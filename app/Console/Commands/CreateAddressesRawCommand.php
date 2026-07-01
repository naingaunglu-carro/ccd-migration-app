<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\MigratesCcd;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Raw addresses from dealer contacts/company_contacts/groups/users → ccd_addresses_raw,
 * tied (where possible) to the migrated party. Deduped on tenant + source + source_id.
 */
class CreateAddressesRawCommand extends Command
{
    use MigratesCcd;

    protected $signature = 'ccd:create-addresses-raw {tenant_id} {--limit=}';

    protected $description = 'Create/update ccd_addresses_raw from dealer entities';

    /**
     * source table => [reference_name, line1 col, line2 col|null, postcode col|null]
     *
     * @var array<string, array{0: string, 1: string, 2: ?string, 3: ?string}>
     */
    private const SOURCES = [
        'dealer_contacts' => ['contact', 'address', 'address_2', 'postcode'],
        'dealer_company_contacts' => ['company_contact', 'address', null, null],
        'dealer_groups' => ['group', 'address', 'business_letter_address', 'postal_code'],
        'dealer_users' => ['user', 'address', 'address_2', 'postcode'],
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

        foreach (self::SOURCES as $source => [$ref, $line1Col, $line2Col, $postcodeCol]) {
            $query = DB::table($source)
                ->where('country_id', $countryId)
                ->whereNull('deleted_at')
                ->orderBy('id');

            if ($limit = $this->limit()) {
                $query->limit($limit);
            }

            foreach ($query->get() as $row) {
                $row = (array) $row;
                $line1 = trim((string) ($row[$line1Col] ?? ''));
                $line2 = $line2Col ? trim((string) ($row[$line2Col] ?? '')) : '';
                $postcode = $postcodeCol ? trim((string) ($row[$postcodeCol] ?? '')) : '';

                if ($line1 === '' && $postcode === '') {
                    $skipped++; // nothing to store

                    continue;
                }

                $partyId = $this->ccd()->table('parties')
                    ->where('tenant_id', $tenantId)->where('reference_name', $ref)->where('reference_id', (string) $row['id'])->value('id');

                $r = $this->upsertCcd('addresses_raw',
                    ['tenant_id' => $tenantId, 'source' => $source, 'source_id' => (string) $row['id']],
                    [
                        'raw_line_1' => $line1 ?: null,
                        'raw_line_2' => $line2 ?: null,
                        'raw_postcode' => $postcode ?: null,
                        'raw_full' => trim("{$line1} {$line2} {$postcode}") ?: null,
                        'party_id' => $partyId !== null ? (int) $partyId : null,
                        'parse_status' => 'pending',
                    ],
                );
                $r === 'created' ? $created++ : $updated++;
            }
        }

        $this->info("Done — {$created} created, {$updated} updated, {$skipped} skipped.");

        return self::SUCCESS;
    }
}
