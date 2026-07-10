<?php

namespace App\Console\Commands\CCD;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Export dealer `files` rows for a batch of transactions into TSV file(s).
 *
 * Walks local dealer_transactions (latest first, optionally filtered by
 * country, optionally capped by --limit), then queries the remote dealer DB's
 * `files` table directly — bypassing whatever's already synced locally into
 * dealer_files — for rows where
 *   model_type = App\Modules\Transaction\Models\Transaction
 *   model_id   IN (the batch of transaction ids)
 * Transaction ids are split into --transaction-batch-sized IN() lists so each
 * remote query stays bounded. Rows are written into rotating batch files
 * (dealer_files_batch_1.tsv, _2.tsv, …) under
 * storage/app/raw_data/files/{country|all}/{date}/, capped at --max-rows data
 * rows per file (header repeated in each file) so no single TSV grows
 * unbounded.
 */
class DownloadTransactionFilesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'ccd:download-transaction-files_dump
        {--country= : Only dealer_transactions for this dealer_countries.id or .country_code (e.g. 49 or my)}
        {--limit= : Max dealer_transactions to include (latest first); all when omitted}
        {--transaction-batch=500 : Transaction ids per remote files query}
        {--max-rows=100000 : Max data rows per TSV file before rotating to a new batch file}
        {--dest= : Output directory for batch TSVs (default storage/app/raw_data/files/[country|all]/date)}';

    /**
     * @var string
     */
    protected $description = 'Download dealer `files` rows for transactions (by country/latest/limit) into batch TSV files';

    private const TRANSACTION_MODEL = 'App\Modules\Transaction\Models\Transaction';

    private const COLUMNS = [
        'id', 'uuid', 'slug', 'model_id', 'model_type', 'collection_name', 'name', 'file_name',
        'mime_type', 'disk', 'conversions_disk', 'size', 'original_size',
        'custom_properties', 'order_column', 'created_at',
        'updated_at', 'deleted_at',
    ];

    public function handle(): int
    {
        $countryOpt = $this->option('country') !== null ? trim((string) $this->option('country')) : null;
        $limit      = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $batch      = max(1, (int) $this->option('transaction-batch'));

        $countryId   = null;
        $countryCode = null;

        if ($countryOpt !== null) {
            // Accept either a numeric dealer_countries.id (back-compat with
            // ccd:queue-file-ocr's --country) or a country_code like "my".
            $country = ctype_digit($countryOpt)
                ? DB::table('dealer_countries')->where('id', (int) $countryOpt)->first()
                : DB::table('dealer_countries')->where('country_code', strtoupper($countryOpt))->first();

            if ($country === null) {
                $this->error("Unknown dealer_countries entry: {$countryOpt}");

                return self::FAILURE;
            }

            $countryId   = $country->id;
            $countryCode = strtolower((string) $country->country_code);
        }

        $ids = DB::table('dealer_transactions')
            ->when($countryId !== null, fn ($q) => $q->where('country_id', $countryId))
            ->orderByDesc('created_at')
            ->when($limit !== null, fn ($q) => $q->limit($limit))
            ->pluck('id');

        if ($ids->isEmpty()) {
            $this->info('No dealer_transactions match'
                . ($countryCode !== null ? " country [{$countryCode}]." : '.'));

            return self::SUCCESS;
        }

        $maxRows = $this->option('max-rows') !== null ? max(1, (int) $this->option('max-rows')) : null;

        $countryFolder = $countryCode ?? 'all';
        $dateFolder    = now()->format('Ymd');
        $dir           = $this->option('dest') ?: storage_path("app/raw_data/files/{$countryFolder}/{$dateFolder}");

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $conn = config('sync.connections.dealer');

        if (! is_array($conn) || empty($conn['host'])) {
            $this->error('Sync connection [dealer] is not configured.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Exporting files for %d transaction(s)%s in batches of %d (max %s row(s)/file) → %s',
            $ids->count(),
            $countryCode !== null ? " (country [{$countryCode}])" : '',
            $batch,
            $maxRows !== null ? (string) $maxRows : 'unlimited',
            $dir,
        ));

        $writer = new TsvBatchWriter($dir, $maxRows);

        $chunks = $ids->chunk($batch);
        $bar    = $this->output->createProgressBar($chunks->count());
        $bar->start();

        $tmp = $dir . '/.batch.part';

        try {
            foreach ($chunks as $chunk) {
                $this->stream($conn, $this->selectSql($chunk->all()), $tmp);
                $this->appendChunk($tmp, $writer);
                $bar->advance();
            }
        } finally {
            $writer->close();
            @unlink($tmp);
        }

        $bar->finish();
        $this->newLine(2);
        $this->info(sprintf(
            'Done. %d file row(s) exported across %d batch file(s) → %s',
            $writer->totalRows(),
            $writer->fileCount(),
            $dir,
        ));

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

        // Escape backslashes (and quotes) for MySQL's string-literal parser —
        // otherwise "\M"/"\T" in the class name are treated as (unrecognized)
        // escape sequences and MySQL silently drops the backslashes, so the
        // WHERE clause never matches and the export comes back empty.
        $modelType = addcslashes(self::TRANSACTION_MODEL, "\\'");

        return "SELECT {$columns} FROM files"
            . " WHERE model_type = '{$modelType}'"
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
     * Feed one batch's data rows into the rotating writer (header cached once).
     */
    private function appendChunk(string $tmp, TsvBatchWriter $writer): void
    {
        $in = fopen($tmp, 'rb');

        if ($in === false) {
            return;
        }

        $lineNo = 0;

        try {
            while (($line = fgets($in)) !== false) {
                $lineNo++;

                if ($lineNo === 1) {
                    $writer->setHeader($line);

                    continue;
                }

                if (trim($line) === '') {
                    continue;
                }

                $writer->writeRow($line);
            }
        } finally {
            fclose($in);
        }
    }
}

/**
 * Writes data rows into rotating "dealer_files_batch_{n}.tsv" files inside a
 * directory, starting a new file (with the header repeated) once --max-rows
 * data rows have been written to the current one.
 */
class TsvBatchWriter
{
    private int $batchNum = 0;

    /** @var resource|null */
    private $handle = null;

    private ?string $header = null;

    private int $rowsInFile = 0;

    private int $totalRows = 0;

    /** @var array<int, string> */
    private array $paths = [];

    public function __construct(private readonly string $dir, private readonly ?int $maxRows) {}

    public function setHeader(string $line): void
    {
        $this->header ??= $line;
    }

    public function writeRow(string $line): void
    {
        if ($this->handle === null || ($this->maxRows !== null && $this->rowsInFile >= $this->maxRows)) {
            $this->rotate();
        }

        fwrite($this->handle, $line);
        $this->rowsInFile++;
        $this->totalRows++;
    }

    public function totalRows(): int
    {
        return $this->totalRows;
    }

    public function fileCount(): int
    {
        return count($this->paths);
    }

    public function close(): void
    {
        if ($this->handle === null && $this->header !== null && $this->paths === []) {
            $this->rotate();
        }

        $this->closeHandle();
    }

    private function closeHandle(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    private function rotate(): void
    {
        $this->closeHandle();

        $this->batchNum++;
        $path   = $this->dir . "/dealer_files_batch_{$this->batchNum}.tsv";
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException("Unable to open output file: {$path}");
        }

        $this->handle       = $handle;
        $this->paths[]      = $path;
        $this->rowsInFile   = 0;

        if ($this->header !== null) {
            fwrite($this->handle, $this->header);
        }
    }
}
