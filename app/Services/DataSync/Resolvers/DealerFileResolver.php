<?php

namespace App\Services\DataSync\Resolvers;

use App\Contracts\Sync\ImportResolver;
use App\Models\SyncSource;

/**
 * Resolver for the dealer.files source — maps the raw `files` columns onto the
 * dealer_files table (source id/timestamps land in the source_* columns).
 */
class DealerFileResolver implements ImportResolver
{
    public function uniqueBy(): string|array
    {
        return 'source_id';
    }

    public function map(array $row, SyncSource $source): ?array
    {
        return [
            'source_id' => $row['id'] ?? null,
            'uuid' => $row['uuid'] ?? null,
            'slug' => $row['slug'] ?? null,
            'model_id' => $row['model_id'] ?? null,
            'model_type' => $row['model_type'] ?? null,
            'collection_name' => $row['collection_name'] ?? null,
            'name' => $row['name'] ?? null,
            'file_name' => $row['file_name'] ?? null,
            'mime_type' => $row['mime_type'] ?? null,
            'disk' => $row['disk'] ?? null,
            'conversions_disk' => $row['conversions_disk'] ?? null,
            'size' => $row['size'] ?? null,
            'original_size' => $row['original_size'] ?? null,
            'source_created_at' => $row['created_at'] ?? null,
            'source_updated_at' => $row['updated_at'] ?? null,
        ];
    }
}
