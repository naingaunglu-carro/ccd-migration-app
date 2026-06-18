<?php

namespace App\Services\DataSync;

use App\Contracts\Sync\ImportResolver;
use App\Enums\Sync\SyncStatus;
use App\Models\SyncDownload;
use App\Models\SyncImport;
use App\Models\SyncSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class SyncImportService
{
    /**
     * Part 2 — PROCESS: parse a downloaded file and upsert it via the resolver.
     */
    public function import(SyncDownload $download): SyncImport
    {
        if ($download->status !== SyncStatus::COMPLETED) {
            throw new RuntimeException('Cannot import a download that has not completed.');
        }

        if (! $download->file_path || ! is_file($download->file_path)) {
            throw new RuntimeException("Download file is missing: {$download->file_path}");
        }

        $source = $download->source;
        $resolver = $source->resolver();

        $import = $download->imports()->create([
            'sync_source_id' => $source->id,
            'resolver_class' => $source->resolver_class,
            'status' => SyncStatus::RUNNING,
            'started_at' => Carbon::now(),
        ]);

        try {
            $parsed = $this->parse($download, $source, $resolver);
            $stats = $this->load($resolver->table(), $resolver->uniqueBy(), $parsed['rows']);

            $source->forceFill(['last_synced_at' => Carbon::now()])->save();

            $import->forceFill([
                'status' => SyncStatus::COMPLETED,
                'rows_read' => $stats['read'],
                'rows_inserted' => $stats['inserted'],
                'rows_updated' => $stats['updated'],
                'rows_skipped' => $parsed['skipped'],
                'finished_at' => Carbon::now(),
            ])->save();
        } catch (\Throwable $e) {
            $import->forceFill([
                'status' => SyncStatus::FAILED,
                'error_message' => $e->getMessage(),
                'finished_at' => Carbon::now(),
            ])->save();

            throw $e;
        }

        return $import->fresh();
    }

    /**
     * Parse a download file, mapping each row to a target row via the resolver.
     *
     * @return array{rows: list<array<string, mixed>>, skipped: int}
     */
    public function parse(SyncDownload $download, SyncSource $source, ImportResolver $resolver): array
    {
        $isCsv = $download->file_type === 'csv';
        $handle = fopen($download->file_path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Unable to read file: {$download->file_path}");
        }

        $rows = [];
        $skipped = 0;
        $headers = null;
        $keys = (array) $resolver->uniqueBy();

        try {
            while (($values = $this->readRecord($handle, $isCsv)) !== null) {
                if ($headers === null) {
                    $headers = $values; // query output column names

                    continue;
                }

                $sourceRow = [];

                foreach ($headers as $i => $column) {
                    $sourceRow[$column] = $this->normalize($values[$i] ?? null, $isCsv);
                }

                $row = $resolver->map($sourceRow, $source);

                // Resolver-dropped, or missing any part of the upsert key → skip.
                if ($row === null || ! $this->hasKeys($row, $keys)) {
                    $skipped++;

                    continue;
                }

                $rows[] = $row;
            }
        } finally {
            fclose($handle);
        }

        return ['rows' => $rows, 'skipped' => $skipped];
    }

    /**
     * Upsert mapped rows into the target table, stamping last_synced_at when present.
     *
     * @param  string|list<string>  $uniqueBy
     * @param  list<array<string, mixed>>  $rows
     * @return array{read: int, inserted: int, updated: int}
     */
    public function load(string $table, string|array $uniqueBy, array $rows): array
    {
        if ($rows === []) {
            return ['read' => 0, 'inserted' => 0, 'updated' => 0];
        }

        $keys = array_values((array) $uniqueBy);
        $stampSynced = Schema::hasColumn($table, 'last_synced_at');
        $now = Carbon::now();

        $before = DB::table($table)->count();

        foreach (array_chunk($rows, 500) as $chunk) {
            if ($stampSynced) {
                $chunk = array_map(fn ($row) => $row + ['last_synced_at' => $now], $chunk);
            }

            $updateColumns = array_values(array_diff(array_keys($chunk[0]), $keys));

            DB::table($table)->upsert($chunk, $keys, $updateColumns);
        }

        $read = count($rows);
        $inserted = max(0, DB::table($table)->count() - $before);

        return ['read' => $read, 'inserted' => $inserted, 'updated' => max(0, $read - $inserted)];
    }

    /**
     * Whether a row carries a non-empty value for every upsert key column.
     *
     * @param  array<string, mixed>  $row
     * @param  list<string>  $keys
     */
    protected function hasKeys(array $row, array $keys): bool
    {
        foreach ($keys as $key) {
            if (! isset($row[$key]) || $row[$key] === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Read one record from the file: CSV via fgetcsv, TSV via tab split.
     * Returns null at EOF.
     *
     * @param  resource  $handle
     * @return list<string|null>|null
     */
    protected function readRecord($handle, bool $isCsv): ?array
    {
        if ($isCsv) {
            $values = fgetcsv($handle, escape: '');

            if ($values === false) {
                return null;
            }

            // fgetcsv yields [null] for a blank line — treat as empty record.
            return $values === [null] ? [] : $values;
        }

        $line = fgets($handle);

        if ($line === false) {
            return null;
        }

        return explode("\t", rtrim($line, "\r\n"));
    }

    /**
     * Normalise a raw cell to a value or null per the file format.
     */
    protected function normalize(?string $value, bool $isCsv): ?string
    {
        if ($value === null) {
            return null;
        }

        // mysql --batch emits \N for NULL; pg CSV emits an empty field for NULL.
        if ($isCsv) {
            return $value === '' ? null : $value;
        }

        return $value === '\N' ? null : $value;
    }
}
