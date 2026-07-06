<?php

namespace App\Console\Commands\CCD;

use App\Console\Commands\CCD\Concerns\MigratesToCcd;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Migrate a tenant's transaction graph into the CCD party model.
 *
 * Walks dealer_transactions (filtered by the tenant's country), and for every
 * transaction party rebuilds the CCD-side records:
 *
 *   dealer_transactions.group_id        → ccd_groups
 *   dealer_transaction_parties.role_id  → ccd_party_categories
 *   party (resolved by type + type_id)  → ccd_parties
 *                                         ccd_group_members (role = member)
 *                                         ccd_party_category_party
 *                                         ccd_addresses_raw
 *                                         ccd_contact_points / ccd_contact_point_party
 *   dealer_bank_accounts (of the party) → ccd_bank_accounts
 *
 * Every write is create-or-update, so the command is idempotent and can be
 * re-run, resumed with a larger --limit, or resumed from a crash with --offset
 * (the resume position is logged periodically and on failure).
 */
class MigratePartiesCommand extends Command
{
    use MigratesToCcd;

    /**
     * @var string
     */
    protected $signature = 'ccd:migrate-parties
        {tenant_id : Target tenant id (its country selects the dealer_transactions to migrate)}
        {--limit= : Max number of dealer_transactions to process (all when omitted)}
        {--offset=0 : Skip this many transactions before starting (resume point after a failure)}';

    /**
     * @var string
     */
    protected $description = 'Migrate dealer transaction parties into the CCD party model for a tenant';

    private int $tenantId;

    public function handle(): int
    {
        $this->tenantId = (int) $this->argument('tenant_id');

        // country_id is resolved from the shared tenant => country map in config/ccd.php.
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

        // Remaining rows after the resume offset, capped by --limit for the bar.
        $remaining = max(0, (clone $base)->count() - $offset);
        $total     = $limit !== null ? min($limit, $remaining) : $remaining;

        $query = $base
            ->orderByDesc('id') // newest transactions first — stable order for offset resume
            ->offset($offset);

        if ($limit !== null) {
            $query->limit($limit);
        }

        $this->info("Migrating {$total} transaction(s) for country #{$countryId} → tenant #{$this->tenantId} (offset {$offset})…");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        // $processed is the absolute count from the start of the dataset, so it
        // doubles as the --offset to resume from if this run dies mid-way.
        $processed   = $offset;
        $transaction = null;

        try {
            // Cursor keeps memory flat over large result sets; limit/offset honoured by the query.
            foreach ($query->cursor() as $transaction) {
                DB::connection('ccd')->transaction(function () use ($transaction) {
                    $this->migrateTransaction($transaction);
                });

                $processed++;
                $bar->advance();

                // Checkpoint the resume position so a crash leaves a trail in the log.
                if ($processed % 100 === 0) {
                    Log::info('ccd:migrate-parties checkpoint', [
                        'tenant_id'            => $this->tenantId,
                        'processed'            => $processed,
                        'last_transaction_id'  => $transaction->id,
                        'resume_offset'        => $processed,
                    ]);
                }
            }
        } catch (Throwable $e) {
            $bar->clear();

            $resumeCmd = "php artisan ccd:migrate-parties {$this->tenantId} --offset={$processed}"
                . ($limit !== null ? ' --limit=' . ($limit - ($processed - $offset)) : '');

            Log::error('ccd:migrate-parties failed', [
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

        Log::info('ccd:migrate-parties completed', [
            'tenant_id' => $this->tenantId,
            'processed' => $processed,
            'inserts'   => $this->stats,
        ]);

        $this->info("Done. Processed {$processed} transaction(s). Created/updated:");
        foreach ($this->stats as $table => $count) {
            $this->line("  {$table}: {$count}");
        }

        // Only a bounded run can have more rows waiting; point at the next batch.
        if ($limit !== null && $total === $limit) {
            $this->newLine();
            $this->comment("Next batch: php artisan ccd:migrate-parties {$this->tenantId} --offset={$processed} --limit={$limit}");
        }

        return self::SUCCESS;
    }

    private function migrateTransaction(object $transaction): void
    {
        // dealer_transactions.group_id => ccd_groups (kept for group membership below).
        $ccdGroupId = $transaction->group_id ? $this->resolveGroup((int) $transaction->group_id) : null;

        $parties = DB::table('dealer_transaction_parties')
            ->where('transaction_id', $transaction->id)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get();

        foreach ($parties as $party) {
            $identity = $this->resolveIdentity($party->type, $party->type_id);

            if ($identity === null) {
                continue; // unknown morph type or missing source row — nothing to migrate
            }

            // dealer_transaction_party_roles => ccd_party_categories
            $categoryId = $party->role_id ? $this->resolveCategory((int) $party->role_id) : null;

            $partyId = $this->upsertParty($identity);

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
     * Resolve a transaction party's morph (type + type_id) into a normalised
     * identity using the config/ccd.php party_types map.
     *
     * @return array{ref:string,kind:string,source:string,id:int,row:object}|null
     */
    private function resolveIdentity(?string $type, ?int $typeId): ?array
    {
        if ($type === null || $typeId === null) {
            return null;
        }

        $map = config('ccd.party_types');

        if (! isset($map[$type])) {
            return null;
        }

        $meta = $map[$type];
        $row  = DB::table($meta['source'])->where('id', $typeId)->first();

        if ($row === null) {
            return null;
        }

        return [
            'ref'    => $meta['ref'],
            'kind'   => $meta['kind'],
            'source' => $meta['source'],
            'id'     => $typeId,
            'row'    => $row,
        ];
    }

    /**
     * Create-or-update ccd_parties, unique on (tenant_id, reference_id, reference_name).
     */
    private function upsertParty(array $identity): int
    {
        $row  = $identity['row'];
        $kind = $identity['kind'];

        $name = $row->display_name
            ?? $row->name
            ?? trim(($row->first_name ?? '') . ' ' . ($row->last_name ?? ''));
        $name = $name !== '' ? $name : "{$identity['ref']} #{$identity['id']}";

        $values = [
            'type'    => $kind,
            'is_host' => false,
            'name'    => $name,
        ];

        $keys = [
            'tenant_id'      => $this->tenantId,
            'reference_id'   => (string) $identity['id'],
            'reference_name' => $identity['ref'],
        ];

        if ($kind === 'person') {
            $dob = $row->date_of_birth ?? null;

            $values += [
                'person_first_name'    => $row->first_name ?? null,
                'person_last_name'     => $row->last_name ?? null,
                'person_gender'        => $row->gender ?? null,
                'person_date_of_birth' => $dob ? substr($dob, 0, 10) : null,
                // national_id / passport carry their own unique constraint — drop junk
                // placeholders and any value already claimed by a different party.
                'person_national_id' => $this->deconflict('person_national_id', $row->national_identity_number ?? null, $keys),
            ];
        } else {
            $values += [
                'company_registration_number' => $row->company_registration_number
                    ?? $row->unique_entity_number
                    ?? $row->business_registration_number
                    ?? null,
                'company_tax_id' => $row->tax_identification_number ?? null,
            ];
        }

        return $this->createOrUpdate('parties', $keys, $values);
    }

    /**
     * Guard a party column that carries its own unique constraint (national id,
     * passport). Returns null when the value is a junk placeholder or is already
     * held by a different party in this tenant, otherwise the cleaned value.
     *
     * @param  array<string, mixed>  $keys  reference keys identifying this party
     */
    private function deconflict(string $column, ?string $value, array $keys): ?string
    {
        $value = trim((string) $value);

        // Drop placeholders, degenerate digit patterns, and anything without an alphanumeric core.
        if (! $this->isUsableValue($value)) {
            return null;
        }

        // Truncate to the column limit up front so the uniqueness check below
        // matches the value that will actually be stored.
        $limit = $this->limitsFor('parties')[$column] ?? null;
        if ($limit !== null && mb_strlen($value) > $limit) {
            $value = mb_substr($value, 0, $limit);
        }

        $taken = DB::connection('ccd')->table('parties')
            ->where('tenant_id', $this->tenantId)
            ->where($column, $value)
            ->where(fn ($q) => $q
                ->where('reference_id', '!=', $keys['reference_id'])
                ->orWhere('reference_name', '!=', $keys['reference_name']))
            ->exists();

        return $taken ? null : $value;
    }
}
