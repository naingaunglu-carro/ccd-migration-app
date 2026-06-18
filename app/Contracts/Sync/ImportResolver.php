<?php

namespace App\Contracts\Sync;

use App\Models\SyncSource;

interface ImportResolver
{
    /**
     * The unique column(s) used as the upsert key (ON CONFLICT / ON DUPLICATE KEY).
     *
     * The target table itself comes from the source's target_table.
     *
     * @return string|list<string>
     */
    public function uniqueBy(): string|array;

    /**
     * Map a parsed source row (keyed by the query's output columns) into a
     * target-table row. Return null to skip the row.
     *
     * @param  array<string, string|null>  $row
     * @return array<string, mixed>|null
     */
    public function map(array $row, SyncSource $source): ?array;
}
