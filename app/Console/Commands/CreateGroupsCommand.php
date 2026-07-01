<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\MigratesCcd;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * dealer_groups (of a tenant's country) → ccd_groups.
 */
class CreateGroupsCommand extends Command
{
    use MigratesCcd;

    protected $signature = 'ccd:create-groups {tenant_id} {--limit=}';

    protected $description = 'Create/update ccd_groups from dealer_groups';

    public function handle(): int
    {
        $tenantId = (int) $this->argument('tenant_id');
        $countryId = $this->countryForTenant($tenantId);

        if ($countryId === null) {
            $this->error("No country mapped for tenant #{$tenantId}. Add it to config/ccd.php.");

            return self::FAILURE;
        }

        $query = DB::table('dealer_groups')
            ->where('country_id', $countryId)
            ->whereNull('deleted_at')
            ->orderBy('id');

        if ($limit = $this->limit()) {
            $query->limit($limit);
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($query->get() as $g) {
            $name = trim((string) ($g->name ?: $g->display_name));

            if ($name === '') {
                $skipped++;

                continue;
            }

            try {
                $r = $this->upsertCcd('groups', ['tenant_id' => $tenantId, 'name' => $name], ['type' => 'group']);
                $r === 'created' ? $created++ : $updated++;
            } catch (\Throwable $e) {
                $failed++;
            }
        }

        $this->info("Done — {$created} created, {$updated} updated, {$skipped} skipped, {$failed} failed.");

        return self::SUCCESS;
    }
}
