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
        Schema::create('sync_downloads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_source_id')->constrained('sync_sources');
            $table->string('connection');
            $table->text('query');
            $table->string('file_disk');
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->string('file_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error_message')->nullable();
            $table->string('status');
            $table->timestamps();
            $table->index(['sync_source_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_downloads');
    }
};
