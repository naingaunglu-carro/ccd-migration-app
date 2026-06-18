<?php

namespace App\Models;

use App\Contracts\Sync\ImportResolver;
use App\Services\DataSync\Resolvers\DefaultResolver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * @property int $id
 * @property string $group
 * @property string $name
 * @property string $display_name
 * @property string $connection
 * @property string $query
 * @property string $target_table
 * @property string|null $resolver_class
 * @property string|null $folder_path
 * @property string|null $file_name
 * @property Carbon|null $last_downloaded_at
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'group',
    'name',
    'display_name',
    'connection',
    'query',
    'target_table',
    'resolver_class',
    'folder_path',
    'file_name',
    'last_downloaded_at',
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
            'last_downloaded_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * Resolve the import resolver for this source.
     *
     * The resolver owns the target table, upsert key, and column mapping. When
     * no resolver_class is set, a convention-based DefaultResolver is used.
     */
    public function resolver(): ImportResolver
    {
        $class = $this->resolver_class;

        if (empty($class)) {
            return new DefaultResolver;
        }

        if (! class_exists($class)) {
            throw new InvalidArgumentException("Import resolver [{$class}] does not exist.");
        }

        $resolver = app($class);

        if (! $resolver instanceof ImportResolver) {
            throw new InvalidArgumentException("[{$class}] must implement ".ImportResolver::class.'.');
        }

        return $resolver;
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
