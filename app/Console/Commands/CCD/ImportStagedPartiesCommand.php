<?php

namespace App\Console\Commands\CCD;

use App\Console\Commands\CCD\Concerns\MigratesToCcd;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Import Contact-type parties into the CCD party model, merged by
 * identification number using ccd_party_staging (populated by ccd:stage-parties).
 *
 * Structurally the same transaction-walking loop as ccd:migrate-parties
 * (resumable via --offset, progress bar, checkpoint logging), scoped to
 * App\Modules\Contact\Models\Contact parties only. The difference is party
 * resolution: when a Contact's staged row has an identification_key
 * (national id, or an OCR-matched passport when no national id exists),
 * every occurrence sharing that key resolves to the same ccd_parties row —
 * using the canonical staged contact's field values — instead of creating one
 * party per dealer_contacts record. Contacts with no identification key at
 * all fall back to the legacy per-reference key
 * (tenant_id, reference_id, reference_name).
 *
 * The five association upserts (category, group, address, contact points,
 * bank accounts) are unchanged from ccd:migrate-parties — they're what
 * "merges" multiple source records: each keeps writing its own independently
 * keyed rows, just pointed at one shared party_id.
 */
class ImportStagedPartiesCommand extends Command
{
    use MigratesToCcd;

    /**
     * @var string
     */
    protected $signature = 'ccd:import-staged-parties
        {tenant_id : Target tenant id (its country selects the dealer_transactions to migrate)}
        {--limit= : Max number of dealer_transactions to process (all when omitted)}
        {--offset=0 : Skip this many transactions before starting (resume point after a failure)}';

    /**
     * @var string
     */
    protected $description = 'Import staged Contact parties (merged by national id/passport) into the CCD party model for a tenant';

    private const CONTACT_MODEL = 'App\Modules\Contact\Models\Contact';

    private const CONTACT_SOURCE = 'dealer_contacts';

    private const CONTACT_REF = 'contact';

    private int $tenantId;

    /** reference_id => ccd_party_staging row, or null when not staged */
    private array $stagingCache = [];

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

        $base = DB::table('dealer_transactions')
            ->where('country_id', $countryId)
            ->whereNull('deleted_at');

        $remaining = max(0, (clone $base)->count() - $offset);
        $total     = $limit !== null ? min($limit, $remaining) : $remaining;

        $query = $base
            ->orderByDesc('id')
            ->offset($offset);

        if ($limit !== null) {
            $query->limit($limit);
        }

        $this->info("Importing staged parties for {$total} transaction(s), country #{$countryId} → tenant #{$this->tenantId} (offset {$offset})…");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $processed   = $offset;
        $transaction = null;

        try {
            foreach ($query->cursor() as $transaction) {
                DB::connection('ccd')->transaction(function () use ($transaction) {
                    $this->migrateTransaction($transaction);
                });

                $processed++;
                $bar->advance();

                if ($processed % 100 === 0) {
                    Log::info('ccd:import-staged-parties checkpoint', [
                        'tenant_id'           => $this->tenantId,
                        'processed'           => $processed,
                        'last_transaction_id' => $transaction->id,
                        'resume_offset'       => $processed,
                    ]);
                }
            }
        } catch (Throwable $e) {
            $bar->clear();

            $resumeCmd = "php artisan ccd:import-staged-parties {$this->tenantId} --offset={$processed}"
                . ($limit !== null ? ' --limit=' . ($limit - ($processed - $offset)) : '');

            Log::error('ccd:import-staged-parties failed', [
                'tenant_id'      => $this->tenantId,
                'resume_offset'  => $processed,
                'transaction_id' => $transaction->id ?? null,
                'error'          => $e->getMessage(),
            ]);

            $this->error("Failed after {$processed} transaction(s)"
                . (isset($transaction->id) ? " (at dealer_transactions #{$transaction->id})" : '')
                . ': ' . $e->getMessage());
            $this->warn("Resume with: {$resumeCmd}");

            return self::FAILURE;
        }

        $bar->finish();
        $this->newLine(2);

        Log::info('ccd:import-staged-parties completed', [
            'tenant_id' => $this->tenantId,
            'processed' => $processed,
            'inserts'   => $this->stats,
        ]);

        $this->info("Done. Processed {$processed} transaction(s). Created/updated:");
        foreach ($this->stats as $table => $count) {
            $this->line("  {$table}: {$count}");
        }

        if ($limit !== null && $total === $limit) {
            $this->newLine();
            $this->comment("Next batch: php artisan ccd:import-staged-parties {$this->tenantId} --offset={$processed} --limit={$limit}");
        }

        return self::SUCCESS;
    }

    private function migrateTransaction(object $transaction): void
    {
        $ccdGroupId = $transaction->group_id ? $this->resolveGroup((int) $transaction->group_id) : null;

        $parties = DB::table('dealer_transaction_parties')
            ->where('transaction_id', $transaction->id)
            ->where('type', self::CONTACT_MODEL)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get();

        foreach ($parties as $party) {
            $row = DB::table(self::CONTACT_SOURCE)->where('id', $party->type_id)->first();

            if ($row === null) {
                continue; // dangling reference — contact not (yet) synced
            }

            $identity = [
                'ref'    => self::CONTACT_REF,
                'kind'   => 'person',
                'source' => self::CONTACT_SOURCE,
                'id'     => (int) $party->type_id,
                'row'    => $row,
            ];

            $categoryId = $party->role_id ? $this->resolveCategory((int) $party->role_id) : null;

            $partyId = $this->resolvePartyId($identity);

            if ($categoryId !== null) {
                $this->upsertPartyCategoryParty($partyId, $categoryId);
            }

            if ($ccdGroupId !== null) {
                $this->upsertGroupMember($ccdGroupId, $partyId);
            }

            $this->upsertAddressRaw($identity, $partyId);
            $this->upsertContactPoints($identity, $partyId);
            $this->upsertBankAccounts($identity, $partyId);
        }
    }

    /**
     * Resolve (and merge) the ccd_parties id for this Contact occurrence.
     *
     * When staged with an identification_key, every occurrence sharing that
     * key — regardless of its own reference_id — resolves to the same
     * party_id, keyed on (tenant_id, identification_column) and written with
     * the canonical staged contact's field values. Falls back to the legacy
     * per-reference key when the contact has no identification key at all
     * (not staged, or staged with neither a national id nor an OCR passport match).
     */
    private function resolvePartyId(array $identity): int
    {
        $staged = $this->stagingFor((int) $identity['id']);

        if ($staged !== null && $staged->identification_key !== null) {
            $canonical = $this->stagingFor((int) $staged->canonical_reference_id) ?? $staged;

            $keys = [
                'tenant_id'                       => $this->tenantId,
                $canonical->identification_column => $canonical->identification_key,
            ];

            $values = [
                'type'                   => 'person',
                'is_host'                => false,
                'name'                   => $canonical->name,
                'person_first_name'      => $canonical->person_first_name,
                'person_last_name'       => $canonical->person_last_name,
                'person_gender'          => $canonical->person_gender,
                'person_date_of_birth'   => $canonical->person_date_of_birth,
                'person_national_id'     => $canonical->person_national_id,
                'person_passport_number' => $canonical->person_passport_number,
            ];

            return $this->createOrUpdate('parties', $keys, $values);
        }

        return $this->upsertPartyLegacy($identity);
    }

    /**
     * Legacy per-reference upsert (unique on tenant_id, reference_id,
     * reference_name) for Contacts with no usable identification number.
     */
    private function upsertPartyLegacy(array $identity): int
    {
        $row = $identity['row'];

        $name = $row->display_name
            ?? $row->name
            ?? trim(($row->first_name ?? '') . ' ' . ($row->last_name ?? ''));
        $name = $name !== '' ? $name : "{$identity['ref']} #{$identity['id']}";

        $keys = [
            'tenant_id'      => $this->tenantId,
            'reference_id'   => (string) $identity['id'],
            'reference_name' => $identity['ref'],
        ];

        $values = [
            'type'                 => 'person',
            'is_host'              => false,
            'name'                 => $name,
            'person_first_name'    => $row->first_name ?? null,
            'person_last_name'     => $row->last_name ?? null,
            'person_gender'        => $row->gender ?? null,
            'person_date_of_birth' => isset($row->date_of_birth) ? substr($row->date_of_birth, 0, 10) : null,
        ];

        return $this->createOrUpdate('parties', $keys, $values);
    }

    private function stagingFor(int $referenceId): ?object
    {
        if (array_key_exists($referenceId, $this->stagingCache)) {
            return $this->stagingCache[$referenceId];
        }

        $row = DB::table('ccd_party_staging')
            ->where('tenant_id', $this->tenantId)
            ->where('reference_id', $referenceId)
            ->first();

        return $this->stagingCache[$referenceId] = $row;
    }
}
