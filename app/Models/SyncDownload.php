<?php

namespace App\Models;

use App\Enums\Sync\SyncStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Part 1 — EXTRACT: a single download/export run that owns a file artifact.
 *
 * @property int $id
 * @property int $sync_source_id
 * @property string $connection
 * @property string $query
 * @property string $file_disk
 * @property string|null $file_path
 * @property string|null $file_name
 * @property string|null $file_type
 * @property int|null $file_size
 * @property string|null $checksum
 * @property int|null $row_count
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property string|null $error_message
 * @property SyncStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'sync_source_id',
    'connection',
    'query',
    'file_disk',
    'file_path',
    'file_name',
    'file_type',
    'file_size',
    'checksum',
    'row_count',
    'started_at',
    'finished_at',
    'error_message',
    'status',
])]
class SyncDownload extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SyncStatus::class,
            'file_size' => 'integer',
            'row_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SyncSource, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(SyncSource::class, 'sync_source_id');
    }

    /**
     * @return HasMany<SyncImport, $this>
     */
    public function imports(): HasMany
    {
        return $this->hasMany(SyncImport::class);
    }

    /**
     * @return HasOne<SyncImport, $this>
     */
    public function latestImport(): HasOne
    {
        return $this->hasOne(SyncImport::class)->latestOfMany();
    }
}
