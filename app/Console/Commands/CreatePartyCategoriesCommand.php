<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\MigratesCcd;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * dealer_transaction_party_roles → ccd_party_categories (a per-tenant copy).
 */
class CreatePartyCategoriesCommand extends Command
{
    use MigratesCcd;

    protected $signature = 'ccd:create-party-categories {tenant_id}';

    protected $description = 'Create/update ccd_party_categories from dealer transaction party roles';

    public function handle(): int
    {
        $tenantId = (int) $this->argument('tenant_id');

        $created = 0;
        $updated = 0;
        $failed = 0;

        foreach (DB::table('dealer_transaction_party_roles')->whereNull('deleted_at')->get() as $role) {
            $name = $this->firstNonEmpty([$role->display_name ?? null, $role->name ?? null]);

            if ($name === null) {
                continue;
            }

            try {
                $result = $this->upsertCcd('party_categories',
                    ['tenant_id' => $tenantId, 'name' => $name],
                    ['slug' => Str::slug($name), 'description' => null, 'is_active' => true],
                );
                $result === 'created' ? $created++ : $updated++;
            } catch (\Throwable $e) {
                $failed++;
            }
        }

        $this->info("Done — {$created} created, {$updated} updated, {$failed} failed.");

        return self::SUCCESS;
    }

    /** @param  array<int, mixed>  $values */
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
