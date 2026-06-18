<?php

namespace App\Console\Commands;

use App\Models\SyncSource;
use App\Services\DataSync\DataSyncService;
use Illuminate\Console\Command;

class SyncRun extends Command
{
    /**
     * @var string
     */
    protected $signature = 'sync:run {source : The sync source name, e.g. "dealer.statuses"}';

    /**
     * @var string
     */
    protected $description = 'Export a source table and upsert it into its local landing table';

    public function handle(DataSyncService $service): int
    {
        $source = SyncSource::where('name', $this->argument('source'))->first();

        if (! $source) {
            $this->error("Unknown sync source: {$this->argument('source')}");

            return self::FAILURE;
        }

        $this->info("Syncing {$source->display_name} ({$source->source_table} → {$source->target_table})...");

        $log = $service->sync($source);

        $this->info("Done: {$log->rows_read} read, {$log->rows_inserted} inserted, {$log->rows_updated} updated.");

        return self::SUCCESS;
    }
}
