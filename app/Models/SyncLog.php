<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $sync_source_id
 * @property string $source_table
 * @property string $target_table
 * @property string $status
 * @property int $rows_read
 * @property int $rows_inserted
 * @property int $rows_updated
 * @property int $rows_failed
 * @property string|null $file_path
 * @property string|null $error_message
 * @property string|null $triggered_by
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'sync_source_id',
    'source_table',
    'target_table',
    'status',
    'rows_read',
    'rows_inserted',
    'rows_updated',
    'rows_failed',
    'file_path',
    'error_message',
    'triggered_by',
    'started_at',
    'finished_at',
])]
class SyncLog extends Model
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rows_read' => 'integer',
            'rows_inserted' => 'integer',
            'rows_updated' => 'integer',
            'rows_failed' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * The source configuration this run belongs to.
     *
     * @return BelongsTo<SyncSource, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(SyncSource::class, 'sync_source_id');
    }
}
