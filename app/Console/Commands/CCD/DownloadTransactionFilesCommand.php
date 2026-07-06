<?php

namespace App\Console\Commands\CCD;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Export dealer `files` rows for a batch of transactions into a single TSV.
 *
 * Walks local dealer_transactions (latest first, optionally filtered by
 * country, optionally capped by --limit), then queries the remote dealer DB's
 * `files` table directly — bypassing whatever's already synced locally into
 * dealer_files — for rows where
 *   model_type = App\Modules\Transaction\Models\Transaction
 *   model_id   IN (the batch of transaction ids)
 * Transaction ids are split into --transaction-batch-sized IN() lists so each
 * remote query stays bounded; every batch's rows are appended into the same
 * output file (single TSV, header written once).
 */
class DownloadTransactionFilesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'ccd:download-transaction-files
        {--country= : Only dealer_transactions with this country_id}
        {--limit= : Max dealer_transactions to include (latest first); all when omitted}
        {--transaction-batch=500 : Transaction ids per remote files query}
        {--dest= : Output TSV path (default storage/app/raw_data/files/{timestamp}.tsv)}';

    /**
     * @var string
     */
    protected $description = 'Download dealer `files` rows for transactions (by country/latest/limit) into one TSV';

    private const TRANSACTION_MODEL = 'App\Modules\Transaction\Models\Transaction';

    private const COLUMNS = [
        'id', 'uuid', 'slug', 'model_id', 'model_type', 'collection_name', 'name', 'file_name',
        'mime_type', 'disk', 'conversions_disk', 'size', 'original_size', 'manipulations',
        'custom_properties', 'order_column', 'is_optimized', 'lark_code', 'created_at',
        'updated_at', 'deleted_at', 'responsive_images', 'generated_conversions',
    ];

    public function handle(): int
    {
        $country = $this->option('country') !== null ? (int) $this->option('country') : null;
        $limit   = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $batch   = max(1, (int) $this->option('transaction-batch'));

        $ids = DB::table('dealer_transactions')
            ->when($country !== null, fn ($q) => $q->where('country_id', $country))
            ->orderByDesc('created_at')
            ->when($limit !== null, fn ($q) => $q->limit($limit))
            ->pluck('id');

        if ($ids->isEmpty()) {
            $this->info('No dealer_transactions match'
                . ($country !== null ? " country #{$country}." : '.'));

            return self::SUCCESS;
        }

        $dest = $this->option('dest') ?: storage_path('app/raw_data/files/' . now()->format('Ymd_His') . '.tsv');
        $dir  = dirname($dest);

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $conn = config('sync.connections.dealer');

        if (! is_array($conn) || empty($conn['host'])) {
            $this->error('Sync connection [dealer] is not configured.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Exporting files for %d transaction(s)%s in batches of %d → %s',
            $ids->count(),
            $country !== null ? " (country #{$country})" : '',
            $batch,
            $dest,
        ));

        $main = fopen($dest, 'wb');

        if ($main === false) {
            $this->error("Unable to open output file: {$dest}");

            return self::FAILURE;
        }

        $chunks = $ids->chunk($batch);
        $bar    = $this->output->createProgressBar($chunks->count());
        $bar->start();

        $tmp    = $dest . '.part';
        $rows   = 0;
        $first  = true;

        try {
            foreach ($chunks as $chunk) {
                $this->stream($conn, $this->selectSql($chunk->all()), $tmp);
                $rows += $this->appendChunk($tmp, $main, $first);
                $first = false;
                $bar->advance();
            }
        } finally {
            fclose($main);
            @unlink($tmp);
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Done. {$rows} file row(s) exported → {$dest}");

        return self::SUCCESS;
    }

    /**
     * Build the remote SELECT for one batch of transaction ids.
     *
     * @param  array<int, int>  $ids
     */
    private function selectSql(array $ids): string
    {
        $columns = implode(', ', self::COLUMNS);
        $inList  = implode(',', array_map('intval', $ids));

        return "SELECT {$columns} FROM files"
            . " WHERE model_type = '" . self::TRANSACTION_MODEL . "'"
            . " AND model_id IN ({$inList})";
    }

    /**
     * Run one export query via the mysql client, streaming stdout into a file.
     *
     * @param  array<string, mixed>  $conn
     */
    private function stream(array $conn, string $sql, string $path): void
    {
        $binary = config('sync.binaries.mysql', 'mysql');
        $flags  = (array) config('sync.drivers.mysql.flags', []);

        $command = array_merge(
            [$binary],
            ['-h', (string) $conn['host']],
            ['-P', (string) $conn['port']],
            ['-u', (string) $conn['username']],
            ['--database', (string) $conn['database']],
            $flags,
            ['--ssl-mode=' . $conn['ssl_mode']],
            ['--connect-timeout=' . $conn['connect_timeout']],
            ['-e', $sql],
        );

        $process = new Process($command, timeout: null, env: ['MYSQL_PWD' => (string) $conn['password']]);
        $handle  = fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException("Unable to open temp file: {$path}");
        }

        try {
            $process->run(function (string $type, string $buffer) use ($handle) {
                if ($type === Process::OUT) {
                    fwrite($handle, $buffer);
                }
            });
        } finally {
            fclose($handle);
        }

        if (! $process->isSuccessful()) {
            throw new RuntimeException('mysql export failed: ' . trim($process->getErrorOutput() ?: $process->getOutput()));
        }
    }

    /**
     * Append a batch's data rows into the combined file (header only once).
     *
     * @param  resource  $main
     */
    private function appendChunk(string $tmp, $main, bool $withHeader): int
    {
        $in = fopen($tmp, 'rb');

        if ($in === false) {
            return 0;
        }

        $rows   = 0;
        $lineNo = 0;

        try {
            while (($line = fgets($in)) !== false) {
                $lineNo++;

                if ($lineNo === 1) {
                    if ($withHeader) {
                        fwrite($main, $line);
                    }

                    continue;
                }

                if (trim($line) === '') {
                    continue;
                }

                fwrite($main, $line);
                $rows++;
            }
        } finally {
            fclose($in);
        }

        return $rows;
    }
}
