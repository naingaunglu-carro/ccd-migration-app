<?php

namespace Database\Seeders;

use App\Models\SyncSource;
use App\Services\DataSync\Resolvers\DealerStatusResolver;
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
                'group' => 'Dealer',
                'connection' => 'dealer',
                'source_table' => 'statuses',
                'target_table' => 'raw_statuses',
                'source_key' => 'id',
                // folder_path null → default config('sync.output_path')/raw_statuses
                'folder_path' => null,
                'file_name' => 'dealer_status_{{timestamp}}',
                'resolver_class' => DealerStatusResolver::class,
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
