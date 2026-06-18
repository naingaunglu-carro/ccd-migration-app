<?php

namespace App\Services\DataSync\Resolvers;

use App\Contracts\Sync\ImportResolver;
use App\Models\SyncSource;
use Illuminate\Support\Facades\Schema;

/**
 * Convention-based resolver used when a source defines no resolver_class.
 *
 *  - table:    derived from the source name (e.g. "dealer.statuses" → "dealer_statuses")
 *  - uniqueBy: "source_id"
 *  - map:      identity, filtered to the target table's real columns
 *
 * To use it, write the source query to output columns that match the target
 * table's column names (alias as needed), e.g.
 *   select id as source_id, name, created_at as source_created_at from statuses
 */
class DefaultResolver implements ImportResolver
{
    /** @var list<string>|null */
    private ?array $targetColumns = null;

    public function __construct(private readonly SyncSource $source) {}

    public function table(): string
    {
        return strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '_', $this->source->name));
    }

    public function uniqueBy(): string|array
    {
        return 'source_id';
    }

    public function map(array $row, SyncSource $source): ?array
    {
        // Keep only keys that are real columns on the target table.
        return array_intersect_key($row, array_flip($this->targetColumns()));
    }

    /**
     * @return list<string>
     */
    private function targetColumns(): array
    {
        return $this->targetColumns ??= Schema::getColumnListing($this->table());
    }
}
