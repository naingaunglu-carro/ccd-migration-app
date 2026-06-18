<?php

namespace App\Jobs;

use App\Models\SyncDownload;
use App\Services\DataSync\SyncImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessSyncImport implements ShouldQueue
{
    use Queueable;

    /**
     * Attempts before the job is marked failed. The upsert is idempotent, so
     * retrying after a transient failure is safe.
     */
    public int $tries = 3;

    /**
     * Seconds the job may run — large imports need well beyond the default 60s.
     */
    public int $timeout = 3600;

    public function __construct(public readonly SyncDownload $download) {}

    /**
     * Seconds to wait between retries.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(SyncImportService $service): void
    {
        $service->import($this->download);
    }
}
