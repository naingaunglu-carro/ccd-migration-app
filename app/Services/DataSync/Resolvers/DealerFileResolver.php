<?php

namespace App\Services\DataSync\Resolvers;

use App\Contracts\Sync\ImportResolver;
use App\Models\SyncSource;

/**
 * Resolver for the dealer.files source — maps the raw `files` columns onto the
 * dealer_files table (imported columns keep their natural names; the table's own
 * local_* columns are stamped by the import pipeline).
 */
class DealerFileResolver implements ImportResolver
{
    public function uniqueBy(): string|array
    {
        return 'id';
    }

    public function map(array $row, SyncSource $source): ?array
    {
        return [
            'id' => $row['id'] ?? null,
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
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }
}
