<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Build ccd_parties from the dealer transaction parties of one tenant.
 *
 * tenant_id → country_id (manual map) → the dealer_transactions of that country →
 * their dealer_transaction_parties. Each party is polymorphic (type/type_id), so
 * we load the real record from the matching dealer_* table and map it onto a
 * party, created-or-updated by its source reference.
 */
class CreatePartiesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'ccd:create-parties
        {tenant_id : Target tenant id (its country selects the dealer data)}
        {--limit= : Max distinct parties to process (for testing)}';

    /**
     * @var string
     */
    protected $description = 'Create/update ccd_parties from a tenant\'s dealer transaction parties';

    /**
     * Manual tenant_id => country_id mapping.
     *
     * @var array<int, int>
     */
    private const TENANT_COUNTRY = [
        1 => 49,
    ];

    /**
     * dealer_transaction_parties.type (morph class) => how to resolve it.
     *   source: dealer_* table to read,  ref: reference_name tag,  kind: party type
     *
     * @var array<string, array{source: string, ref: string, kind: string}>
     */
    private const TYPE_MAP = [
        'App\\Modules\\Account\\User\\Models\\User' => ['source' => 'dealer_users', 'ref' => 'user', 'kind' => 'person'],
        'App\\Modules\\Contact\\Models\\Contact' => ['source' => 'dealer_contacts', 'ref' => 'contact', 'kind' => 'person'],
        'App\\Modules\\Contact\\Models\\CompanyContact' => ['source' => 'dealer_company_contacts', 'ref' => 'company_contact', 'kind' => 'company'],
        'App\\Modules\\Account\\Group\\Models\\Group' => ['source' => 'dealer_groups', 'ref' => 'group', 'kind' => 'company'],
    ];

    public function handle(): int
    {
        $tenantId = (int) $this->argument('tenant_id');
        $countryId = self::TENANT_COUNTRY[$tenantId] ?? null;

        if ($countryId === null) {
            $this->error("No country mapped for tenant #{$tenantId}. Add it to CreatePartiesCommand::TENANT_COUNTRY.");

            return self::FAILURE;
        }

        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;

        // Distinct (type, type_id) parties belonging to this country's transactions.
        $query = DB::table('dealer_transaction_parties as tp')
            ->join('dealer_transactions as t', 't.id', '=', 'tp.transaction_id')
            ->where('t.country_id', $countryId)
            ->whereNull('tp.deleted_at')
            ->whereIn('tp.type', array_keys(self::TYPE_MAP))
            ->whereNotNull('tp.type_id')
            ->select('tp.type', 'tp.type_id')
            ->distinct()
            ->orderBy('tp.type')
            ->orderBy('tp.type_id');

        if ($limit !== null) {
            $query->limit($limit);
        }

        $parties = $query->get();
        $this->info("Tenant #{$tenantId} (country #{$countryId}): {$parties->count()} distinct parties to process…");

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($parties as $p) {
            $map = self::TYPE_MAP[$p->type];
            $source = (array) DB::table($map['source'])->where('id', $p->type_id)->first();

            if ($source === []) {
                $skipped++; // source record missing

                continue;
            }

            $row = $this->mapParty($map, $source);
            $key = [
                'tenant_id' => $tenantId,
                'reference_id' => (string) $p->type_id,
                'reference_name' => $map['ref'],
            ];

            try {
                // Match an existing party by its reference OR any natural key the
                // schema enforces unique (national id / company reg / tax id), so
                // duplicate source records collapse onto one party.
                $existing = $this->findExisting($tenantId, $key, $row);

                if ($existing) {
                    DB::connection('ccd')->table('parties')->where('id', $existing->id)
                        ->update($key + $row + ['updated_at' => now()]);
                    $updated++;
                } else {
                    DB::connection('ccd')->table('parties')
                        ->insert($key + $row + ['created_at' => now(), 'updated_at' => now()]);
                    $created++;
                }
            } catch (\Throwable $e) {
                $failed++; // conflicting natural keys across different rows — skip
            }
        }

        $this->info("Done — {$created} created, {$updated} updated, {$skipped} skipped, {$failed} failed.");

        return self::SUCCESS;
    }

    /**
     * Map a source dealer_* row to ccd_parties attributes (excludes the key cols).
     *
     * @param  array{source: string, ref: string, kind: string}  $map
     * @param  array<string, mixed>  $src
     * @return array<string, mixed>
     */
    private function mapParty(array $map, array $src): array
    {
        $name = $this->firstNonEmpty([
            $src['name'] ?? null,
            trim(($src['first_name'] ?? '').' '.($src['last_name'] ?? '')),
            $src['display_name'] ?? null,
        ]) ?? 'Unknown';

        return [
            'type' => $map['kind'],
            'is_host' => false,
            'name' => mb_substr($name, 0, 255),
            'person_first_name' => $src['first_name'] ?? null,
            'person_last_name' => $src['last_name'] ?? null,
            'person_gender' => $src['gender'] ?? null,
            'person_date_of_birth' => isset($src['date_of_birth']) ? substr((string) $src['date_of_birth'], 0, 10) : null,
            'person_national_id' => $this->cleanKey($src['national_identity_number'] ?? null),
            // company reg no: UEN for company_contacts, company_registration_number elsewhere
            'company_registration_number' => $this->cleanKey($src['unique_entity_number'] ?? ($src['company_registration_number'] ?? null)),
            'company_tax_id' => $this->cleanKey($src['tax_identification_number'] ?? null),
        ];
    }

    /**
     * Find an existing party that this source row should merge into: same source
     * reference, or any tenant-unique natural key it carries. Returns null to insert.
     *
     * @param  array<string, mixed>  $key
     * @param  array<string, mixed>  $row
     */
    private function findExisting(int $tenantId, array $key, array $row): ?object
    {
        return DB::connection('ccd')->table('parties')
            ->where('tenant_id', $tenantId)
            ->where(function ($w) use ($key, $row) {
                $w->where(fn ($q) => $q
                    ->where('reference_id', $key['reference_id'])
                    ->where('reference_name', $key['reference_name']));

                foreach (['person_national_id', 'company_registration_number', 'company_tax_id'] as $col) {
                    if (! empty($row[$col])) {
                        $w->orWhere($col, $row[$col]);
                    }
                }
            })
            ->first();
    }

    /**
     * Null out blank or obvious-placeholder identifiers (e.g. "1111111", "XXXX",
     * "000000") so they don't false-merge parties or trip unique constraints.
     */
    private function cleanKey(?string $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '' || preg_match('/^(.)\1+$/', $value)) {
            return null;
        }

        return $value;
    }

    /**
     * @param  array<int, mixed>  $values
     */
    private function firstNonEmpty(array $values): ?string
    {
        foreach ($values as $v) {
            if ($v !== null && trim((string) $v) !== '') {
                return (string) $v;
            }
        }

        return null;
    }
}
