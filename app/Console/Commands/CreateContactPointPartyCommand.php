<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\MigratesCcd;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Link a migrated party to its contact points (email/phone) → ccd_contact_point_party.
 * Needs parties + contact_points first.
 */
class CreateContactPointPartyCommand extends Command
{
    use MigratesCcd;

    protected $signature = 'ccd:create-contact-point-party {tenant_id} {--limit=}';

    protected $description = 'Link ccd_parties to ccd_contact_points (email/phone) per dealer contact/user';

    /** @var array<string, string> source table => reference_name */
    private const SOURCES = [
        'dealer_contacts' => 'contact',
        'dealer_users' => 'user',
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

        foreach (self::SOURCES as $source => $ref) {
            $query = DB::table($source)
                ->where('country_id', $countryId)
                ->whereNull('deleted_at')
                ->orderBy('id');

            if ($limit = $this->limit()) {
                $query->limit($limit);
            }

            foreach ($query->get() as $row) {
                $partyId = $this->partyByRef($tenantId, $ref, $row->id);

                if ($partyId === null) {
                    $skipped++;

                    continue;
                }

                foreach (['email' => $row->email ?? null, 'phone' => $row->phone ?? null] as $channel => $value) {
                    $value = trim((string) ($value ?? ''));

                    if ($value === '') {
                        continue;
                    }

                    $cpId = $this->ccd()->table('contact_points')
                        ->where('tenant_id', $tenantId)->where('channel', $channel)->where('value', $value)->value('id');

                    if ($cpId === null) {
                        continue; // contact point not created (run ccd:create-contact-points first)
                    }

                    $r = $this->upsertCcd('contact_point_party',
                        ['contact_point_id' => (int) $cpId, 'party_id' => $partyId],
                        ['tenant_id' => $tenantId],
                    );
                    $r === 'created' ? $created++ : $updated++;
                }
            }
        }

        $this->info("Done — {$created} created, {$updated} updated, {$skipped} skipped.");

        return self::SUCCESS;
    }

    private function partyByRef(int $tenantId, string $ref, int|string $sourceId): ?int
    {
        $id = $this->ccd()->table('parties')
            ->where('tenant_id', $tenantId)->where('reference_name', $ref)->where('reference_id', (string) $sourceId)->value('id');

        return $id !== null ? (int) $id : null;
    }
}
