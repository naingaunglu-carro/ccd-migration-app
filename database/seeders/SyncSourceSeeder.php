<?php

namespace Database\Seeders;

use App\Concerns\ReadsJson;
use App\Models\SyncSource;
use Illuminate\Database\Seeder;

class SyncSourceSeeder extends Seeder
{
    use ReadsJson;

    /**
     * Seed the configured data-sync sources from database/seeders/data/sync_sources.json.
     */
    public function run(): void
    {
        $sources = $this->readJson(database_path('seeders/data/sync_sources.json'));

        foreach ($sources as $source) {
            SyncSource::updateOrCreate(
                ['name' => $source['name']],
                $source,
            );
        }
    }
}
