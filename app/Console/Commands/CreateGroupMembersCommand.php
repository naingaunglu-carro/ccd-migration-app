<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\MigratesCcd;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Link a migrated party to the group it belongs to → ccd_group_members.
 * Membership comes from the source record's group column. Needs parties + groups.
 */
class CreateGroupMembersCommand extends Command
{
    use MigratesCcd;

    protected $signature = 'ccd:create-group-members {tenant_id} {--limit=}';

    protected $description = 'Link ccd_parties to ccd_groups from dealer contact/user group ids';

    /** @var array<int, array{0: string, 1: string, 2: string}> [source table, reference_name, group column] */
    private const SOURCES = [
        ['dealer_contacts', 'contact', 'group_id'],
        ['dealer_users', 'user', 'active_group_id'],
    ];

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
        $skipped = 0;

        foreach (self::SOURCES as [$source, $ref, $groupCol]) {
            $query = DB::table($source)
                ->where('country_id', $countryId)
                ->whereNull('deleted_at')
                ->whereNotNull($groupCol)
                ->orderBy('id');

            if ($limit = $this->limit()) {
                $query->limit($limit);
            }

            foreach ($query->get() as $row) {
                $partyId = $this->ccd()->table('parties')
                    ->where('tenant_id', $tenantId)->where('reference_name', $ref)->where('reference_id', (string) $row->id)->value('id');
                $groupId = $this->groupId($tenantId, $row->{$groupCol});

                if ($partyId === null || $groupId === null) {
                    $skipped++;

                    continue;
                }

                $r = $this->upsertCcd('group_members',
                    ['group_id' => $groupId, 'party_id' => (int) $partyId],
                    ['tenant_id' => $tenantId, 'role' => null],
                );
                $r === 'created' ? $created++ : $updated++;
            }
        }

        $this->info("Done — {$created} created, {$updated} updated, {$skipped} skipped.");

        return self::SUCCESS;
    }
}
