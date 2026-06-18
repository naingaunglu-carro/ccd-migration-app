<?php

namespace App\Console\Commands;

use App\Jobs\ProcessSyncImport;
use App\Models\SyncDownload;
use App\Services\DataSync\SyncImportService;
use Illuminate\Console\Command;

class SyncImportCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'sync:import {download : The sync_downloads id to process}
        {--queue : Dispatch the import to the queue instead of running inline}';

    /**
     * @var string
     */
    protected $description = 'Part 2 — process a downloaded file into its landing table';

    public function handle(SyncImportService $service): int
    {
        $download = SyncDownload::with('source')->find($this->argument('download'));

        if (! $download) {
            $this->error("Unknown download id: {$this->argument('download')}");

            return self::FAILURE;
        }

        if ($this->option('queue')) {
            ProcessSyncImport::dispatch($download);
            $this->info("Queued import for download #{$download->id}.");

            return self::SUCCESS;
        }

        $this->info("Importing download #{$download->id} for {$download->source->display_name}...");

        $import = $service->import($download);

        $this->info("Done: {$import->rows_read} read, {$import->rows_inserted} inserted, "
            ."{$import->rows_updated} updated, {$import->rows_skipped} skipped.");

        return self::SUCCESS;
    }
}
