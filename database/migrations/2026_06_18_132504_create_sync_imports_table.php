<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Part 2 — PROCESS: one row per import run; consumes a downloaded file.
        Schema::create('sync_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_download_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sync_source_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending'); // pending|running|completed|failed
            $table->unsignedInteger('rows_read')->default(0);
            $table->unsignedInteger('rows_inserted')->default(0);
            $table->unsignedInteger('rows_updated')->default(0);
            $table->unsignedInteger('rows_skipped')->default(0); // bad/unmapped rows
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['sync_source_id', 'status']);
            $table->index('sync_download_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_imports');
    }
};
