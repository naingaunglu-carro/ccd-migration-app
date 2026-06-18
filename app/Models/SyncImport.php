<?php

namespace App\Models;

use App\Enums\Sync\SyncStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Part 2 — PROCESS: a single import run that consumes a downloaded file.
 *
 * @property int $id
 * @property int $sync_download_id
 * @property int $sync_source_id
 * @property string|null $resolver_class
 * @property int $rows_read
 * @property int $rows_inserted
 * @property int $rows_updated
 * @property int $rows_skipped
 * @property string|null $error_message
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property SyncStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'sync_download_id',
    'sync_source_id',
    'resolver_class',
    'rows_read',
    'rows_inserted',
    'rows_updated',
    'rows_skipped',
    'error_message',
    'started_at',
    'finished_at',
    'status',
])]
class SyncImport extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SyncStatus::class,
            'rows_read' => 'integer',
            'rows_inserted' => 'integer',
            'rows_updated' => 'integer',
            'rows_skipped' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SyncDownload, $this>
     */
    public function download(): BelongsTo
    {
        return $this->belongsTo(SyncDownload::class, 'sync_download_id');
    }

    /**
     * @return BelongsTo<SyncSource, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(SyncSource::class, 'sync_source_id');
    }
}
