<?php

namespace App\Services\DataSync\Resolvers;

use App\Contracts\Sync\ImportResolver;
use App\Models\SyncSource;

/**
 * Resolver for the dealer.statuses source.
 *
 * Owns the upsert key and the mapping from the query's output columns to the
 * target columns (with light normalisation). The target table comes from the
 * source's target_table.
 */
class DealerStatusResolver implements ImportResolver
{
    public function uniqueBy(): string|array
    {
        return 'source_id';
    }

    public function map(array $row, SyncSource $source): ?array
    {
        // Return null to drop a row (e.g. soft-deleted / invalid source records).
        return [
            'source_id' => $row['id'] ?? null,
            'name' => isset($row['name']) ? strtolower(trim($row['name'])) : null,
            'display_name' => isset($row['display_name']) ? trim($row['display_name']) : null,
            'source_created_at' => $row['created_at'] ?? null,
            'source_updated_at' => $row['updated_at'] ?? null,
        ];
    }
}
