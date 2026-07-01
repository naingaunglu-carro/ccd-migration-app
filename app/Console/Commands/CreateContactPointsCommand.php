<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\MigratesCcd;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Emails / phones of a tenant's contacts and users → ccd_contact_points
 * (deduped on tenant + channel + value).
 */
class CreateContactPointsCommand extends Command
{
    use MigratesCcd;

    protected $signature = 'ccd:create-contact-points {tenant_id} {--limit=}';

    protected $description = 'Create/update ccd_contact_points from dealer contacts/users';

    public function handle(): int
    {
        $tenantId = (int) $this->argument('tenant_id');
        $countryId = $this->countryForTenant($tenantId);

        if ($countryId === null) {
            $this->error("No country mapped for tenant #{$tenantId}. Add it to config/ccd.php.");

            return self::FAILURE;
        }

        $created = 0;
        $updated = 0;

        foreach (['dealer_contacts', 'dealer_users'] as $source) {
            $query = DB::table($source)
                ->where('country_id', $countryId)
                ->whereNull('deleted_at')
                ->orderBy('id');

            if ($limit = $this->limit()) {
                $query->limit($limit);
            }

            foreach ($query->get() as $row) {
                foreach (['email' => $row->email ?? null, 'phone' => $row->phone ?? null] as $channel => $value) {
                    $value = trim((string) ($value ?? ''));

                    if ($value === '') {
                        continue;
                    }

                    $r = $this->upsertCcd('contact_points',
                        ['tenant_id' => $tenantId, 'channel' => $channel, 'value' => $value], []);
                    $r === 'created' ? $created++ : $updated++;
                }
            }
        }

        $this->info("Done — {$created} created, {$updated} updated.");

        return self::SUCCESS;
    }
}
