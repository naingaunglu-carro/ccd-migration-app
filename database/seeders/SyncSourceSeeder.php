<?php

namespace Database\Seeders;

use App\Models\SyncSource;
use Illuminate\Database\Seeder;

class SyncSourceSeeder extends Seeder
{
    /**
     * Seed the configured data-sync sources.
     */
    public function run(): void
    {
        SyncSource::updateOrCreate(
            ['name' => 'dealer.statuses'],
            [
                'display_name' => 'Dealer Statuses',
                'connection' => 'dealer',
                'source_table' => 'statuses',
                'target_table' => 'raw_statuses',
                'source_key' => 'id',
                // source column => target (raw_statuses) column
                'columns' => [
                    'id' => 'source_id',
                    'name' => 'name',
                    'display_name' => 'display_name',
                    'created_at' => 'source_created_at',
                    'updated_at' => 'source_updated_at',
                ],
            ],
        );
    }
}
