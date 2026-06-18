<?php

namespace App\Services\DataSync;

use App\Enums\Sync\SyncStatus;
use App\Models\SyncDownload;
use App\Models\SyncImport;
use App\Models\SyncSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SyncImportService
{
    /**
     * Part 2 — PROCESS: parse a downloaded file and upsert it into the target.
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

        $import = $download->imports()->create([
            'sync_source_id' => $source->id,
            'status' => SyncStatus::RUNNING,
            'started_at' => Carbon::now(),
        ]);

        try {
            $parsed = $this->parse($download, $source);
            $stats = $this->load($source, $parsed['rows']);

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
     * Parse a download file into rows keyed by target column, honouring file_type.
     *
     * @return array{rows: list<array<string, string|null>>, skipped: int}
     */
    public function parse(SyncDownload $download, SyncSource $source): array
    {
        $isCsv = $download->file_type === 'csv';
        $handle = fopen($download->file_path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Unable to read file: {$download->file_path}");
        }

        $rows = [];
        $skipped = 0;
        $headers = null;
        $key = $source->targetKey();

        try {
            while (($values = $this->readRecord($handle, $isCsv)) !== null) {
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

                    $row[$target] = $this->normalize($values[$i] ?? null, $isCsv);
                }

                // A row with no upsert key can't be loaded — count it as skipped.
                if (! isset($row[$key]) || $row[$key] === null || $row[$key] === '') {
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
     * Upsert parsed rows into the target table, stamping last_synced_at.
     *
     * @param  list<array<string, string|null>>  $rows
     * @return array{read: int, inserted: int, updated: int}
     */
    public function load(SyncSource $source, array $rows): array
    {
        if ($rows === []) {
            return ['read' => 0, 'inserted' => 0, 'updated' => 0];
        }

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

                isset($existing[(string) $row[$key]]) ? $updated++ : $inserted++;
            }

            $updateColumns = array_values(array_diff(array_keys($payload[0]), [$key]));

            DB::table($source->target_table)->upsert($payload, [$key], $updateColumns);
        }

        return ['read' => count($rows), 'inserted' => $inserted, 'updated' => $updated];
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
