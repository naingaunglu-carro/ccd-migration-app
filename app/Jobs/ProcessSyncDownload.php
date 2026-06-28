<?php

namespace App\Jobs;

use App\Enums\Sync\SyncStatus;
use App\Models\SyncSource;
use App\Services\DataSync\SyncDownloadService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Throwable;

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

    /**
     * Mark the in-flight download as failed. The service already does this for
     * caught exceptions, but a timeout / hard kill skips that path — this
     * backstop ensures a stuck "running" row is always resolved to "failed".
     */
    public function failed(?Throwable $e): void
    {
        $this->source->downloads()
            ->where('status', SyncStatus::RUNNING)
            ->latest('id')
            ->first()
            ?->forceFill([
                'status' => SyncStatus::FAILED,
                'error_message' => $e?->getMessage() ?? 'Download job failed.',
                'finished_at' => Carbon::now(),
            ])->save();
    }
}
