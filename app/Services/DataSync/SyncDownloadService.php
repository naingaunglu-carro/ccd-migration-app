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
        $conn = $this->connection($source);
        $driver = $conn['driver'];
        $fileType = config("sync.drivers.{$driver}.file_type", 'tsv');
        $select = $this->select($source); // resolved query — snapshotted on the row

        $download = $source->downloads()->create([
            'connection' => $source->connection,
            'query' => $select,
            'file_disk' => 'local',
            'file_type' => $fileType,
            'status' => SyncStatus::RUNNING,
            'started_at' => Carbon::now(),
        ]);

        try {
            [$absolute, $name] = $this->resolvePath($source, $download, $fileType);

            $metrics = $this->run($conn, $select, $absolute);

            $download->forceFill([
                'status' => SyncStatus::COMPLETED,
                'file_path' => $absolute,
                'file_name' => $name,
                'file_size' => $metrics['size'] ?: null,
                'checksum' => $metrics['checksum'],
                'row_count' => $metrics['row_count'],
                'finished_at' => Carbon::now(),
            ])->save();

            $source->forceFill(['last_downloaded_at' => Carbon::now()])->save();
        } catch (\Throwable $e) {
            $download->forceFill([
                'status' => SyncStatus::FAILED,
                'error_message' => $e->getMessage(),
                'finished_at' => Carbon::now(),
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

        $hash = hash_init('sha256');
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
                "{$conn['driver']} export failed: ".trim($process->getErrorOutput() ?: $process->getOutput())
            );
        }

        return [
            'size' => $bytes,
            'row_count' => max(0, $lines - 1), // total lines minus the header
            'checksum' => $bytes > 0 ? hash_final($hash) : null,
        ];
    }

    /**
     * Build the export command (argv) for the connection's driver.
     *
     * @param  array<string, mixed>  $conn
     * @return list<string>
     */
    protected function command(string $select, array $conn): array
    {
        $driver = $conn['driver'];
        $binary = config("sync.binaries.{$driver}", $driver);
        $flags = (array) config("sync.drivers.{$driver}.flags", []);

        return match ($driver) {
            'mysql' => array_merge(
                [$binary],
                ['-h', (string) $conn['host']],
                ['-P', (string) $conn['port']],
                ['-u', (string) $conn['username']],
                ['--database', (string) $conn['database']],
                $flags,
                ['--ssl-mode='.$conn['ssl_mode']],
                ['--connect-timeout='.$conn['connect_timeout']],
                ['-e', $select],
            ),
            'pgsql' => array_merge(
                [$binary],
                ['--host='.$conn['host']],
                ['--port='.$conn['port']],
                ['--username='.$conn['username']],
                ['--dbname='.$conn['database']],
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
     * @return array<string, string>
     */
    protected function env(array $conn): array
    {
        return match ($conn['driver']) {
            'mysql' => ['MYSQL_PWD' => (string) $conn['password']],
            'pgsql' => [
                'PGPASSWORD' => (string) $conn['password'],
                'PGSSLMODE' => (string) ($conn['ssl_mode'] ?? 'prefer'),
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
            (bool) $source->folder_path => "{$base}/".trim($source->folder_path, '/'),
            default => "{$base}/{$source->target_table}",
        };
        $dir = rtrim($dir, '/');

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $pattern = $source->file_name ?: '{{timestamp}}_{{id}}';
        $name = $this->resolveFileName($pattern, $download).".{$fileType}";

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
            '{{date}}' => $now->format('Ymd'),
            '{{id}}' => (string) $download->id,
        ]);
    }
}
