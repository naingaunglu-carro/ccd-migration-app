<?php

use App\Http\Controllers\SyncController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

// Data sync UI — auth intentionally omitted for now.
Route::get('sync', [SyncController::class, 'index'])->name('sync.index');
Route::post('sync/{source}', [SyncController::class, 'sync'])->name('sync.run');

require __DIR__.'/settings.php';
