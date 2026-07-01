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

        $source   = $download->source;
        $resolver = $source->resolver();

        $import = $download->imports()->create([
            'sync_source_id' => $source->id,
            'target_table'   => $source->target_table,
            'resolver_class' => $source->resolver_class,
            'status'         => SyncStatus::RUNNING,
            'started_at'     => Carbon::now(),
        ]);

        try {
            $stats = $this->process($download, $source, $resolver);

            $source->forceFill(['last_synced_at' => Carbon::now()])->save();

            $import->forceFill([
                'status'        => SyncStatus::COMPLETED,
                'rows_read'     => $stats['read'],
                'rows_inserted' => $stats['inserted'],
                'rows_updated'  => $stats['updated'],
                'rows_skipped'  => $stats['skipped'],
                'finished_at'   => Carbon::now(),
            ])->save();
        } catch (\Throwable $e) {
            $import->forceFill([
                'status'        => SyncStatus::FAILED,
                'error_message' => $e->getMessage(),
                'finished_at'   => Carbon::now(),
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
        $keys  = array_values((array) $resolver->uniqueBy());

        // Query-builder upsert doesn't manage these — stamp the ones the table has.
        // These are the local bookkeeping columns; imported created_at/updated_at
        // flow through as ordinary data and are never auto-stamped here.
        $stamps = array_values(array_filter(
            ['sync_created_at', 'sync_updated_at', 'sync_last_synced_at'],
            fn ($column) => Schema::hasColumn($table, $column),
        ));

        $handle = fopen($download->file_path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Unable to read file: {$download->file_path}");
        }

        $before  = DB::table($table)->count();
        $headers = null;
        $columns = null;
        $idIndex = false;
        $read    = 0;
        $skipped = 0;
        $dropped = 0;
        $batch   = [];

        try {
            while (($values = $this->readRecord($handle, $isCsv, $columns)) !== null) {
                if ($headers === null) {
                    $headers = $values; // query output column names
                    $columns = count($headers);
                    $idIndex = array_search('id', $headers, true);

                    continue;
                }

                // A field count that doesn't match the header means the row could
                // not be cleanly reconstructed (e.g. a value held literal tabs in an
                // un-escaped export) — skip it rather than import shifted columns.
                if (count($values) !== $columns) {
                    $skipped++;

                    continue;
                }

                // The source `id` is always a numeric PK; a non-numeric one means
                // a value shifted into its slot (ambiguous row) — skip it.
                if ($idIndex !== false) {
                    $id = $values[$idIndex] ?? '';

                    if ($id !== '' && $id !== 'NULL' && $id !== '\N' && ! ctype_digit($id)) {
                        $skipped++;

                        continue;
                    }
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
                    $dropped += $this->flush($table, $keys, $stamps, $batch);
                    $batch = [];
                }
            }

            if ($batch !== []) {
                $dropped += $this->flush($table, $keys, $stamps, $batch);
            }
        } finally {
            fclose($handle);
        }

        $inserted  = max(0, DB::table($table)->count() - $before);
        $persisted = max(0, $read - $dropped);

        return ['read' => $read, 'inserted' => $inserted, 'updated' => max(0, $persisted - $inserted), 'skipped' => $skipped + $dropped];
    }

    /**
     * Upsert one batch, stamping local columns (sync_created_at is insert-only).
     * Returns the number of rows that could not be persisted (dropped).
     *
     * If the bulk upsert throws (one malformed row — e.g. a shifted column from an
     * ambiguous un-escaped export — poisons the whole statement), it retries the
     * batch row by row so only the offending rows are dropped.
     *
     * @param  list<string>  $keys
     * @param  list<string>  $stamps
     * @param  list<array<string, mixed>>  $batch
     */
    protected function flush(string $table, array $keys, array $stamps, array $batch): int
    {
        $now = Carbon::now();

        foreach ($batch as &$row) {
            foreach ($stamps as $column) {
                // Don't override a value the resolver already supplied.
                $row[$column] ??= $now;
            }
        }
        unset($row);

        // Update everything except the key(s) and sync_created_at (preserved on conflict).
        $updateColumns = array_values(array_diff(array_keys($batch[0]), $keys, ['sync_created_at']));

        try {
            DB::table($table)->upsert($batch, $keys, $updateColumns);

            return 0;
        } catch (\Throwable $e) {
            $failed = 0;

            foreach ($batch as $row) {
                try {
                    DB::table($table)->upsert([$row], $keys, $updateColumns);
                } catch (\Throwable $e2) {
                    $failed++;
                }
            }

            return $failed;
        }
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
     * For TSV, $columns (the header width) lets us heal rows that were written
     * with literal newlines inside a value (e.g. a multi-line `notes` column from
     * an un-escaped mysql export): physical lines are merged back — rejoined with
     * the newline that split them — until the row has its full column count.
     *
     * @param  resource  $handle
     *
     * @return list<string|null>|null
     */
    protected function readRecord($handle, bool $isCsv, ?int $columns = null): ?array
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

        $line   = rtrim($line, "\r\n");
        $fields = explode("\t", $line);

        // Header row (or unknown width): take the single physical line as-is.
        if ($columns === null) {
            return $fields;
        }

        // A short row means a value held a literal newline that split it across
        // lines — pull in continuation lines (restoring the newline) until whole.
        while (count($fields) < $columns) {
            $next = fgets($handle);

            if ($next === false) {
                break; // EOF mid-row — return what we have.
            }

            $line .= "\n" . rtrim($next, "\r\n");
            $fields = explode("\t", $line);
        }

        return $fields;
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

        // mysql --batch emits \N for NULL; an un-escaped (--raw) export emits the
        // literal "NULL". An empty cell is also treated as null (landing columns
        // are nullable, and bool/int columns reject ""). Treat all three as null.
        if ($value === '' || $value === '\N' || $value === 'NULL') {
            return null;
        }

        // mysql --batch escapes special chars inside values; reverse it so
        // multi-line text (e.g. notes) is restored intact. strtr replaces in a
        // single pass (longest match wins, no re-scan), so "\\" → "\" doesn't
        // re-trigger the "\n"/"\t" rules.
        return strtr($value, ['\\\\' => '\\', '\\t' => "\t", '\\n' => "\n", '\\0' => "\0"]);
    }
}
