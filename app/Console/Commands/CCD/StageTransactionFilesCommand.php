<?php

namespace App\Console\Commands\CCD;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Populate stg_transaction_files: one row per (transaction, tag_name), walking
 * dealer_transactions latest-first and recording whether a dealer_files
 * object tagged tag_name exists for it — file_id set when found, null
 * otherwise. downloaded_at/downloaded_path (set later by the download step)
 * are left untouched on re-runs.
 *
 * When a transaction has more than one dealer_files row for the same tag, the
 * highest id (most recently uploaded) wins — the unique (transaction_id,
 * tag_name) key allows only one match per transaction.
 */
class StageTransactionFilesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'ccd:stage-transaction-files
        {tag_name : dealer_files.tag_name to match (e.g. bos, voc, vrc, owner_ic)}
        {--country= : Only dealer_transactions for this dealer_countries.id or .country_code (all when omitted)}
        {--limit= : Max transactions to process (all when omitted)}';

    /**
     * @var string
     */
    protected $description = 'Populate stg_transaction_files from dealer_transactions (latest first) for a given tag';

    private const TRANSACTION_MODEL = 'App\Modules\Transaction\Models\Transaction';

    public function handle(): int
    {
        $tagName = trim((string) $this->argument('tag_name'));

        if ($tagName === '') {
            $this->error('tag_name must not be empty.');

            return self::FAILURE;
        }

        $countryOpt = $this->option('country') !== null ? trim((string) $this->option('country')) : null;
        $country    = null;

        if ($countryOpt !== null) {
            // Accept either a numeric dealer_countries.id or a country_code like "MY".
            $row = ctype_digit($countryOpt)
                ? DB::table('dealer_countries')->where('id', (int) $countryOpt)->first()
                : DB::table('dealer_countries')->where('country_code', strtoupper($countryOpt))->first();

            if ($row === null) {
                $this->error("Unknown dealer_countries entry: {$countryOpt}");

                return self::FAILURE;
            }

            $country = $row->id;
        }

        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        // One matched file per transaction for this tag — the highest id
        // (latest upload) wins when a transaction has more than one.
        $fileMatches = DB::table('dealer_files')
            ->where('model_type', self::TRANSACTION_MODEL)
            ->where('tag_name', $tagName)
            ->whereNull('deleted_at')
            ->selectRaw('model_id as transaction_id, max(id) as file_id')
            ->groupBy('model_id');

        $base = DB::table('dealer_transactions as t')
            ->leftJoinSub($fileMatches, 'f', 'f.transaction_id', '=', 't.id')
            ->whereNull('t.deleted_at')
            ->when($country !== null, fn ($q) => $q->where('t.country_id', $country));

        $total = $limit !== null ? min($limit, (clone $base)->count()) : (clone $base)->count();

        if ($total === 0) {
            $this->info('No dealer_transactions match' . ($country !== null ? " country #{$country}." : '.'));

            return self::SUCCESS;
        }

        $query = $base
            ->orderByDesc('t.id')
            ->select(['t.id as transaction_id', 'f.file_id']);

        if ($limit !== null) {
            $query->limit($limit);
        }

        $this->info("Staging {$total} transaction(s) for tag '{$tagName}'"
            . ($country !== null ? " (country #{$country})" : '') . '…');

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $now    = now();
        $staged = 0;
        $buffer = [];

        $flush = function () use (&$buffer, &$staged, $bar) {
            if ($buffer === []) {
                return;
            }

            // Upsert on (transaction_id, tag_name): refresh the file match, but
            // never touch downloaded_at/downloaded_path from an earlier download pass.
            DB::table('stg_transaction_files')->upsert(
                $buffer,
                ['transaction_id', 'tag_name'],
                ['file_id', 'updated_at'],
            );

            $staged += count($buffer);
            $bar->advance(count($buffer));
            $buffer = [];
        };

        foreach ($query->cursor() as $row) {
            $buffer[] = [
                'transaction_id' => $row->transaction_id,
                'file_id'        => $row->file_id,
                'tag_name'       => $tagName,
                'created_at'     => $now,
                'updated_at'     => $now,
            ];

            if (count($buffer) >= 500) {
                $flush();
            }
        }

        $flush();

        $bar->finish();
        $this->newLine(2);
        $this->info("Done. Staged {$staged} transaction(s) for tag '{$tagName}'.");

        return self::SUCCESS;
    }
}
