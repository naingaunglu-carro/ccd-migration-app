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
        Schema::create('sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_source_id')->nullable()
                ->constrained('sync_sources')->nullOnDelete();
            $table->string('source_table');             // e.g. "statuses"
            $table->string('target_table');             // e.g. "raw_statuses"
            $table->string('status')->default('running'); // running | completed | failed
            $table->unsignedInteger('rows_read')->default(0);
            $table->unsignedInteger('rows_inserted')->default(0);
            $table->unsignedInteger('rows_updated')->default(0);
            $table->unsignedInteger('rows_failed')->default(0);
            $table->string('file_path')->nullable();    // the .tsv that was loaded
            $table->text('error_message')->nullable();
            $table->string('triggered_by')->nullable(); // username/email who ran the sync
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['source_table', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_logs');
    }
};
