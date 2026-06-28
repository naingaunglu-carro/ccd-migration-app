<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Migrate dealer_banks → ccd_banks for a single tenant.
 *
 * The tenant's country is looked up from the tenants table, that country filters
 * which dealer_banks rows are migrated, and the passed tenant_id is written onto
 * every inserted ccd_banks row.
 */
class MigrateBanksCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'ccd:migrate-banks {tenant_id : Target tenant id (its country selects the dealer_banks to migrate)}';

    /**
     * @var string
     */
    protected $description = 'Migrate dealer_banks into ccd_banks for a tenant (resolved by country)';

    /**
     * Manual tenant_id => country_id mapping. Add a row per tenant.
     *
     * @var array<int, int>
     */
    private const TENANT_COUNTRY = [
        1 => 49,
    ];

    public function handle(): int
    {
        $tenantId = (int) $this->argument('tenant_id');

        // country_id is resolved from the manual map above.
        $countryId = self::TENANT_COUNTRY[$tenantId] ?? null;

        if ($countryId === null) {
            $this->error("No country mapped for tenant #{$tenantId}. Add it to MigrateBanksCommand::TENANT_COUNTRY.");

            return self::FAILURE;
        }

        $this->info("Migrating dealer_banks for country #{$countryId} → ccd_banks (tenant #{$tenantId})…");

        $migrated = 0;

        // Only this tenant's country, excluding soft-deleted banks.
        DB::table('dealer_banks')
            ->where('country_id', $countryId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->chunkById(500, function ($banks) use ($tenantId, &$migrated) {
                $rows = $banks->map(fn ($bank) => [
                    'tenant_id' => $tenantId,
                    'name' => $bank->display_name,
                    'code' => null,
                    'swift_code' => $bank->bic,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->all();

                DB::connection('ccd')->table('banks')->insert($rows);
                $migrated += count($rows);
            }, 'id');

        $this->info("Done — migrated {$migrated} bank(s) into ccd_banks.");

        return self::SUCCESS;
    }
}
