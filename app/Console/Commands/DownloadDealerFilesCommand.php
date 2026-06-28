<?php

namespace App\Console\Commands;

use App\Jobs\DownloadDealerFiles;
use App\Services\DataSync\DealerFileDownloadService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Download the physical objects recorded in dealer_files from a cloud disk
 * (s3) to a target disk. The table can hold 100M+ rows, so the work is sliced
 * into fixed id-ranges that are either streamed inline or — far better at
 * scale — dispatched as one job per slice across many queue workers.
 */
class DownloadDealerFilesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'dealer:download-files
        {--source-disk=s3 : Disk to read objects from}
        {--target-disk=local : Disk to write objects to}
        {--key-prefix= : Path prefix prepended to "{id}/{file_name}"}
        {--chunk=2000 : dealer_files rows per slice (one job each when --queue)}
        {--from-id= : Lowest dealer_files.id to include (default: table min)}
        {--to-id= : Highest dealer_files.id to include (default: table max)}
        {--collection= : Only files whose collection_name matches}
        {--model-type= : Only files whose model_type matches}
        {--file-name= : Only files whose file_name matches exactly (e.g. "Owner-IC.pdf")}
        {--since= : Only files with updated_at >= this datetime}
        {--overwrite : Re-download even if the object already exists on the target}
        {--queue= : Queue name to dispatch slices to (omit to run inline)}';

    /**
     * @var string
     */
    protected $description = 'Stream dealer_files objects from S3 to a target disk (resumable, chunked)';

    public function handle(DealerFileDownloadService $service): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $sourceDisk = (string) $this->option('source-disk');
        $targetDisk = (string) $this->option('target-disk');
        $keyPrefix = (string) ($this->option('key-prefix') ?? '');

        $options = [
            'collection' => $this->option('collection'),
            'model_type' => $this->option('model-type'),
            'file_name' => $this->option('file-name'),
            'since' => $this->option('since'),
            'overwrite' => (bool) $this->option('overwrite'),
        ];

        $min = $this->option('from-id') !== null
            ? (int) $this->option('from-id')
            : (int) DB::table('dealer_files')->min('id');
        $max = $this->option('to-id') !== null
            ? (int) $this->option('to-id')
            : (int) DB::table('dealer_files')->max('id');

        if ($max < $min) {
            $this->info('No dealer_files rows to process.');

            return self::SUCCESS;
        }

        $slices = (int) (intdiv($max - $min, $chunk) + 1);

        // --queue: fan the table out into one job per slice. The dispatcher stays
        // O(1) memory — it never reads the table, only walks id windows.
        if ($queue = $this->option('queue')) {
            for ($from = $min; $from <= $max; $from += $chunk) {
                DownloadDealerFiles::dispatch(
                    $from, min($from + $chunk - 1, $max),
                    $sourceDisk, $targetDisk, $keyPrefix, $options,
                )->onQueue($queue);
            }

            $this->info("Dispatched {$slices} slice(s) to queue \"{$queue}\" (ids {$min}–{$max}, chunk {$chunk}).");
            $this->line('Run workers to process: php artisan queue:work --queue='.$queue);

            return self::SUCCESS;
        }

        // Inline: stream each slice in this process, accumulating stats.
        $this->info("Streaming dealer_files {$min}–{$max} ({$slices} slice(s)) from \"{$sourceDisk}\" to \"{$targetDisk}\"...");
        $bar = $this->output->createProgressBar($slices);
        $bar->start();

        $totals = ['seen' => 0, 'copied' => 0, 'skipped' => 0, 'missing' => 0, 'failed' => 0, 'bytes' => 0];

        for ($from = $min; $from <= $max; $from += $chunk) {
            $stats = $service->downloadRange(
                $from, min($from + $chunk - 1, $max),
                $sourceDisk, $targetDisk, $keyPrefix, $options,
            );

            foreach ($totals as $key => $_) {
                $totals[$key] += $stats[$key];
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info(sprintf(
            'Done: %d seen, %d copied, %d skipped, %d missing, %d failed (%s).',
            $totals['seen'], $totals['copied'], $totals['skipped'],
            $totals['missing'], $totals['failed'], $this->humanBytes($totals['bytes']),
        ));

        return $totals['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Format a byte count for the summary line.
     */
    protected function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2).' '.$units[$i];
    }
}
