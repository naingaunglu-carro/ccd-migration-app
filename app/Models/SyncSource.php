<?php

namespace App\Models;

use App\Contracts\Sync\ImportResolver;
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
     * The resolver owns the target table, upsert key, and column mapping, so a
     * source must declare a resolver_class before it can be imported.
     */
    public function resolver(): ImportResolver
    {
        $class = $this->resolver_class;

        if (empty($class)) {
            throw new InvalidArgumentException("Source [{$this->name}] has no resolver_class; an import resolver is required.");
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
