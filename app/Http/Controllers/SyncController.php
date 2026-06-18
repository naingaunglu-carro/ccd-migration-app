<?php

namespace App\Http\Controllers;

use App\Models\SyncSource;
use App\Services\DataSync\DataSyncService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SyncController extends Controller
{
    /**
     * List the configured sync sources with their latest run.
     */
    public function index(): Response
    {
        $sources = SyncSource::query()
            ->with('latestLog')
            ->orderBy('display_name')
            ->get()
            ->map(fn (SyncSource $source) => [
                'id' => $source->id,
                'name' => $source->name,
                'display_name' => $source->display_name,
                'connection' => $source->connection,
                'source_table' => $source->source_table,
                'target_table' => $source->target_table,
                'columns' => array_keys($source->columns),
                'last_synced_at' => $source->last_synced_at?->toIso8601String(),
                'latest_log' => $source->latestLog ? [
                    'status' => $source->latestLog->status,
                    'rows_read' => $source->latestLog->rows_read,
                    'rows_inserted' => $source->latestLog->rows_inserted,
                    'rows_updated' => $source->latestLog->rows_updated,
                    'error_message' => $source->latestLog->error_message,
                    'finished_at' => $source->latestLog->finished_at?->toIso8601String(),
                ] : null,
            ]);

        return Inertia::render('Sync/Index', [
            'sources' => $sources,
        ]);
    }

    /**
     * Trigger a sync for the given source.
     */
    public function sync(SyncSource $source, DataSyncService $service): RedirectResponse
    {
        try {
            $log = $service->sync($source);

            Inertia::flash('toast', [
                'type' => 'success',
                'message' => __(':name synced — :inserted new, :updated updated.', [
                    'name' => $source->display_name,
                    'inserted' => $log->rows_inserted,
                    'updated' => $log->rows_updated,
                ]),
            ]);
        } catch (\Throwable $e) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('Sync failed: :error', ['error' => $e->getMessage()]),
            ]);
        }

        return to_route('sync.index');
    }
}
