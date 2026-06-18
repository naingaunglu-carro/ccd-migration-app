<?php

namespace App\Contracts\Sync;

use App\Models\SyncSource;

interface ImportResolver
{
    /**
     * The target table this resolver upserts into.
     */
    public function table(): string;

    /**
     * The unique column(s) used as the upsert key (ON CONFLICT / ON DUPLICATE KEY).
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
