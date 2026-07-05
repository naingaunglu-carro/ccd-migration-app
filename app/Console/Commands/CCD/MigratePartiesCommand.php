<?php

namespace App\Console\Commands\CCD;

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

    /** dealer group_id => ccd_groups.id */
    private array $groupCache = [];

    /** dealer role_id => ccd_party_categories.id */
    private array $categoryCache = [];

    /** dealer bank_id => ccd_banks.id (or null when no match) */
    private array $bankCache = [];

    /** ccd table => [column => varchar length]; filled lazily from the schema */
    private array $columnLimits = [];

    /** @var array<string, int> running counters */
    private array $stats = [];

    /**
     * Tokens that signal placeholder / junk free-text data rather than a real
     * value. Used to reject fake national ids and fake contact points (both of
     * which would otherwise collapse many unrelated parties onto one shared row).
     */
    private const PLACEHOLDER_TOKENS = [
        'N/A', 'NA', 'NIL', 'NULL', 'NONE', 'NO', 'NOEMAIL', 'NO-EMAIL', 'NOMAIL',
        'TEST', 'TESTING', 'ABC', 'ABC123', 'XXX', 'XXXX', 'ASDF', 'QWERTY',
        'EXAMPLE', 'DUMMY', 'UNKNOWN', 'TBA', 'TBD', '0', '-',
    ];

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
     * Create-or-update ccd_groups from dealer_groups. ccd_groups has no natural
     * unique key, so it is deduped on (tenant_id, name).
     */
    private function resolveGroup(int $dealerGroupId): ?int
    {
        if (array_key_exists($dealerGroupId, $this->groupCache)) {
            return $this->groupCache[$dealerGroupId];
        }

        $group = DB::table('dealer_groups')->where('id', $dealerGroupId)->first();

        if ($group === null) {
            return $this->groupCache[$dealerGroupId] = null;
        }

        $name = $group->display_name ?: $group->name ?: "Group #{$dealerGroupId}";

        $id = $this->createOrUpdate('groups',
            ['tenant_id' => $this->tenantId, 'name' => $name],
            ['type'      => 'group'],
        );

        return $this->groupCache[$dealerGroupId] = $id;
    }

    /**
     * Create-or-update ccd_party_categories from dealer_transaction_party_roles,
     * deduped on (tenant_id, slug).
     */
    private function resolveCategory(int $roleId): ?int
    {
        if (array_key_exists($roleId, $this->categoryCache)) {
            return $this->categoryCache[$roleId];
        }

        $role = DB::table('dealer_transaction_party_roles')->where('id', $roleId)->first();

        if ($role === null) {
            return $this->categoryCache[$roleId] = null;
        }

        $id = $this->createOrUpdate('party_categories',
            ['tenant_id' => $this->tenantId, 'slug' => $role->name],
            ['name'      => $role->display_name ?: $role->name, 'is_active' => true],
        );

        return $this->categoryCache[$roleId] = $id;
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

        // Drop placeholders and anything without an alphanumeric core.
        if ($value === '' || in_array(strtoupper($value), self::PLACEHOLDER_TOKENS, true) || preg_replace('/[^a-z0-9]/i', '', $value) === '') {
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

    private function upsertPartyCategoryParty(int $partyId, int $categoryId): void
    {
        $this->createOrUpdate('party_category_party',
            ['party_id'  => $partyId, 'party_category_id' => $categoryId],
            ['tenant_id' => $this->tenantId],
        );
    }

    private function upsertGroupMember(int $groupId, int $partyId): void
    {
        $this->createOrUpdate('group_members',
            ['group_id'  => $groupId, 'party_id' => $partyId],
            ['tenant_id' => $this->tenantId, 'role' => 'member'],
        );
    }

    /**
     * Create-or-update ccd_addresses_raw from the party's source row,
     * unique on (tenant_id, source, source_id).
     */
    private function upsertAddressRaw(array $identity, int $partyId): void
    {
        $row = $identity['row'];

        $line1    = $row->address ?? null;
        $line2    = $row->address_2 ?? null;
        $postcode = $row->postcode ?? $row->postal_code ?? null;

        // Nothing addressable — skip so we don't create empty raw rows.
        if (! filled($line1) && ! filled($line2) && ! filled($postcode)) {
            return;
        }

        // raw_full is text (unbounded), so it keeps the complete address even
        // when the varchar line/postcode columns get truncated to fit.
        $rawFull = trim(implode(', ', array_filter([$line1, $line2, $postcode])));

        $this->createOrUpdate('addresses_raw',
            [
                'tenant_id' => $this->tenantId,
                'source'    => $identity['ref'],
                'source_id' => (string) $identity['id'],
            ],
            [
                'raw_full'     => $rawFull !== '' ? $rawFull : null,
                'raw_line_1'   => $line1,
                'raw_line_2'   => $line2,
                'raw_postcode' => $postcode,
                'raw_country'  => isset($row->country_id) ? (string) $row->country_id : null,
                'party_id'     => $partyId,
                'parse_status' => 'pending',
            ],
        );
    }

    /**
     * Create-or-update ccd_contact_points (email/phone) and link them to the party.
     */
    private function upsertContactPoints(array $identity, int $partyId): void
    {
        $row = $identity['row'];

        $channels = [
            'email' => $row->email ?? null,
            'phone' => $row->phone ?? $row->contact_number ?? null,
        ];

        foreach ($channels as $channel => $value) {
            $value = $this->cleanContact($channel, $value);

            if ($value === null) {
                continue; // empty or placeholder — don't create a shared junk contact point
            }

            $contactPointId = $this->createOrUpdate('contact_points',
                ['tenant_id' => $this->tenantId, 'channel' => $channel, 'value' => $value],
                [],
            );

            $this->createOrUpdate('contact_point_party',
                ['contact_point_id' => $contactPointId, 'party_id' => $partyId],
                ['tenant_id'        => $this->tenantId],
            );
        }
    }

    /**
     * Normalise a contact value and reject obvious junk so we don't create a
     * shared placeholder contact point that links unrelated parties together
     * (e.g. noemail@gmail.com, 123@gmail.com, or a 3-digit "phone").
     *
     * Returns the cleaned value, or null when it should be skipped.
     */
    private function cleanContact(string $channel, ?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if ($channel === 'email') {
            // Must be a syntactically valid address…
            if (! filter_var($value, FILTER_VALIDATE_EMAIL)) {
                return null;
            }

            // …with a local part that isn't a placeholder or all digits.
            $local = strtoupper(explode('@', $value, 2)[0]);
            if (ctype_digit($local) || in_array($local, self::PLACEHOLDER_TOKENS, true)) {
                return null;
            }

            return strtolower($value);
        }

        // phone: keep original formatting but require a plausible number of digits.
        return strlen(preg_replace('/\D/', '', $value)) >= 7 ? $value : null;
    }

    /**
     * Create-or-update ccd_bank_accounts from dealer_bank_accounts held by the party,
     * unique on (tenant_id, bank_id, account_number).
     */
    private function upsertBankAccounts(array $identity, int $partyId): void
    {
        $accounts = DB::table('dealer_bank_accounts')
            ->where('account_holder_type', $this->morphFor($identity['ref']))
            ->where('account_holder_id', (string) $identity['id'])
            ->whereNull('deleted_at')
            ->get();

        foreach ($accounts as $account) {
            if (! filled($account->account_no)) {
                continue;
            }

            $bankId = $this->resolveBank($account->bank_id ? (int) $account->bank_id : null);

            if ($bankId === null) {
                continue; // ccd_bank_accounts.bank_id is NOT NULL — skip unmatched banks
            }

            $this->createOrUpdate('bank_accounts',
                [
                    'tenant_id'      => $this->tenantId,
                    'bank_id'        => $bankId,
                    'account_number' => $account->account_no,
                ],
                [
                    'party_id'     => $partyId,
                    'account_name' => $account->account_holder_name ?: 'Unknown',
                ],
            );
        }
    }

    /**
     * Map dealer_banks.id => ccd_banks.id via the bank's display_name
     * (mirrors ccd:migrate-banks, which upserts ccd_banks on tenant_id + name).
     */
    private function resolveBank(?int $dealerBankId): ?int
    {
        if ($dealerBankId === null) {
            return null;
        }

        if (array_key_exists($dealerBankId, $this->bankCache)) {
            return $this->bankCache[$dealerBankId];
        }

        $name = DB::table('dealer_banks')->where('id', $dealerBankId)->value('display_name');

        $id = $name
            ? DB::connection('ccd')->table('banks')
                ->where('tenant_id', $this->tenantId)
                ->where('name', $name)
                ->value('id')
            : null;

        return $this->bankCache[$dealerBankId] = $id ? (int) $id : null;
    }

    /**
     * The morph class stored in dealer_bank_accounts.account_holder_type for a ref.
     */
    private function morphFor(string $ref): ?string
    {
        foreach (config('ccd.party_types') as $class => $meta) {
            if ($meta['ref'] === $ref) {
                return $class;
            }
        }

        return null;
    }

    /**
     * Create-or-update a row on the ccd connection and return its id.
     *
     * @param  array<string, mixed>  $keys  columns that identify the row
     * @param  array<string, mixed>  $values  columns to write on insert and update
     */
    private function createOrUpdate(string $table, array $keys, array $values): int
    {
        $connection = DB::connection('ccd');

        // Fit every string to its column length so oversized dealer data (long
        // addresses, names, ids) can't blow up on a varchar limit. Keys are fit
        // too, so the lookup and the insert agree on the stored value.
        $keys   = $this->fitColumns($table, $keys);
        $values = $this->fitColumns($table, $values);

        $existingId = $connection->table($table)->where($keys)->value('id');

        if ($existingId !== null) {
            if ($values !== []) {
                $connection->table($table)
                    ->where('id', $existingId)
                    ->update($values + ['updated_at' => now()]);
            }

            return (int) $existingId;
        }

        $id = (int) $connection->table($table)->insertGetId(
            $keys + $values + ['created_at' => now(), 'updated_at' => now()]
        );

        $this->stats[$table] = ($this->stats[$table] ?? 0) + 1;

        return $id;
    }

    /**
     * Truncate string values to their column's varchar length for the given
     * ccd table. Non-string values and columns without a length are untouched.
     *
     * @param  array<string, mixed>  $data
     *
     * @return array<string, mixed>
     */
    private function fitColumns(string $table, array $data): array
    {
        $limits = $this->limitsFor($table);

        foreach ($data as $column => $value) {
            if (is_string($value) && isset($limits[$column]) && mb_strlen($value) > $limits[$column]) {
                $data[$column] = mb_substr($value, 0, $limits[$column]);
            }
        }

        return $data;
    }

    /**
     * varchar length per column for a ccd table, read once from the schema.
     *
     * @return array<string, int>
     */
    private function limitsFor(string $table): array
    {
        if (isset($this->columnLimits[$table])) {
            return $this->columnLimits[$table];
        }

        $rows = DB::connection('ccd')->select(
            "select column_name, character_maximum_length as len
               from information_schema.columns
              where table_schema = 'public' and table_name = ? and data_type = 'character varying'",
            ['ccd_' . $table], // the ccd connection is prefixed with ccd_
        );

        $map = [];
        foreach ($rows as $row) {
            if ($row->len !== null) {
                $map[$row->column_name] = (int) $row->len;
            }
        }

        return $this->columnLimits[$table] = $map;
    }
}
