<?php

namespace App\Console\Commands\CCD\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Shared helpers for writing dealer_* data into the external `ccd` connection.
 *
 * Type-agnostic: nothing here knows about a specific party kind (person vs
 * company) or source table — it just knows how to create-or-update a row on
 * the ccd connection, fit values to column limits, and wire the standard
 * party associations (category, group, address, contact points, bank
 * accounts). Used by MigratePartiesCommand and ImportStagedPartiesCommand.
 */
trait MigratesToCcd
{
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

    /**
     * Whether a raw value is usable (not empty, not a placeholder, has an
     * alphanumeric core, and — for all-digit ids like a national id/passport —
     * not a degenerate pattern like "1111111111111" or "1234567890123"). These
     * dummy values are common test/placeholder input and are shared by many
     * unrelated real people, so treating them as a real id would collapse
     * unrelated parties onto one row. Doesn't check for cross-party conflicts
     * — that's `MigratePartiesCommand::deconflict()`'s job for the legacy
     * per-reference path.
     */
    private function isUsableValue(?string $value): bool
    {
        $value = trim((string) $value);

        if ($value === '' || in_array(strtoupper($value), self::PLACEHOLDER_TOKENS, true)) {
            return false;
        }

        if (preg_replace('/[^a-z0-9]/i', '', $value) === '') {
            return false;
        }

        return ! (ctype_digit($value) && $this->isDegenerateDigitString($value));
    }

    /**
     * All-same-digit ("1111111111111") or a run of consecutive ascending/
     * descending digits ("1234567890123" / "9876543210987").
     */
    private function isDegenerateDigitString(string $value): bool
    {
        if (preg_match('/^(\d)\1*$/', $value) === 1) {
            return true;
        }

        $ascending  = '01234567890123456789';
        $descending = '98765432109876543210';

        return str_contains($ascending, $value) || str_contains($descending, $value);
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
