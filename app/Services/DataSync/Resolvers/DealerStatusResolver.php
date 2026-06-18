<?php

namespace App\Services\DataSync\Resolvers;

use App\Contracts\Sync\ImportResolver;
use App\Models\SyncSource;

/**
 * Example custom resolver for the dealer.statuses source.
 *
 * Demonstrates per-row data manipulation before the upsert: it trims every
 * value and normalises `name` to lowercase. Swap in real business logic here.
 */
class DealerStatusResolver implements ImportResolver
{
    public function resolve(array $row, array $source, SyncSource $syncSource): ?array
    {
        foreach ($row as $key => $value) {
            if (is_string($value)) {
                $row[$key] = trim($value);
            }
        }

        if (isset($row['name']) && is_string($row['name'])) {
            $row['name'] = strtolower($row['name']);
        }

        // Return null here to drop a row (e.g. soft-deleted / invalid source records).
        return $row;
    }
}
