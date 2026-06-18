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
 * @property string $group
 * @property string $connection
 * @property string $source_table
 * @property string $target_table
 * @property array<string, string> $columns
 * @property string $source_key
 * @property string|null $folder_path
 * @property string|null $file_name
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'name',
    'display_name',
    'group',
    'connection',
    'source_table',
    'target_table',
    'columns',
    'source_key',
    'folder_path',
    'file_name',
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
     * Downloads (Part 1 — extract) recorded for this source.
     *
     * @return HasMany<SyncDownload, $this>
     */
    public function downloads(): HasMany
    {
        return $this->hasMany(SyncDownload::class);
    }

    /**
     * Most recent download for this source.
     *
     * @return HasOne<SyncDownload, $this>
     */
    public function latestDownload(): HasOne
    {
        return $this->hasOne(SyncDownload::class)->latestOfMany();
    }

    /**
     * Imports (Part 2 — process) recorded for this source.
     *
     * @return HasMany<SyncImport, $this>
     */
    public function imports(): HasMany
    {
        return $this->hasMany(SyncImport::class);
    }
}
