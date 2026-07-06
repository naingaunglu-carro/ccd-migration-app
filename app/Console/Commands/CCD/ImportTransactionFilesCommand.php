<?php

namespace App\Console\Commands\CCD;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Create or update dealer_files rows from a TSV produced by
 * ccd:download-transaction-files.
 *
 * Streams the file and upserts by `id` in fixed-size batches, so memory stays
 * flat regardless of file size. `tag_name` isn't part of the exported columns
 * — it's derived per row from custom_properties.document_default_tag_name.
 */
class ImportTransactionFilesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'ccd:import-transaction-files
        {file : Path to the TSV produced by ccd:download-transaction-files}
        {--chunk=1000 : Rows per upsert batch}';

    /**
     * @var string
     */
    protected $description = 'Create or update dealer_files rows from a TSV exported by ccd:download-transaction-files';

    private const TABLE = 'dealer_files';

    public function handle(): int
    {
        $path = $this->argument('file');

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $chunkSize = max(1, (int) $this->option('chunk'));
        $columns   = Schema::getColumnListing(self::TABLE);
        $stamps    = array_values(array_filter(
            ['sync_created_at', 'sync_updated_at', 'sync_last_synced_at'],
            fn ($column) => in_array($column, $columns, true),
        ));

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            $this->error("Unable to read file: {$path}");

            return self::FAILURE;
        }

        $before  = DB::table(self::TABLE)->count();
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

        $inserted = max(0, DB::table(self::TABLE)->count() - $before);
        $updated  = max(0, ($read - $skipped) - $inserted);

        $this->info("Done. {$read} read, {$inserted} inserted, {$updated} updated, {$skipped} skipped.");

        return self::SUCCESS;
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
