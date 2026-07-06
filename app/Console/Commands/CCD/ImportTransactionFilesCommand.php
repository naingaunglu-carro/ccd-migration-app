<?php

namespace App\Console\Commands\CCD;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Create or update dealer_files rows from TSV(s) produced by
 * ccd:download-transaction-files.
 *
 * Accepts either a single TSV file or a folder of them (e.g. the
 * {country}/{date}/ directory of dealer_files_batch_*.tsv files that
 * ccd:download-transaction-files writes) — folders are imported file by file,
 * in natural filename order, so batch_2 runs before batch_10.
 *
 * Streams each file and upserts by `id` in fixed-size batches, so memory
 * stays flat regardless of file size. `tag_name` isn't part of the exported
 * columns — it's derived per row from custom_properties.document_default_tag_name.
 */
class ImportTransactionFilesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'ccd:import-transaction-files
        {path : Path to a TSV produced by ccd:download-transaction-files, or a folder of them}
        {--chunk=1000 : Rows per upsert batch}';

    /**
     * @var string
     */
    protected $description = 'Create or update dealer_files rows from TSV(s) exported by ccd:download-transaction-files';

    private const TABLE = 'dealer_files';

    public function handle(): int
    {
        $path = $this->argument('path');

        $files = $this->resolveFiles($path);

        if ($files === null) {
            $this->error("Path not found: {$path}");

            return self::FAILURE;
        }

        if ($files === []) {
            $this->error("No .tsv files found in: {$path}");

            return self::FAILURE;
        }

        $chunkSize = max(1, (int) $this->option('chunk'));
        $columns   = Schema::getColumnListing(self::TABLE);
        $stamps    = array_values(array_filter(
            ['sync_created_at', 'sync_updated_at', 'sync_last_synced_at'],
            fn ($column) => in_array($column, $columns, true),
        ));

        $before      = DB::table(self::TABLE)->count();
        $totalRead    = 0;
        $totalSkipped = 0;

        foreach ($files as $file) {
            if (count($files) > 1) {
                $this->info("Importing {$file}…");
            }

            [$read, $skipped] = $this->importFile($file, $chunkSize, $columns, $stamps);

            $totalRead    += $read;
            $totalSkipped += $skipped;
        }

        $inserted = max(0, DB::table(self::TABLE)->count() - $before);
        $updated  = max(0, ($totalRead - $totalSkipped) - $inserted);

        $this->info("Done. {$totalRead} read, {$inserted} inserted, {$updated} updated, {$totalSkipped} skipped across " . count($files) . ' file(s).');

        return self::SUCCESS;
    }

    /**
     * Resolve the given path into an ordered list of TSV files.
     *
     * Null means the path doesn't exist; an empty array means it's a folder
     * with no .tsv files in it.
     *
     * @return list<string>|null
     */
    private function resolveFiles(string $path): ?array
    {
        if (is_file($path)) {
            return [$path];
        }

        if (! is_dir($path)) {
            return null;
        }

        $files = glob(rtrim($path, '/') . '/*.tsv') ?: [];
        natsort($files);

        return array_values($files);
    }

    /**
     * Stream one TSV file and upsert its rows in batches.
     *
     * @param  list<string>  $columns
     * @param  list<string>  $stamps
     * @return array{0: int, 1: int} [read, skipped]
     */
    private function importFile(string $path, int $chunkSize, array $columns, array $stamps): array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            $this->error("Unable to read file: {$path}");

            return [0, 0];
        }

        $headers = null;
        $width   = null;
        $idIndex = false;
        $read    = 0;
        $skipped = 0;
        $batch   = [];

        try {
            while (($line = fgets($handle)) !== false) {
                $line = rtrim($line, "\r\n");

                if ($headers === null) {
                    $headers = explode("\t", $line);
                    $width   = count($headers);
                    $idIndex = array_search('id', $headers, true);

                    continue;
                }

                $fields = explode("\t", $line);

                // A field count that doesn't match the header means the row could
                // not be cleanly reconstructed — skip rather than import shifted columns.
                if (count($fields) !== $width) {
                    $skipped++;

                    continue;
                }

                if ($idIndex !== false) {
                    $id = $fields[$idIndex] ?? '';

                    if ($id === '' || $id === '\N' || ! ctype_digit($id)) {
                        $skipped++;

                        continue;
                    }
                }

                $row = [];

                foreach ($headers as $i => $column) {
                    $row[$column] = $this->normalize($fields[$i] ?? null);
                }

                $mapped              = array_intersect_key($row, array_flip($columns));
                $mapped['tag_name']  = $this->tagName($row['custom_properties'] ?? null);

                $batch[] = $mapped;
                $read++;

                if (count($batch) >= $chunkSize) {
                    $this->flush($batch, $stamps);
                    $batch = [];
                }
            }

            if ($batch !== []) {
                $this->flush($batch, $stamps);
            }
        } finally {
            fclose($handle);
        }

        return [$read, $skipped];
    }

    /**
     * Upsert one batch by id, stamping sync_* columns (sync_created_at is insert-only).
     *
     * @param  list<array<string, mixed>>  $batch
     * @param  list<string>  $stamps
     */
    private function flush(array $batch, array $stamps): void
    {
        $now = now();

        foreach ($batch as &$row) {
            foreach ($stamps as $column) {
                $row[$column] ??= $now;
            }
        }
        unset($row);

        $keys          = ['id'];
        $updateColumns = array_values(array_diff(array_keys($batch[0]), $keys, ['sync_created_at']));

        DB::table(self::TABLE)->upsert($batch, $keys, $updateColumns);
    }

    /**
     * Reverse mysql --batch escaping and NULL markers.
     */
    private function normalize(?string $value): ?string
    {
        if ($value === null || $value === '' || $value === '\N' || $value === 'NULL') {
            return null;
        }

        return strtr($value, ['\\\\' => '\\', '\\t' => "\t", '\\n' => "\n", '\\0' => "\0"]);
    }

    /**
     * Pull custom_properties.document_default_tag_name out of the raw JSON cell.
     */
    private function tagName(?string $customProperties): ?string
    {
        if ($customProperties === null) {
            return null;
        }

        $decoded = json_decode($customProperties, true);

        if (! is_array($decoded)) {
            return null;
        }

        $tag = $decoded['document_default_tag_name'] ?? null;

        return is_string($tag) ? $tag : null;
    }
}
