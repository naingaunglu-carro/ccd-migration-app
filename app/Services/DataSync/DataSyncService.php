<?php

namespace App\Services\DataSync;

use App\Models\SyncLog;
use App\Models\SyncSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;

class DataSyncService
{
    /**
     * Run a full sync for the given source:
     * export from the source DB into a TSV file, then upsert into the target table.
     */
    public function sync(SyncSource $source): SyncLog
    {
        $log = $this->startLog($source);

        try {
            $path = $this->export($source);

            $rows = $this->parse($source, $path);
            $stats = $this->load($source, $rows);

            $source->forceFill(['last_synced_at' => Carbon::now()])->save();

            $log->forceFill([
                'status' => SyncLog::STATUS_COMPLETED,
                'file_path' => $path,
                'rows_read' => $stats['read'],
                'rows_inserted' => $stats['inserted'],
                'rows_updated' => $stats['updated'],
                'finished_at' => Carbon::now(),
            ])->save();
        } catch (\Throwable $e) {
            $log->forceFill([
                'status' => SyncLog::STATUS_FAILED,
                'error_message' => $e->getMessage(),
                'finished_at' => Carbon::now(),
            ])->save();

            throw $e;
        }

        return $log->fresh();
    }

    /**
     * Export the configured columns from the source table into a TSV file
     * using the mysql CLI, and return the written file path.
     */
    public function export(SyncSource $source): string
    {
        $conn = $this->connection($source);
        $path = $this->outputPath($source);

        $process = new Process(
            $this->command($source, $conn),
            timeout: null,
            env: ['MYSQL_PWD' => (string) $conn['password']], // keep the password off argv
        );

        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException("Unable to open output file: {$path}");
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
            throw new RuntimeException(
                'mysql export failed: '.trim($process->getErrorOutput() ?: $process->getOutput())
            );
        }

        return $path;
    }

    /**
     * Parse a TSV export into rows keyed by target column name.
     *
     * mysql --batch --raw emits a header row and \N for NULLs.
     *
     * @return list<array<string, string|null>>
     */
    public function parse(SyncSource $source, string $path): array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Unable to read export file: {$path}");
        }

        $rows = [];
        $headers = null;

        try {
            while (($line = fgets($handle)) !== false) {
                $line = rtrim($line, "\r\n");

                if ($line === '' && $headers !== null) {
                    continue;
                }

                $values = explode("\t", $line);

                if ($headers === null) {
                    $headers = $values; // source column names

                    continue;
                }

                $row = [];

                foreach ($headers as $i => $sourceColumn) {
                    $target = $source->columns[$sourceColumn] ?? null;

                    if ($target === null) {
                        continue; // column not mapped to the target table
                    }

                    $value = $values[$i] ?? null;
                    $row[$target] = $value === '\N' ? null : $value;
                }

                $rows[] = $row;
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    /**
     * Upsert parsed rows into the target table, stamping last_synced_at.
     *
     * @param  list<array<string, string|null>>  $rows
     * @return array{read: int, inserted: int, updated: int}
     */
    public function load(SyncSource $source, array $rows): array
    {
        $key = $source->targetKey();
        $now = Carbon::now();

        $existing = DB::table($source->target_table)->pluck($key)->all();
        $existing = array_flip(array_map('strval', $existing));

        $inserted = 0;
        $updated = 0;

        foreach (array_chunk($rows, 500) as $chunk) {
            $payload = [];

            foreach ($chunk as $row) {
                $row['last_synced_at'] = $now;
                $payload[] = $row;

                isset($existing[(string) ($row[$key] ?? '')]) ? $updated++ : $inserted++;
            }

            $updateColumns = array_values(array_diff(array_keys($payload[0]), [$key]));

            DB::table($source->target_table)->upsert($payload, [$key], $updateColumns);
        }

        return ['read' => count($rows), 'inserted' => $inserted, 'updated' => $updated];
    }

    /**
     * Build the mysql CLI invocation as an argv array (no shell interpolation).
     *
     * @param  array<string, mixed>  $conn
     * @return list<string>
     */
    protected function command(SyncSource $source, array $conn): array
    {
        $columns = implode(',', array_keys($source->columns));
        $query = "select {$columns} from {$source->source_table}";

        return array_merge(
            [config('sync.mysql_binary', 'mysql')],
            ['-h', (string) $conn['host']],
            ['-P', (string) $conn['port']],
            ['-u', (string) $conn['username']],
            ['--database', (string) $conn['database']],
            (array) config('sync.flags', ['--batch', '--raw', '--quick']),
            ['--ssl-mode='.$conn['ssl_mode']],
            ['--connect-timeout='.$conn['connect_timeout']],
            ['-e', $query],
        );
    }

    /**
     * Resolve the configured source connection, validating it exists.
     *
     * @return array<string, mixed>
     */
    protected function connection(SyncSource $source): array
    {
        $conn = config("sync.connections.{$source->connection}");

        if (! is_array($conn) || empty($conn['host'])) {
            throw new RuntimeException("Sync connection [{$source->connection}] is not configured.");
        }

        return $conn;
    }

    /**
     * Resolve (and ensure) the TSV output path for a source.
     */
    protected function outputPath(SyncSource $source): string
    {
        $dir = rtrim(config('sync.output_path'), '/');

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return "{$dir}/{$source->target_table}.tsv";
    }

    /**
     * Open a new running sync log for the source.
     */
    protected function startLog(SyncSource $source): SyncLog
    {
        return $source->logs()->create([
            'source_table' => $source->source_table,
            'target_table' => $source->target_table,
            'status' => SyncLog::STATUS_RUNNING,
            'started_at' => Carbon::now(),
        ]);
    }
}
