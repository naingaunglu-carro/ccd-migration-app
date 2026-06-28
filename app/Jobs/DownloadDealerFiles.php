<?php

namespace App\Jobs;

use App\Services\DataSync\DealerFileDownloadService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Copies one id-range slice of dealer_files objects from the source disk to the
 * target disk. The command fans the table out into many of these so the work
 * spreads across queue workers; each job is independent, idempotent, and safe
 * to retry (already-copied objects are skipped).
 */
class DownloadDealerFiles implements ShouldQueue
{
    use Queueable;

    /**
     * Transient S3/network errors are common at scale — retry with backoff.
     */
    public int $tries = 3;

    /**
     * Seconds between retries: 1m, 5m.
     *
     * @var list<int>
     */
    public array $backoff = [60, 300];

    /**
     * A slice may stream thousands of objects — give it room.
     */
    public int $timeout = 3600; // 1 hour

    /**
     * @param  array{since?:?string,collection?:?string,model_type?:?string,file_name?:?string,overwrite?:bool}  $options
     */
    public function __construct(
        public readonly int $idFrom,
        public readonly int $idTo,
        public readonly string $sourceDisk,
        public readonly string $targetDisk,
        public readonly string $keyPrefix = '',
        public readonly array $options = [],
    ) {}

    public function handle(DealerFileDownloadService $service): void
    {
        $service->downloadRange(
            $this->idFrom,
            $this->idTo,
            $this->sourceDisk,
            $this->targetDisk,
            $this->keyPrefix,
            $this->options,
        );
    }
}
