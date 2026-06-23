<?php

namespace App\Jobs;

use App\Models\SyncSource;
use App\Services\DataSync\SyncDownloadService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessSyncDownload implements ShouldQueue
{
    use Queueable;

    /**
     * One attempt — a partial export shouldn't be silently retried.
     */
    public int $tries = 1;

    /**
     * Seconds the job may run — large exports take well beyond the default 60s.
     */
    public int $timeout = 21600; // 6 hours

    public function __construct(public readonly SyncSource $source) {}

    public function handle(SyncDownloadService $service): void
    {
        $service->download($this->source);
    }
}
