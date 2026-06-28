<?php

namespace App\Services\DataSync\Resolvers;

use App\Contracts\Sync\ImportResolver;
use App\Models\SyncSource;
use Illuminate\Support\Facades\Schema;

/**
 * Convention-based resolver used when a source defines no resolver_class.
 *
 *  - uniqueBy: "id"
 *  - map:      identity, filtered to the target table's real columns
 *
 * Imported columns keep their natural source names; the target table reserves
 * local_* columns for its own bookkeeping (stamped by SyncImportService). So
 * the source query just selects the raw columns, no aliasing, e.g.
 *   select id, name, created_at, updated_at, deleted_at from statuses
 */
class DefaultResolver implements ImportResolver
{
    /** @var array<string, list<string>> */
    private array $columnCache = [];

    public function uniqueBy(): string|array
    {
        return 'id';
    }

    public function map(array $row, SyncSource $source): ?array
    {
        // Keep only keys that are real columns on the target table.
        return array_intersect_key($row, array_flip($this->columnsFor($source->target_table)));
    }

    /**
     * @return list<string>
     */
    private function columnsFor(string $table): array
    {
        return $this->columnCache[$table] ??= Schema::getColumnListing($table);
    }
}
