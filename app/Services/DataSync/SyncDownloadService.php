<?php

namespace App\Services\DataSync;

use App\Enums\Sync\SyncStatus;
use App\Models\SyncDownload;
use App\Models\SyncSource;
use Illuminate\Support\Carbon;
use RuntimeException;
use Symfony\Component\Process\Process;

class SyncDownloadService
{
    /**
     * Part 1 — EXTRACT: export the source table into a file and record the run.
     */
    public function download(SyncSource $source): SyncDownload
    {
        $conn     = $this->connection($source);
        $driver   = $conn['driver'];
        $fileType = config("sync.drivers.{$driver}.file_type", 'tsv');
        $select   = $this->select($source); // resolved query — snapshotted on the row

        $download = $source->downloads()->create([
            'connection' => $source->connection,
            'query'      => $select,
            'file_disk'  => 'local',
            'file_type'  => $fileType,
            'status'     => SyncStatus::RUNNING,
            'started_at' => Carbon::now(),
        ]);

        try {
            [$absolute, $name] = $this->resolvePath($source, $download, $fileType);

            $metrics = $source->chunk_size
                ? $this->runChunked($conn, $select, $absolute, $source->key_column ?: 'id', (int) $source->chunk_size, $fileType === 'csv')
                : $this->run($conn, $select, $absolute);

            $download->forceFill([
                'status'      => SyncStatus::COMPLETED,
                'file_path'   => $absolute,
                'file_name'   => $name,
                'file_size'   => $metrics['size'] ?: null,
                'checksum'    => $metrics['checksum'],
                'row_count'   => $metrics['row_count'],
                'finished_at' => Carbon::now(),
            ])->save();

            $source->forceFill(['last_downloaded_at' => Carbon::now()])->save();
        } catch (\Throwable $e) {
            $download->forceFill([
                'status'        => SyncStatus::FAILED,
                'error_message' => $e->getMessage(),
                'finished_at'   => Carbon::now(),
            ])->save();

            throw $e;
        }

        return $download->fresh();
    }

    /**
     * Run the driver-specific export, streaming stdout into the target file.
     *
     * Size, row count, and checksum are computed in this single streaming pass
     * so huge exports are never re-read from disk afterwards.
     *
     * @param  array<string, mixed>  $conn
     *
     * @return array{size: int, row_count: int, checksum: string|null}
     */
    protected function run(array $conn, string $select, string $path): array
    {
        $process = new Process(
            $this->command($select, $conn),
            timeout: null,
            env: $this->env($conn),
        );

        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException("Unable to open output file: {$path}");
        }

        $hash  = hash_init('sha256');
        $bytes = 0;
        $lines = 0;

        try {
            $process->run(function (string $type, string $buffer) use ($handle, $hash, &$bytes, &$lines) {
                if ($type === Process::OUT) {
                    fwrite($handle, $buffer);
                    hash_update($hash, $buffer);
                    $bytes += strlen($buffer);
                    $lines += substr_count($buffer, "\n");
                }
            });
        } finally {
            fclose($handle);
        }

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                "{$conn['driver']} export failed: " . trim($process->getErrorOutput() ?: $process->getOutput())
            );
        }

        return [
            'size'      => $bytes,
            'row_count' => max(0, $lines - 1), // total lines minus the header
            'checksum'  => $bytes > 0 ? hash_final($hash) : null,
        ];
    }

    /**
     * Keyset-paginated export: pull the table in chunks of `WHERE key > cursor
     * ORDER BY key LIMIT n`, appending each chunk into a single file. Each chunk
     * is a fast PK-index range scan on a fresh connection, so it never trips the
     * server's max_execution_time the way one giant SELECT does.
     *
     * @param  array<string, mixed>  $conn
     *
     * @return array{size: int, row_count: int, checksum: string|null}
     */
    protected function runChunked(array $conn, string $select, string $path, string $key, int $chunk, bool $isCsv): array
    {
        $main = fopen($path, 'wb');

        if ($main === false) {
            throw new RuntimeException("Unable to open output file: {$path}");
        }

        $hash     = hash_init('sha256');
        $keyIndex = $this->keyIndexFromSelect($select, $key);
        $tmp      = $path . '.part';
        $bytes    = 0;
        $rows     = 0;
        $cursor   = null;

        try {
            while (true) {
                $this->stream($conn, $this->chunkSql($select, $key, $cursor, $chunk), $tmp);

                [$partRows, $lastKey, $partBytes] = $this->appendChunk(
                    $tmp, $main, $hash, $isCsv, $keyIndex, $cursor === null,
                );

                $rows += $partRows;
                $bytes += $partBytes;

                // Stop on an empty/short chunk, or if the cursor fails to advance.
                if ($partRows < $chunk || $lastKey === null || $lastKey === $cursor) {
                    break;
                }

                $cursor = $lastKey;
            }
        } finally {
            fclose($main);
            @unlink($tmp);
        }

        return [
            'size'      => $bytes,
            'row_count' => $rows,
            'checksum'  => $rows > 0 ? hash_final($hash) : null,
        ];
    }

    /**
     * Run one export query, streaming stdout into a file (no metrics).
     *
     * @param  array<string, mixed>  $conn
     */
    protected function stream(array $conn, string $sql, string $path): void
    {
        $process = new Process($this->command($sql, $conn), timeout: null, env: $this->env($conn));
        $handle  = fopen($path, 'wb');

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
                "{$conn['driver']} export failed: " . trim($process->getErrorOutput() ?: $process->getOutput())
            );
        }
    }

    /**
     * Append a chunk file's data rows into the combined file (header only on the
     * first chunk), returning [rows, lastKeyValue, bytesWritten].
     *
     * @param  resource  $main
     *
     * @return array{0: int, 1: string|null, 2: int}
     */
    protected function appendChunk(string $tmp, $main, \HashContext $hash, bool $isCsv, int $keyIndex, bool $withHeader): array
    {
        $in = fopen($tmp, 'rb');

        if ($in === false) {
            return [0, null, 0];
        }

        $rows     = 0;
        $bytes    = 0;
        $lineNo   = 0;
        $lastLine = null;

        try {
            while (($line = fgets($in)) !== false) {
                $lineNo++;

                if ($lineNo === 1) {
                    if ($withHeader) {
                        fwrite($main, $line);
                        hash_update($hash, $line);
                        $bytes += strlen($line);
                    }

                    continue;
                }

                if (trim($line) === '') {
                    continue;
                }

                fwrite($main, $line);
                hash_update($hash, $line);
                $bytes += strlen($line);
                $rows++;
                $lastLine = $line;
            }
        } finally {
            fclose($in);
        }

        $lastKey = null;

        if ($lastLine !== null) {
            $line    = rtrim($lastLine, "\r\n");
            $fields  = $isCsv ? str_getcsv($line, escape: '') : explode("\t", $line);
            $value   = $fields[$keyIndex] ?? null;
            $lastKey = ($value === null || $value === '' || $value === '\N') ? null : $value;
        }

        return [$rows, $lastKey, $bytes];
    }

    /**
     * Build a keyset chunk query from the base SELECT.
     */
    protected function chunkSql(string $select, string $key, ?string $cursor, int $chunk): string
    {
        $where = '';

        if ($cursor !== null) {
            $value = is_numeric($cursor) ? $cursor : "'" . str_replace("'", "''", $cursor) . "'";
            // Extend the base query's WHERE with AND when it already filters,
            // otherwise open a fresh WHERE. Prevents a double-WHERE when the
            // source query carries its own filter (e.g. model_type/date bounds).
            $connector = preg_match('/\bwhere\b/i', $select) ? 'and' : 'where';
            $where = " {$connector} {$key} > {$value}";
        }

        return "{$select}{$where} order by {$key} asc limit {$chunk}";
    }

    /**
     * Locate the key column's position in the SELECT list (matching aliases).
     */
    protected function keyIndexFromSelect(string $select, string $key): int
    {
        if (! preg_match('/select\s+(.*?)\s+from\s/is', $select, $matches)) {
            return 0;
        }

        foreach (array_map('trim', explode(',', $matches[1])) as $i => $column) {
            if (preg_match('/\bas\s+["`]?([A-Za-z0-9_]+)["`]?$/i', $column, $alias)) {
                $name = $alias[1];
            } else {
                $name = preg_replace('/^.*\./', '', trim($column, '"`'));
            }

            if (strcasecmp((string) $name, $key) === 0) {
                return $i;
            }
        }

        return 0;
    }

    /**
     * Build the export command (argv) for the connection's driver.
     *
     * @param  array<string, mixed>  $conn
     *
     * @return list<string>
     */
    protected function command(string $select, array $conn): array
    {
        $driver = $conn['driver'];
        $binary = config("sync.binaries.{$driver}", $driver);
        $flags  = (array) config("sync.drivers.{$driver}.flags", []);

        return match ($driver) {
            'mysql' => array_merge(
                [$binary],
                ['-h', (string) $conn['host']],
                ['-P', (string) $conn['port']],
                ['-u', (string) $conn['username']],
                ['--database', (string) $conn['database']],
                $flags,
                ['--ssl-mode=' . $conn['ssl_mode']],
                ['--connect-timeout=' . $conn['connect_timeout']],
                ['-e', $select],
            ),
            'pgsql' => array_merge(
                [$binary],
                ['--host=' . $conn['host']],
                ['--port=' . $conn['port']],
                ['--username=' . $conn['username']],
                ['--dbname=' . $conn['database']],
                ['--no-psqlrc', '-v', 'ON_ERROR_STOP=1'],
                $flags,
                ['-c', "\\copy ({$select}) TO STDOUT WITH (FORMAT csv, HEADER true)"],
            ),
            default => throw new RuntimeException("Unsupported sync driver: {$driver}"),
        };
    }

    /**
     * Resolve the source's SELECT statement, expanding placeholders.
     */
    protected function select(SyncSource $source): string
    {
        if (empty($source->query)) {
            throw new RuntimeException("Source [{$source->name}] has no query to export.");
        }

        // Strip a trailing ";" — the pgsql path wraps this in \copy (...).
        $query = rtrim(trim($source->query), ';');

        return $this->resolvePlaceholders($query, $source);
    }

    /**
     * Expand query placeholders (e.g. {{last_synced_at}} for incremental pulls).
     */
    protected function resolvePlaceholders(string $query, SyncSource $source): string
    {
        $lastSynced = $source->last_synced_at?->toDateTimeString() ?? '1970-01-01 00:00:00';

        return strtr($query, [
            '{{last_synced_at}}' => $lastSynced,
        ]);
    }

    /**
     * Driver-specific environment (keeps the password off argv).
     *
     * @param  array<string, mixed>  $conn
     *
     * @return array<string, string>
     */
    protected function env(array $conn): array
    {
        return match ($conn['driver']) {
            'mysql' => ['MYSQL_PWD' => (string) $conn['password']],
            'pgsql' => [
                'PGPASSWORD'        => (string) $conn['password'],
                'PGSSLMODE'         => (string) ($conn['ssl_mode'] ?? 'prefer'),
                'PGCONNECT_TIMEOUT' => (string) ($conn['connect_timeout'] ?? 30),
            ],
            default => [],
        };
    }

    /**
     * Resolve the source connection config, validating it exists.
     *
     * @return array<string, mixed>
     */
    protected function connection(SyncSource $source): array
    {
        $conn = config("sync.connections.{$source->connection}");

        if (! is_array($conn) || empty($conn['host'])) {
            throw new RuntimeException("Sync connection [{$source->connection}] is not configured.");
        }

        $conn['driver'] ??= 'mysql';

        return $conn;
    }

    /**
     * Build (and ensure the directory for) the output file path.
     *
     * Honours the source's folder_path / file_name config, falling back to the
     * default output directory and a timestamped name.
     *
     * @return array{0: string, 1: string} [absolute path, file name]
     */
    protected function resolvePath(SyncSource $source, SyncDownload $download, string $fileType): array
    {
        $base = rtrim((string) config('sync.output_path'), '/');

        // Absolute folder_path is used as-is; a relative one nests under output_path;
        // none falls back to a per-source slug directory.
        $dir = match (true) {
            $source->folder_path && str_starts_with($source->folder_path, '/') => $source->folder_path,
            (bool) $source->folder_path                                        => "{$base}/" . trim($source->folder_path, '/'),
            default                                                            => "{$base}/{$source->target_table}",
        };
        $dir = rtrim($dir, '/');

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $pattern = $source->file_name ?: '{{timestamp}}_{{id}}';
        $name    = $this->resolveFileName($pattern, $download) . ".{$fileType}";

        return ["{$dir}/{$name}", $name];
    }

    /**
     * Expand placeholder tokens in a file-name template.
     */
    protected function resolveFileName(string $pattern, SyncDownload $download): string
    {
        $now = Carbon::now();

        return strtr($pattern, [
            '{{timestamp}}' => $now->format('Ymd_His'),
            '{{date}}'      => $now->format('Ymd'),
            '{{id}}'        => (string) $download->id,
        ]);
    }
}
