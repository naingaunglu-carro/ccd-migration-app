<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $connection
 * @property string $source_table
 * @property string $target_table
 * @property array $columns
 * @property string $source_key
 * @property bool $is_active
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'name',
    'connection',
    'source_table',
    'target_table',
    'columns',
    'source_key',
    'is_active',
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
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
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
}
