<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $display_name
 * @property string $connection
 * @property string $source_table
 * @property string $target_table
 * @property array<string, string> $columns
 * @property string $source_key
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'name',
    'display_name',
    'connection',
    'source_table',
    'target_table',
    'columns',
    'source_key',
    'last_synced_at',
])]
class SyncSource extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'columns' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * The target column used as the upsert key (where the source key lands).
     */
    public function targetKey(): string
    {
        return $this->columns[$this->source_key] ?? 'source_id';
    }

    /**
     * Sync runs recorded for this source.
     *
     * @return HasMany<SyncLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(SyncLog::class);
    }

    /**
     * Most recent sync run for this source.
     *
     * @return HasOne<SyncLog, $this>
     */
    public function latestLog(): HasOne
    {
        return $this->hasOne(SyncLog::class)->latestOfMany();
    }
}
