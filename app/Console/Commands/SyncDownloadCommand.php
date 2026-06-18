<?php

namespace App\Console\Commands;

use App\Models\SyncSource;
use App\Services\DataSync\SyncDownloadService;
use Illuminate\Console\Command;

class SyncDownloadCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'sync:download {source : The sync source name, e.g. "dealer.statuses"}';

    /**
     * @var string
     */
    protected $description = 'Part 1 — export a source table into a file';

    public function handle(SyncDownloadService $service): int
    {
        $source = SyncSource::where('name', $this->argument('source'))->first();

        if (! $source) {
            $this->error("Unknown sync source: {$this->argument('source')}");

            return self::FAILURE;
        }

        $this->info("Downloading {$source->display_name} (connection: {$source->connection})...");

        $download = $service->download($source);

        $this->info("Done [#{$download->id}]: {$download->row_count} rows → {$download->file_path}");
        $this->line("Next: php artisan sync:import {$download->id}");

        return self::SUCCESS;
    }
}
