<?php

use App\Http\Controllers\SyncController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(auth()->check() ? 'sync.index' : 'login'))->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    // Data sync UI — the whole tool requires login.
    Route::get('sync', [SyncController::class, 'index'])->name('sync.index');
    Route::post('sync/{source}/download', [SyncController::class, 'download'])->name('sync.download');
    Route::post('sync/downloads/{download}/import', [SyncController::class, 'import'])->name('sync.import');
});

require __DIR__.'/settings.php';
