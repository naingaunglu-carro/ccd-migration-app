<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only lookup over dealer_files. Prints the S3 object key
 * ("{key-prefix}/{id}/{file_name}") for matching rows so they can be
 * fetched by hand from the AWS Console / CloudShell when S3 is not reachable
 * locally. Mirrors the filters of dealer:download-files but copies nothing.
 */
class FindDealerFilesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'dealer:find-files
        {--file-name= : Match file_name exactly (e.g. "Owner-IC.pdf")}
        {--collection= : Only files whose collection_name matches}
        {--model-type= : Only files whose model_type matches}
        {--since= : Only files with updated_at >= this datetime}
        {--from-id= : Lowest dealer_files.id to include}
        {--to-id= : Highest dealer_files.id to include}
        {--key-prefix= : Path prefix prepended to the id/file_name key}
        {--limit=100 : Max rows to print (0 for no limit)}
        {--keys-only : Print only the S3 keys, one per line (pipe-friendly)}';

    /**
     * @var string
     */
    protected $description = 'List dealer_files rows and their S3 object keys (read-only)';

    public function handle(): int
    {
        $keyPrefix = trim((string) ($this->option('key-prefix') ?? ''), '/');
        $keysOnly = (bool) $this->option('keys-only');
        $limit = (int) $this->option('limit');

        $query = DB::table('dealer_files')
            ->when($this->option('file-name'), fn ($q, $v) => $q->where('file_name', $v))
            ->when($this->option('collection'), fn ($q, $v) => $q->where('collection_name', $v))
            ->when($this->option('model-type'), fn ($q, $v) => $q->where('model_type', $v))
            ->when($this->option('since'), fn ($q, $v) => $q->where('updated_at', '>=', $v))
            ->when($this->option('from-id') !== null, fn ($q) => $q->where('id', '>=', (int) $this->option('from-id')))
            ->when($this->option('to-id') !== null, fn ($q) => $q->where('id', '<=', (int) $this->option('to-id')))
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $rows = $query->get(['id', 'file_name', 'collection_name', 'model_type', 'size']);

        if ($rows->isEmpty()) {
            $this->warn('No matching dealer_files rows.');

            return self::SUCCESS;
        }

        if ($keysOnly) {
            foreach ($rows as $row) {
                if ($key = $this->objectKey($row, $keyPrefix)) {
                    $this->line($key);
                }
            }

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'file_name', 'collection', 'model_type', 'size', 's3_key'],
            $rows->map(fn ($row) => [
                $row->id,
                $row->file_name,
                $row->collection_name,
                $row->model_type,
                $row->size,
                $this->objectKey($row, $keyPrefix) ?? '(unresolvable)',
            ]),
        );

        $this->info("{$rows->count()} row(s) shown.");

        return self::SUCCESS;
    }

    /**
     * Build the S3 object key for a row, matching DealerFileDownloadService.
     * Returns null when the row lacks the columns to form a key.
     */
    protected function objectKey(object $row, string $keyPrefix): ?string
    {
        if (empty($row->id) || empty($row->file_name)) {
            return null;
        }

        $prefix = $keyPrefix === '' ? '' : $keyPrefix.'/';

        return $prefix.$row->id.'/'.$row->file_name;
    }
}
