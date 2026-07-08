<?php

namespace App\Console\Commands\CCD_V2;

use App\Console\Commands\CCD\Concerns\MigratesToCcd;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Migrate stg_two_parties (merged, doubly-verified Contact identities) into
 * the CCD party model for a tenant, with the same associations as
 * ccd:migrate-parties (category, group membership, address, contact points,
 * bank accounts).
 *
 * Structurally the same transaction-walking loop as ccd:migrate-parties
 * (resumable via --offset, progress bar, checkpoint logging), scoped to
 * App\Modules\Contact\Models\Contact parties only. The difference is party
 * resolution: a reverse index (dealer_contacts.id => stg_two_parties row) is
 * built once up front from every merged_reference_ids list ("|"-separated).
 * A transaction-party occurrence whose reference_id is in that index
 * resolves to ONE shared ccd_parties row per stg_two_parties.id — keyed on
 * (tenant_id, person_national_id) or (tenant_id, person_passport_number),
 * written with the merged party's canonical name/person fields — instead of
 * one party per dealer_contacts record. Associations (address, contact
 * points, bank accounts) are still attached per-occurrence from that
 * occurrence's own dealer_contacts row, all pointed at the one shared
 * party_id — that's what "merges" the records, same as ccd:migrate-parties.
 *
 * Contacts with no stg_two_parties match (not yet staged, or staged but
 * never doubly-verified — see ccd:stage-two-parties) are skipped entirely:
 * no party, no associations. Run ccd:stage-one-parties then
 * ccd:stage-two-parties first.
 */
class MigrateTwoPartiesCommand extends Command
{
    use MigratesToCcd;

    /**
     * @var string
     */
    protected $signature = 'ccd:migrate-two-parties
        {tenant_id : Target tenant id (its country selects the dealer_transactions to migrate)}
        {--country= : Only dealer_transactions for this dealer_countries.id or .country_code (default: tenant\'s mapped country)}
        {--limit= : Max number of dealer_transactions to process (all when omitted)}
        {--offset=0 : Skip this many transactions before starting (resume point after a failure)}';

    /**
     * @var string
     */
    protected $description = 'Migrate merged stg_two_parties Contact identities into the CCD party model for a tenant';

    private const CONTACT_MODEL = 'App\Modules\Contact\Models\Contact';

    private const CONTACT_SOURCE = 'dealer_contacts';

    private const CONTACT_REF = 'contact';

    private int $tenantId;

    /** dealer_contacts.id => stg_two_parties row, built once per run */
    private array $referenceIndex = [];

    public function handle(): int
    {
        $this->tenantId = (int) $this->argument('tenant_id');

        $countryOpt = $this->option('country') !== null ? trim((string) $this->option('country')) : null;

        if ($countryOpt !== null) {
            // Accept either a numeric dealer_countries.id or a country_code like "MY".
            $country = ctype_digit($countryOpt)
                ? DB::table('dealer_countries')->where('id', (int) $countryOpt)->first()
                : DB::table('dealer_countries')->where('country_code', strtoupper($countryOpt))->first();

            if ($country === null) {
                $this->error("Unknown dealer_countries entry: {$countryOpt}");

                return self::FAILURE;
            }

            $countryId = $country->id;
        } else {
            $countryId = config("ccd.tenant_country.{$this->tenantId}");

            if ($countryId === null) {
                $this->error("No country mapped for tenant #{$this->tenantId}. Add it to config/ccd.php (tenant_country), or pass --country=.");

                return self::FAILURE;
            }
        }

        $this->info("Indexing stg_two_parties for country #{$countryId}…");
        $this->buildReferenceIndex((int) $countryId);

        if ($this->referenceIndex === []) {
            $this->error("No stg_two_parties rows found for country #{$countryId}. Run ccd:stage-one-parties then ccd:stage-two-parties first.");

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

        $this->info("Migrating {$total} transaction(s), country #{$countryId} → tenant #{$this->tenantId} (offset {$offset})…");

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
                    Log::info('ccd:migrate-two-parties checkpoint', [
                        'tenant_id'           => $this->tenantId,
                        'processed'           => $processed,
                        'last_transaction_id' => $transaction->id,
                        'resume_offset'       => $processed,
                    ]);
                }
            }
        } catch (Throwable $e) {
            $bar->clear();

            $resumeCmd = "php artisan ccd:migrate-two-parties {$this->tenantId} --offset={$processed} --country={$countryId}"
                . ($limit !== null ? ' --limit=' . ($limit - ($processed - $offset)) : '');

            Log::error('ccd:migrate-two-parties failed', [
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

        Log::info('ccd:migrate-two-parties completed', [
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
            $this->comment("Next batch: php artisan ccd:migrate-two-parties {$this->tenantId} --offset={$processed} --limit={$limit} --country={$countryId}");
        }

        return self::SUCCESS;
    }

    /**
     * Build the dealer_contacts.id => stg_two_parties row reverse index by
     * exploding every row's "|"-separated merged_reference_ids.
     */
    private function buildReferenceIndex(int $countryId): void
    {
        DB::table('stg_two_parties')
            ->where('country_id', $countryId)
            ->orderBy('id')
            ->select([
                'id', 'merged_reference_ids', 'name', 'person_first_name', 'person_last_name',
                'person_gender', 'person_date_of_birth', 'person_national_id', 'person_passport_number',
            ])
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    foreach (explode('|', (string) $row->merged_reference_ids) as $referenceId) {
                        if ($referenceId !== '') {
                            $this->referenceIndex[(int) $referenceId] = $row;
                        }
                    }
                }
            });
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
            $merged = $this->referenceIndex[(int) $party->type_id] ?? null;

            if ($merged === null) {
                continue; // not merged into stg_two_parties — not migrated
            }

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

            $partyId = $this->resolvePartyId($merged);

            if ($categoryId !== null) {
                $this->upsertPartyCategoryParty($partyId, $categoryId);
            }

            if ($ccdGroupId !== null) {
                $this->upsertGroupMember($ccdGroupId, $partyId);
            }

            $this->upsertAddressRaw($identity, $partyId);
            $this->upsertVerifiedContactPoints($identity, $partyId);
            $this->upsertBankAccounts($identity, $partyId);
        }
    }

    /**
     * Create-or-update ccd_contact_points (email/phone) and link them to the
     * party — but only when the value passes validContact() below. Invalid
     * values (malformed email, too-short/degenerate "phone") are silently
     * ignored rather than attached, so junk data doesn't create a shared
     * contact point that links unrelated merged parties together.
     */
    private function upsertVerifiedContactPoints(array $identity, int $partyId): void
    {
        $row = $identity['row'];

        $channels = [
            'email' => $row->email ?? null,
            'phone' => $row->phone ?? $row->contact_number ?? null,
        ];

        foreach ($channels as $channel => $value) {
            $value = $this->validContact($channel, $value);

            if ($value === null) {
                continue;
            }

            $contactPointId = $this->createOrUpdate('contact_points',
                ['tenant_id' => $this->tenantId, 'channel' => $channel, 'value' => $value],
                [],
            );

            $this->createOrUpdate('contact_point_party',
                ['contact_point_id' => $contactPointId, 'party_id' => $partyId],
                ['tenant_id' => $this->tenantId],
            );
        }
    }

    /**
     * Validate a contact value, returning the cleaned value or null when it
     * should be ignored:
     *   - email: must pass filter_var(FILTER_VALIDATE_EMAIL), and its local
     *     part (before the @) can't be all-digits or a known placeholder
     *     token (e.g. "noemail@gmail.com", "123@gmail.com").
     *   - phone: digits-only length must be 8-15 (a plausible real number,
     *     tighter than a bare minimum), and can't be a degenerate pattern
     *     (all-same-digit like "0000000000", or a sequential run).
     */
    private function validContact(string $channel, ?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if ($channel === 'email') {
            if (! filter_var($value, FILTER_VALIDATE_EMAIL)) {
                return null;
            }

            $local = strtoupper(explode('@', $value, 2)[0]);

            return ($this->isUsableValue($local) && ! ctype_digit($local)) ? strtolower($value) : null;
        }

        $digits = preg_replace('/\D/', '', $value);

        if (strlen($digits) < 8 || strlen($digits) > 15) {
            return null;
        }

        return $this->isDegenerateDigitString($digits) ? null : $value;
    }

    /**
     * Create-or-update ccd_parties for a merged stg_two_parties identity,
     * keyed on whichever of national id / passport number it carries — every
     * occurrence resolving to the same stg_two_parties row lands on this same
     * ccd_parties row, using the merged party's canonical field values.
     */
    private function resolvePartyId(object $merged): int
    {
        $keys = ['tenant_id' => $this->tenantId];

        if (filled($merged->person_national_id)) {
            $keys['person_national_id'] = $merged->person_national_id;
        } else {
            // stg_two_parties' unique indexes guarantee one of these is set
            // for any row reachable via the reference index.
            $keys['person_passport_number'] = $merged->person_passport_number;
        }

        $values = [
            'type'                   => 'person',
            'is_host'                => false,
            'name'                   => $merged->name,
            'person_first_name'      => $merged->person_first_name,
            'person_last_name'       => $merged->person_last_name,
            'person_gender'          => $merged->person_gender,
            'person_date_of_birth'   => $merged->person_date_of_birth,
            'person_national_id'     => $merged->person_national_id,
            'person_passport_number' => $merged->person_passport_number,
        ];

        return $this->createOrUpdate('parties', $keys, $values);
    }
}
