<?php

namespace App\Console\Commands\Concerns;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Shared helpers for the ccd:* migration commands: tenant→country resolution,
 * the ccd connection, party morph-type lookups, and resolving a source record to
 * its already-migrated ccd_parties / ccd_banks / ccd_groups id.
 */
trait MigratesCcd
{
    protected function countryForTenant(int $tenantId): ?int
    {
        return config('ccd.tenant_country')[$tenantId] ?? null;
    }

    protected function ccd(): Connection
    {
        return DB::connection('ccd');
    }

    /**
     * @return array<string, array{source: string, ref: string, kind: string}>
     */
    protected function partyTypes(): array
    {
        return config('ccd.party_types');
    }

    /** reference_name for a morph class (user|contact|company_contact|group). */
    protected function refForType(?string $morph): ?string
    {
        return $morph !== null ? (config('ccd.party_types')[$morph]['ref'] ?? null) : null;
    }

    /** Resolve a previously-migrated party id from its source (morph type + id). */
    protected function partyId(int $tenantId, ?string $morph, int|string|null $sourceId): ?int
    {
        $ref = $this->refForType($morph);

        if ($ref === null || $sourceId === null || $sourceId === '') {
            return null;
        }

        $id = $this->ccd()->table('parties')
            ->where('tenant_id', $tenantId)
            ->where('reference_name', $ref)
            ->where('reference_id', (string) $sourceId)
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    /** Resolve a ccd_banks id from a dealer_banks id (matched by name within tenant). */
    protected function bankId(int $tenantId, int|string|null $dealerBankId): ?int
    {
        if ($dealerBankId === null) {
            return null;
        }

        $name = DB::table('dealer_banks')->where('id', $dealerBankId)->value('display_name');

        if ($name === null) {
            return null;
        }

        $id = $this->ccd()->table('banks')
            ->where('tenant_id', $tenantId)->where('name', $name)->value('id');

        return $id !== null ? (int) $id : null;
    }

    /** Resolve a ccd_groups id from a dealer_groups id (matched by name within tenant). */
    protected function groupId(int $tenantId, int|string|null $dealerGroupId): ?int
    {
        if ($dealerGroupId === null) {
            return null;
        }

        $name = DB::table('dealer_groups')->where('id', $dealerGroupId)->value('name');

        if ($name === null || trim($name) === '') {
            return null;
        }

        $id = $this->ccd()->table('groups')
            ->where('tenant_id', $tenantId)->where('name', $name)->value('id');

        return $id !== null ? (int) $id : null;
    }

    /** Optional --limit as a positive int or null. */
    protected function limit(): ?int
    {
        return $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;
    }

    /**
     * Insert-or-update a target row keyed on $key. Returns 'created'|'updated'.
     *
     * @param  array<string, mixed>  $key
     * @param  array<string, mixed>  $values
     */
    protected function upsertCcd(string $table, array $key, array $values): string
    {
        $existing = $this->ccd()->table($table)->where($key)->first();

        if ($existing) {
            $this->ccd()->table($table)->where('id', $existing->id)
                ->update($values + ['updated_at' => now()]);

            return 'updated';
        }

        $this->ccd()->table($table)->insert($key + $values + ['created_at' => now(), 'updated_at' => now()]);

        return 'created';
    }
}
