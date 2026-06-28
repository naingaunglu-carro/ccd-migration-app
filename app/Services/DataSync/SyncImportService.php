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
     * Rows upserted per batch while streaming an import.
     */
    private const BATCH_SIZE = 500;

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
            'target_table' => $source->target_table,
            'resolver_class' => $source->resolver_class,
            'status' => SyncStatus::RUNNING,
            'started_at' => Carbon::now(),
        ]);

        try {
            $stats = $this->process($download, $source, $resolver);

            $source->forceFill(['last_synced_at' => Carbon::now()])->save();

            $import->forceFill([
                'status' => SyncStatus::COMPLETED,
                'rows_read' => $stats['read'],
                'rows_inserted' => $stats['inserted'],
                'rows_updated' => $stats['updated'],
                'rows_skipped' => $stats['skipped'],
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
     * Stream the download file, mapping + upserting in batches so memory stays
     * bounded regardless of row count (handles 100M+ row files).
     *
     * @return array{read: int, inserted: int, updated: int, skipped: int}
     */
    public function process(SyncDownload $download, SyncSource $source, ImportResolver $resolver): array
    {
        $isCsv = $download->file_type === 'csv';
        $table = $source->target_table;
        $keys = array_values((array) $resolver->uniqueBy());

        // Query-builder upsert doesn't manage these — stamp the ones the table has.
        // These are the local bookkeeping columns; imported created_at/updated_at
        // flow through as ordinary data and are never auto-stamped here.
        $stamps = array_values(array_filter(
            ['local_created_at', 'local_updated_at', 'local_synced_at'],
            fn ($column) => Schema::hasColumn($table, $column),
        ));

        $handle = fopen($download->file_path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Unable to read file: {$download->file_path}");
        }

        $before = DB::table($table)->count();
        $headers = null;
        $read = 0;
        $skipped = 0;
        $batch = [];

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

                if ($row === null || ! $this->hasKeys($row, $keys)) {
                    $skipped++;

                    continue;
                }

                $batch[] = $row;
                $read++;

                if (count($batch) >= self::BATCH_SIZE) {
                    $this->flush($table, $keys, $stamps, $batch);
                    $batch = [];
                }
            }

            if ($batch !== []) {
                $this->flush($table, $keys, $stamps, $batch);
            }
        } finally {
            fclose($handle);
        }

        $inserted = max(0, DB::table($table)->count() - $before);

        return ['read' => $read, 'inserted' => $inserted, 'updated' => max(0, $read - $inserted), 'skipped' => $skipped];
    }

    /**
     * Upsert one batch, stamping local columns (local_created_at is insert-only).
     *
     * @param  list<string>  $keys
     * @param  list<string>  $stamps
     * @param  list<array<string, mixed>>  $batch
     */
    protected function flush(string $table, array $keys, array $stamps, array $batch): void
    {
        $now = Carbon::now();

        foreach ($batch as &$row) {
            foreach ($stamps as $column) {
                // Don't override a value the resolver already supplied.
                $row[$column] ??= $now;
            }
        }
        unset($row);

        // Update everything except the key(s) and local_created_at (preserved on conflict).
        $updateColumns = array_values(array_diff(array_keys($batch[0]), $keys, ['local_created_at']));

        DB::table($table)->upsert($batch, $keys, $updateColumns);
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

        // pg CSV emits an empty field for NULL.
        if ($isCsv) {
            return $value === '' ? null : $value;
        }

        // mysql --batch emits \N for NULL; with --raw (escaping off) it's the
        // literal "NULL". Treat both as null.
        return ($value === '\N' || $value === 'NULL') ? null : $value;
    }
}
