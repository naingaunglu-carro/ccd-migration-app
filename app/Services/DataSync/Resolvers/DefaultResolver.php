<?php

namespace App\Services\DataSync\Resolvers;

use App\Contracts\Sync\ImportResolver;
use App\Models\SyncSource;
use Illuminate\Support\Facades\Schema;

/**
 * Convention-based resolver used when a source defines no resolver_class.
 *
 *  - uniqueBy: "source_id"
 *  - map:      identity, filtered to the target table's real columns
 *
 * To use it, write the source query to output columns that match the target
 * table's column names (alias as needed), e.g.
 *   select id as source_id, name, created_at as source_created_at from statuses
 */
class DefaultResolver implements ImportResolver
{
    /** @var array<string, list<string>> */
    private array $columnCache = [];

    public function uniqueBy(): string|array
    {
        return 'source_id';
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
