<?php

namespace App\Contracts\Sync;

use App\Models\SyncSource;

interface ImportResolver
{
    /**
     * Transform a parsed row before it is upserted into the target table.
     *
     * @param  array<string, string|null>  $row  row keyed by TARGET column (after column mapping)
     * @param  array<string, string|null>  $source  raw row keyed by SOURCE column (pre-mapping)
     * @return array<string, mixed>|null final row to upsert, or null to skip the row
     */
    public function resolve(array $row, array $source, SyncSource $syncSource): ?array;
}
