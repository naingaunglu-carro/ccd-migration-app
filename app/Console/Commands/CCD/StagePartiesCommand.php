<?php

namespace App\Console\Commands\CCD;

use App\Console\Commands\CCD\Concerns\MigratesToCcd;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stage Contact-type parties into ccd_party_staging ahead of
 * ccd:import-staged-parties, so duplicate real-world people (same national id,
 * or the same OCR-matched passport when no national id exists) can be merged
 * into one ccd_parties row instead of one per dealer_contacts record.
 *
 * Two passes:
 *  1. Per distinct Contact-type dealer_contacts.id for the tenant's country:
 *     resolve an OCR name/passport match (via transaction_file_ocr, matched by
 *     national id or — when the contact has none — by being the sole Contact
 *     party on that transaction), compute an identification_key, upsert.
 *  2. Group staged rows by (tenant_id, identification_key) and mark the most
 *     recently updated dealer_contacts row in each group as canonical — this
 *     runs over the *whole* staging table for the tenant every time, since a
 *     duplicate pair can land in different --limit batches.
 */
class StagePartiesCommand extends Command
{
    use MigratesToCcd;

    /**
     * @var string
     */
    protected $signature = 'ccd:stage-parties
        {tenant_id : Target tenant id (its country selects the dealer_contacts to stage)}
        {--limit= : Max number of distinct contacts to stage (all when omitted)}
        {--offset=0 : Skip this many contacts before starting (resume point after a failure)}';

    /**
     * @var string
     */
    protected $description = 'Stage Contact-type dealer_contacts into ccd_party_staging for merge-by-identification import';

    private const CONTACT_MODEL = 'App\Modules\Contact\Models\Contact';

    private const CHUNK = 500;

    /**
     * A real national id/passport realistically belongs to a handful of
     * dealer_contacts records at most (duplicate registrations by the same
     * person). A group larger than this is far more likely a shared
     * placeholder value (e.g. "1234567891011") that pattern-matching alone
     * didn't catch — quarantine it rather than merge unrelated people.
     */
    private const MAX_GROUP_SIZE = 5;

    private int $tenantId;

    public function handle(): int
    {
        $this->tenantId = (int) $this->argument('tenant_id');

        $countryId = config("ccd.tenant_country.{$this->tenantId}");

        if ($countryId === null) {
            $this->error("No country mapped for tenant #{$this->tenantId}. Add it to config/ccd.php (tenant_country).");

            return self::FAILURE;
        }

        $limit  = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $offset = max(0, (int) $this->option('offset'));

        $occurrenceBase = fn () => DB::table('dealer_transaction_parties as tp')
            ->join('dealer_transactions as t', 't.id', '=', 'tp.transaction_id')
            ->where('tp.type', self::CONTACT_MODEL)
            ->where('t.country_id', $countryId)
            ->whereNull('tp.deleted_at')
            ->whereNull('t.deleted_at');

        $totalAll  = $occurrenceBase()->selectRaw('count(distinct tp.type_id) as cnt')->value('cnt');
        $remaining = max(0, $totalAll - $offset);
        $total     = $limit !== null ? min($limit, $remaining) : $remaining;

        $idsQuery = $occurrenceBase()
            ->groupBy('tp.type_id')
            ->orderByDesc(DB::raw('max(t.created_at)')) // latest transaction first
            ->orderBy('tp.type_id') // stable tie-break
            ->offset($offset);

        if ($limit !== null) {
            $idsQuery->limit($limit);
        }

        $contactIds = $idsQuery->pluck('tp.type_id');

        $this->info("Staging {$total} contact(s) for country #{$countryId} → tenant #{$this->tenantId} (offset {$offset})…");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $processed = $offset;

        try {
            foreach ($contactIds->chunk(self::CHUNK) as $chunk) {
                $this->stageChunk($chunk->all(), $countryId);
                $processed += $chunk->count();
                $bar->advance($chunk->count());
            }
        } catch (Throwable $e) {
            $bar->clear();

            Log::error('ccd:stage-parties failed', [
                'tenant_id' => $this->tenantId,
                'processed' => $processed,
                'error'     => $e->getMessage(),
            ]);

            $this->error("Failed after {$processed} contact(s): {$e->getMessage()}");
            $this->warn("Resume with: php artisan ccd:stage-parties {$this->tenantId} --offset={$processed}"
                . ($limit !== null ? ' --limit=' . ($limit - ($processed - $offset)) : ''));

            return self::FAILURE;
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('Resolving canonical duplicates…');
        [$groups, $quarantined] = $this->markCanonical($this->tenantId);

        $this->info("Done. Staged {$processed} contact(s), {$groups} identification group(s) resolved"
            . ($quarantined > 0 ? ", {$quarantined} quarantined (>" . self::MAX_GROUP_SIZE . ' contacts sharing one id — likely a placeholder value)' : '') . '.');

        if ($limit !== null && $total === $limit) {
            $this->newLine();
            $this->comment("Next batch: php artisan ccd:stage-parties {$this->tenantId} --offset={$processed} --limit={$limit}");
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<int>  $contactIds
     */
    private function stageChunk(array $contactIds, int $countryId): void
    {
        if ($contactIds === []) {
            return;
        }

        $contacts = DB::table('dealer_contacts')->whereIn('id', $contactIds)->get()->keyBy('id');

        $occurrences = DB::table('dealer_transaction_parties as tp')
            ->join('dealer_transactions as t', 't.id', '=', 'tp.transaction_id')
            ->where('tp.type', self::CONTACT_MODEL)
            ->where('t.country_id', $countryId)
            ->whereNull('tp.deleted_at')
            ->whereNull('t.deleted_at')
            ->whereIn('tp.type_id', $contactIds)
            ->select('tp.type_id', 'tp.transaction_id')
            ->get();

        $transactionIdsByContact = $occurrences->groupBy('type_id')
            ->map(fn ($rows) => $rows->pluck('transaction_id')->unique()->values());

        $allTransactionIds = $occurrences->pluck('transaction_id')->unique()->values();

        $partyCounts = DB::table('dealer_transaction_parties')
            ->where('type', self::CONTACT_MODEL)
            ->whereNull('deleted_at')
            ->whereIn('transaction_id', $allTransactionIds)
            ->select('transaction_id', DB::raw('count(*) as cnt'))
            ->groupBy('transaction_id')
            ->pluck('cnt', 'transaction_id');

        $ocrByTransaction = DB::table('transaction_file_ocr')
            ->where('ocr_status', 'done')
            ->whereIn('transaction_id', $allTransactionIds)
            ->get()
            ->groupBy('transaction_id');

        $now  = now();
        $rows = [];

        foreach ($contactIds as $contactId) {
            $contact = $contacts->get($contactId);

            if ($contact === null) {
                continue;
            }

            $nationalId = $this->cleanIdentifier($contact->national_identity_number ?? null);
            $ocrMatch   = $this->matchOcr(
                $transactionIdsByContact->get($contactId, collect()),
                $ocrByTransaction,
                $partyCounts,
                $nationalId,
            );

            $name = $this->cleanIdentifier($ocrMatch->ocr_name ?? null)
                ?? $contact->display_name
                ?? $contact->name
                ?? trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? ''));
            $name = filled($name) ? $name : "contact #{$contactId}";

            $passport = $this->cleanIdentifier($ocrMatch->ocr_person_passport_number ?? null);

            [$identificationKey, $identificationColumn] = match (true) {
                $nationalId !== null => [$nationalId, 'person_national_id'],
                $passport !== null   => [$passport, 'person_passport_number'],
                default              => [null, null],
            };

            $rows[] = [
                'tenant_id'              => $this->tenantId,
                'reference_id'           => $contactId,
                'reference_name'         => 'contact',
                'name'                   => $name,
                'person_first_name'      => $contact->first_name ?? null,
                'person_last_name'       => $contact->last_name ?? null,
                'person_gender'          => $contact->gender ?? null,
                'person_date_of_birth'   => isset($contact->date_of_birth) ? substr($contact->date_of_birth, 0, 10) : null,
                'person_national_id'     => $nationalId,
                'person_passport_number' => $passport,
                'identification_key'     => $identificationKey,
                'identification_column'  => $identificationColumn,
                'source_updated_at'      => $contact->updated_at ?? $contact->created_at ?? null,
                'updated_at'             => $now,
                'created_at'             => $now,
            ];
        }

        if ($rows === []) {
            return;
        }

        DB::table('ccd_party_staging')->upsert(
            $rows,
            ['tenant_id', 'reference_id'],
            [
                'name', 'person_first_name', 'person_last_name', 'person_gender', 'person_date_of_birth',
                'person_national_id', 'person_passport_number', 'identification_key', 'identification_column',
                'source_updated_at', 'updated_at',
            ],
        );
    }

    /**
     * Find the OCR row (if any) that belongs to this contact: matched by
     * national id/passport when the contact has a national id, otherwise
     * accepted unconditionally only when the transaction has exactly one
     * Contact party (so there's no ambiguity about who the document belongs to).
     *
     * @param  Collection<int, int>  $transactionIds
     * @param  Collection<int|string, Collection<int, object>>  $ocrByTransaction
     * @param  Collection<int|string, int>  $partyCounts
     */
    private function matchOcr($transactionIds, $ocrByTransaction, $partyCounts, ?string $nationalId): ?object
    {
        foreach ($transactionIds as $transactionId) {
            foreach ($ocrByTransaction->get($transactionId, collect()) as $candidate) {
                $idMatches = $nationalId !== null
                    ? ($candidate->ocr_person_national_id === $nationalId || $candidate->ocr_person_passport_number === $nationalId)
                    : (($partyCounts[$transactionId] ?? 0) === 1);

                if ($idMatches && (filled($candidate->ocr_name) || filled($candidate->ocr_person_passport_number))) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * Trim + reject placeholder/junk values (shared with the trait's contact
     * cleaning), returning null instead of an empty/junk string.
     */
    private function cleanIdentifier(?string $value): ?string
    {
        $value = trim((string) $value);

        return $this->isUsableValue($value) ? $value : null;
    }

    /**
     * Mark the most-recently-updated contact in each (tenant_id, identification_key)
     * group as canonical_reference_id for every row in that group. Runs over the
     * whole staging table for the tenant, since duplicates can span --limit batches.
     *
     * Groups larger than MAX_GROUP_SIZE are quarantined instead: their
     * identification_key/identification_column are cleared (so
     * ccd:import-staged-parties falls back to the legacy per-reference key for
     * every member) rather than merging what's almost certainly unrelated
     * people sharing a placeholder id.
     *
     * @return array{0: int, 1: int} [groups resolved, groups quarantined]
     */
    private function markCanonical(int $tenantId): array
    {
        $groupSizes = DB::table('ccd_party_staging')
            ->where('tenant_id', $tenantId)
            ->whereNotNull('identification_key')
            ->select('identification_key', DB::raw('count(*) as cnt'))
            ->groupBy('identification_key')
            ->pluck('cnt', 'identification_key');

        $resolved    = 0;
        $quarantined = 0;

        foreach ($groupSizes as $key => $size) {
            if ($size > self::MAX_GROUP_SIZE) {
                DB::table('ccd_party_staging')
                    ->where('tenant_id', $tenantId)
                    ->where('identification_key', $key)
                    ->update(['identification_key' => null, 'identification_column' => null, 'updated_at' => now()]);

                $quarantined++;

                continue;
            }

            $winner = DB::table('ccd_party_staging')
                ->where('tenant_id', $tenantId)
                ->where('identification_key', $key)
                ->orderByDesc('source_updated_at')
                ->orderByDesc('reference_id') // stable tie-break when updated_at ties/nulls
                ->value('reference_id');

            if ($winner !== null) {
                DB::table('ccd_party_staging')
                    ->where('tenant_id', $tenantId)
                    ->where('identification_key', $key)
                    ->update(['canonical_reference_id' => $winner, 'updated_at' => now()]);

                $resolved++;
            }
        }

        return [$resolved, $quarantined];
    }
}
