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

        $download = $source->downloads()->create([
            'file_disk' => 'local',
            'file_type' => $fileType,
            'status' => SyncStatus::RUNNING,
            'started_at' => Carbon::now(),
        ]);

        try {
            [$absolute, $name] = $this->resolvePath($source, $download, $fileType);

            $this->run($source, $conn, $absolute);

            $download->forceFill([
                'status' => SyncStatus::COMPLETED,
                'file_path' => $absolute,
                'file_name' => $name,
                'file_size' => filesize($absolute) ?: null,
                'checksum' => hash_file('sha256', $absolute) ?: null,
                'row_count' => $this->countDataRows($absolute),
                'finished_at' => Carbon::now(),
            ])->save();
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
     * @param  array<string, mixed>  $conn
     */
    protected function run(SyncSource $source, array $conn, string $path): void
    {
        $process = new Process(
            $this->command($source, $conn),
            timeout: null,
            env: $this->env($conn),
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
                "{$conn['driver']} export failed: ".trim($process->getErrorOutput() ?: $process->getOutput())
            );
        }
    }

    /**
     * Build the export command (argv) for the connection's driver.
     *
     * @param  array<string, mixed>  $conn
     * @return list<string>
     */
    protected function command(SyncSource $source, array $conn): array
    {
        $driver = $conn['driver'];
        $binary = config("sync.binaries.{$driver}", $driver);
        $flags = (array) config("sync.drivers.{$driver}.flags", []);
        $columns = implode(', ', array_keys($source->columns));
        $select = "select {$columns} from {$source->source_table}";

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
        $dir = $source->folder_path
            ?: rtrim((string) config('sync.output_path'), '/')."/{$source->target_table}";
        $dir = rtrim($dir, '/');

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $pattern = $source->file_name ?: '{{timestamp}}_{{id}}';
        $name = $this->resolveFileName($pattern, $source, $download).".{$fileType}";

        return ["{$dir}/{$name}", $name];
    }

    /**
     * Expand placeholder tokens in a file-name template.
     */
    protected function resolveFileName(string $pattern, SyncSource $source, SyncDownload $download): string
    {
        $now = Carbon::now();

        return strtr($pattern, [
            '{{timestamp}}' => $now->format('Ymd_His'),
            '{{date}}' => $now->format('Ymd'),
            '{{table}}' => $source->source_table,
            '{{id}}' => (string) $download->id,
        ]);
    }

    /**
     * Count data rows in the export (total lines minus the header).
     */
    protected function countDataRows(string $path): int
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return 0;
        }

        $lines = 0;

        try {
            while (fgets($handle) !== false) {
                $lines++;
            }
        } finally {
            fclose($handle);
        }

        return max(0, $lines - 1);
    }
}
