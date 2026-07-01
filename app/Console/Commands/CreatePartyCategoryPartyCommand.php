<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\MigratesCcd;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * dealer_transaction_parties.role_id → ccd_party_category_party
 * (links a migrated party to its role/category). Needs parties + categories first.
 */
class CreatePartyCategoryPartyCommand extends Command
{
    use MigratesCcd;

    protected $signature = 'ccd:create-party-category-party {tenant_id} {--limit=}';

    protected $description = 'Link ccd_parties to ccd_party_categories from transaction party roles';

    public function handle(): int
    {
        $tenantId = (int) $this->argument('tenant_id');
        $countryId = $this->countryForTenant($tenantId);

        if ($countryId === null) {
            $this->error("No country mapped for tenant #{$tenantId}. Add it to config/ccd.php.");

            return self::FAILURE;
        }

        $query = DB::table('dealer_transaction_parties as tp')
            ->join('dealer_transactions as t', 't.id', '=', 'tp.transaction_id')
            ->where('t.country_id', $countryId)
            ->whereNull('tp.deleted_at')
            ->whereNotNull('tp.role_id')
            ->whereNotNull('tp.type_id')
            ->whereIn('tp.type', array_keys($this->partyTypes()))
            ->select('tp.type', 'tp.type_id', 'tp.role_id')
            ->distinct();

        if ($limit = $this->limit()) {
            $query->limit($limit);
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $failed = 0;
        $categoryIds = []; // role_id => ccd category id (cache)

        foreach ($query->get() as $tp) {
            $partyId = $this->partyId($tenantId, $tp->type, $tp->type_id);
            $categoryId = $categoryIds[$tp->role_id] ??= $this->categoryId($tenantId, $tp->role_id);

            if ($partyId === null || $categoryId === null) {
                $skipped++;

                continue;
            }

            try {
                $r = $this->upsertCcd('party_category_party',
                    ['party_id' => $partyId, 'party_category_id' => $categoryId],
                    ['tenant_id' => $tenantId],
                );
                $r === 'created' ? $created++ : $updated++;
            } catch (\Throwable $e) {
                $failed++;
            }
        }

        $this->info("Done — {$created} created, {$updated} updated, {$skipped} skipped, {$failed} failed.");

        return self::SUCCESS;
    }

    /** Resolve a ccd_party_categories id from a dealer role id (matched by name). */
    private function categoryId(int $tenantId, int|string $roleId): ?int
    {
        $role = DB::table('dealer_transaction_party_roles')->where('id', $roleId)->first();

        if ($role === null) {
            return null;
        }

        $name = trim((string) ($role->display_name ?: $role->name));

        $id = $this->ccd()->table('party_categories')
            ->where('tenant_id', $tenantId)->where('name', $name)->value('id');

        return $id !== null ? (int) $id : null;
    }
}
