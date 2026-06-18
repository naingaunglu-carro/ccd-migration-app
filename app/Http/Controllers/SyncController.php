<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessSyncImport;
use App\Models\SyncDownload;
use App\Models\SyncSource;
use App\Services\DataSync\SyncDownloadService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SyncController extends Controller
{
    /**
     * List sync sources, each with its recent downloads and import results.
     */
    public function index(): Response
    {
        $sources = SyncSource::query()
            ->with(['downloads' => fn ($q) => $q->latest()->limit(10)->with('latestImport')])
            ->orderBy('group')
            ->orderBy('display_name')
            ->get()
            ->map(fn (SyncSource $source) => [
                'id' => $source->id,
                'display_name' => $source->display_name,
                'group' => $source->group,
                'connection' => $source->connection,
                'query' => $source->query,
                'target_table' => $source->target_table,
                'resolver_class' => $source->resolver_class ? class_basename($source->resolver_class) : null,
                'last_downloaded_at' => $source->last_downloaded_at?->toIso8601String(),
                'last_synced_at' => $source->last_synced_at?->toIso8601String(),
                'downloads' => $source->downloads->map(fn (SyncDownload $d) => [
                    'id' => $d->id,
                    'status' => $d->status->value,
                    'file_type' => $d->file_type,
                    'file_name' => $d->file_name,
                    'row_count' => $d->row_count,
                    'file_size' => $d->file_size,
                    'error_message' => $d->error_message,
                    'created_at' => $d->created_at?->toIso8601String(),
                    'latest_import' => $d->latestImport ? [
                        'status' => $d->latestImport->status->value,
                        'rows_inserted' => $d->latestImport->rows_inserted,
                        'rows_updated' => $d->latestImport->rows_updated,
                        'rows_skipped' => $d->latestImport->rows_skipped,
                        'error_message' => $d->latestImport->error_message,
                        'finished_at' => $d->latestImport->finished_at?->toIso8601String(),
                    ] : null,
                ]),
            ]);

        return Inertia::render('Sync/Index', [
            'sources' => $sources,
        ]);
    }

    /**
     * Part 1 — trigger a download (export) for a source.
     */
    public function download(SyncSource $source, SyncDownloadService $service): RedirectResponse
    {
        try {
            $download = $service->download($source);

            Inertia::flash('toast', [
                'type' => 'success',
                'message' => __(':name downloaded — :rows rows.', [
                    'name' => $source->display_name,
                    'rows' => $download->row_count,
                ]),
            ]);
        } catch (\Throwable $e) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('Download failed: :error', ['error' => $e->getMessage()]),
            ]);
        }

        return to_route('sync.index');
    }

    /**
     * Part 2 — queue an import (process) for a downloaded file.
     *
     * Imports can be large, so they run on the queue rather than blocking the
     * request. The result shows up on the download's latest import once done.
     */
    public function import(SyncDownload $download): RedirectResponse
    {
        ProcessSyncImport::dispatch($download);

        Inertia::flash('toast', [
            'type' => 'info',
            'message' => __('Import queued for :name.', [
                'name' => $download->source->display_name,
            ]),
        ]);

        return to_route('sync.index');
    }
}
