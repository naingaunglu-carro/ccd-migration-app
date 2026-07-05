<?php

namespace App\Console\Commands\CCD;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Collect transaction document files into the transaction_file_ocr queue.
 *
 * Walks dealer_transactions (latest first, optionally filtered by country) and
 * for each transaction picks its dealer_files where
 *   model_type = App\Modules\Transaction\Models\Transaction
 *   model_id   = transaction.id
 *   custom_properties.tags overlaps the requested tags (default: owner_ic)
 * then upserts one row per file into transaction_file_ocr for the OCR pass.
 *
 * Re-running is safe: rows are keyed on file_id, and existing ocr_* results /
 * status are preserved (only the transaction reference and tags are refreshed).
 */
class QueueFileOcrCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'ccd:queue-file-ocr
        {--country= : Only transactions with this dealer_transactions.country_id}
        {--tags=owner_ic : Comma-separated custom_properties.tags to match (any-of)}
        {--limit= : Max matching files to queue (all when omitted)}';

    /**
     * @var string
     */
    protected $description = 'Queue transaction files (by tag) into transaction_file_ocr for OCR';

    private const TRANSACTION_MODEL = 'App\Modules\Transaction\Models\Transaction';

    public function handle(): int
    {
        $tags = collect(explode(',', (string) $this->option('tags')))
            ->map(fn ($t) => preg_replace('/[^A-Za-z0-9_]/', '', trim($t)))
            ->filter()
            ->unique()
            ->values();

        if ($tags->isEmpty()) {
            $this->error('No valid tags given via --tags.');

            return self::FAILURE;
        }

        $country = $this->option('country') !== null ? (int) $this->option('country') : null;
        $limit   = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $pgArray = '{' . $tags->implode(',') . '}';

        // Drive from the tag-filtered files. The file already carries its
        // transaction id in model_id, so transaction_id always comes from there;
        // the LEFT JOIN only enriches transaction_type (and enables the country
        // filter) when the transaction itself has been synced. Ordering by
        // model_id desc is "loop transactions, latest first".
        $query = DB::table('dealer_files as f')
            ->leftJoin('dealer_transactions as t', function ($join) {
                $join->on('t.id', '=', 'f.model_id')->whereNull('t.deleted_at');
            })
            ->where('f.model_type', self::TRANSACTION_MODEL)
            ->whereNull('f.deleted_at')
            ->whereRaw("jsonb_exists_any(f.custom_properties::jsonb->'tags', ?::text[])", [$pgArray])
            ->when($country !== null, fn ($q) => $q->where('t.country_id', $country))
            ->orderByDesc('f.model_id')
            ->select([
                'f.model_id as transaction_id',
                't.type as transaction_type',
                'f.id as file_id',
                DB::raw("f.custom_properties::jsonb->'tags' as file_tags"),
            ]);

        if ($limit !== null) {
            $query->limit($limit);
        }

        $total = $limit !== null ? min($limit, (clone $query)->count()) : (clone $query)->count();

        if ($total === 0) {
            $this->info("No transaction files match tags [{$tags->implode(', ')}]"
                . ($country !== null ? " for country #{$country}." : '.'));

            return self::SUCCESS;
        }

        $this->info("Queueing {$total} file(s) with tags [{$tags->implode(', ')}]"
            . ($country !== null ? " for country #{$country}" : '')
            . ' → transaction_file_ocr…');

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $now          = now();
        $queued       = 0;
        $buffer       = [];
        $remoteFailed = false;

        $flush = function () use (&$buffer, &$queued, &$remoteFailed, $now, $bar) {
            if ($buffer === []) {
                return;
            }

            $this->backfillTransactionType($buffer, $remoteFailed);

            // Upsert on file_id: refresh the transaction link and tags, but never
            // clobber ocr_status / ocr_* results captured by an earlier OCR pass.
            DB::table('transaction_file_ocr')->upsert(
                $buffer,
                ['file_id'],
                ['transaction_id', 'transaction_type', 'file_tags', 'updated_at'],
            );

            $queued += count($buffer);
            $bar->advance(count($buffer));
            $buffer = [];
        };

        foreach ($query->cursor() as $row) {
            $buffer[] = [
                'transaction_id'   => $row->transaction_id,
                'transaction_type' => $row->transaction_type,
                'file_id'          => $row->file_id,
                'file_tags'        => $row->file_tags, // jsonb text straight from the source
                'ocr_status'       => 'pending',
                'created_at'       => $now,
                'updated_at'       => $now,
            ];

            if (count($buffer) >= 500) {
                $flush();
            }
        }

        $flush();

        $bar->finish();
        $this->newLine(2);
        $this->info("Done. Queued {$queued} file(s) into transaction_file_ocr (status: pending).");

        return self::SUCCESS;
    }

    /**
     * Fill transaction_type for buffered rows whose transaction wasn't in the
     * local synced dealer_transactions, by looking it up in the live dealer DB.
     * A single batched query per flush; on connection failure it warns once and
     * leaves the remaining types null rather than aborting the whole run.
     *
     * @param  array<int, array<string, mixed>>  $buffer  (mutated in place)
     */
    private function backfillTransactionType(array &$buffer, bool &$remoteFailed): void
    {
        if ($remoteFailed) {
            return;
        }

        $missing = collect($buffer)
            ->filter(fn ($row) => $row['transaction_type'] === null && $row['transaction_id'] !== null)
            ->pluck('transaction_id')
            ->unique()
            ->values();

        if ($missing->isEmpty()) {
            return;
        }

        try {
            $types = DB::connection('dealer')->table('transactions')
                ->whereIn('id', $missing->all())
                ->pluck('type', 'id');
        } catch (\Throwable $e) {
            $remoteFailed = true;
            $this->newLine();
            $this->warn('dealer DB unavailable — leaving transaction_type null: ' . $e->getMessage());

            return;
        }

        foreach ($buffer as &$row) {
            if ($row['transaction_type'] === null) {
                $type = $types->get($row['transaction_id']);

                if ($type !== null) {
                    $row['transaction_type'] = $type;
                }
            }
        }

        unset($row);
    }
}
