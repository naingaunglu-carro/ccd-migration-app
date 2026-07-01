<?php

namespace App\Console\Commands\CCD;

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

    public function handle(): int
    {
        $tenantId = (int) $this->argument('tenant_id');

        // country_id is resolved from the shared tenant => country map in config/ccd.php.
        $countryId = config("ccd.tenant_country.{$tenantId}");

        if ($countryId === null) {
            $this->error("No country mapped for tenant #{$tenantId}. Add it to config/ccd.php (tenant_country).");

            return self::FAILURE;
        }

        $this->info("Migrating dealer_banks for country #{$countryId} → ccd_banks (tenant #{$tenantId})…");

        $migrated = 0;

        // Only this tenant's country, excluding soft-deleted banks.
        DB::table('dealer_banks')
            ->where('country_id', $countryId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->chunkById(500, function ($banks) use ($tenantId, &$migrated) {
                $rows = $banks
                    ->filter(fn ($bank) => filled($bank->display_name))
                    ->keyBy(fn ($bank) => $bank->display_name) // dedupe same name within the chunk
                    ->map(fn ($bank) => [
                        'tenant_id'  => $tenantId,
                        'name'       => $bank->display_name,
                        'code'       => null,
                        'swift_code' => $bank->bic,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])->values()->all();

                if ($rows !== []) {
                    // create-or-update on (tenant_id, name); created_at preserved on conflict
                    DB::connection('ccd')->table('banks')
                        ->upsert($rows, ['tenant_id', 'name'], ['code', 'swift_code', 'updated_at']);
                    $migrated += count($rows);
                }
            }, 'id');

        $this->info("Done — migrated {$migrated} bank(s) into ccd_banks.");

        return self::SUCCESS;
    }
}
