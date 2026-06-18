<?php

namespace App\Services\DataSync\Resolvers;

use App\Contracts\Sync\ImportResolver;
use App\Models\SyncSource;

/**
 * Pass-through resolver used when a source defines no custom resolver_class.
 */
class DefaultImportResolver implements ImportResolver
{
    public function resolve(array $row, array $source, SyncSource $syncSource): ?array
    {
        return $row;
    }
}
