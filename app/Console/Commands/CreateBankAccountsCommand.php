<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\MigratesCcd;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * dealer_bank_accounts → ccd_bank_accounts.
 *
 * The account's party (holder) and bank must already be migrated; an account
 * whose holder party isn't in ccd_parties (different country / not yet run) is
 * skipped, which also scopes this to the tenant.
 */
class CreateBankAccountsCommand extends Command
{
    use MigratesCcd;

    protected $signature = 'ccd:create-bank-accounts {tenant_id} {--limit=}';

    protected $description = 'Create/update ccd_bank_accounts from dealer_bank_accounts';

    public function handle(): int
    {
        $tenantId = (int) $this->argument('tenant_id');

        if ($this->countryForTenant($tenantId) === null) {
            $this->error("No country mapped for tenant #{$tenantId}. Add it to config/ccd.php.");

            return self::FAILURE;
        }

        $query = DB::table('dealer_bank_accounts')->whereNull('deleted_at')->orderBy('id');

        if ($limit = $this->limit()) {
            $query->limit($limit);
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($query->get() as $ba) {
            $partyId = $this->partyId($tenantId, $ba->account_holder_type, $ba->account_holder_id);
            $bankId = $this->bankId($tenantId, $ba->bank_id);
            $accountNo = trim((string) ($ba->account_no ?? ''));

            if ($partyId === null || $bankId === null || $accountNo === '') {
                $skipped++; // holder/bank not migrated for this tenant, or no account number

                continue;
            }

            try {
                $r = $this->upsertCcd('bank_accounts',
                    ['tenant_id' => $tenantId, 'bank_id' => $bankId, 'account_number' => $accountNo],
                    [
                        'party_id' => $partyId,
                        'account_name' => trim((string) ($ba->account_holder_name ?? '')) ?: 'Unknown',
                    ],
                );
                $r === 'created' ? $created++ : $updated++;
            } catch (\Throwable $e) {
                $failed++;
            }
        }

        $this->info("Done — {$created} created, {$updated} updated, {$skipped} skipped, {$failed} failed.");

        return self::SUCCESS;
    }
}
