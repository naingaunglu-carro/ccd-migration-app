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
        Schema::create('sync_sources', function (Blueprint $table) {
            $table->id();
            $table->string('group')->default('Default');
            $table->string('name');
            $table->string('display_name');
            $table->string('connection');
            $table->text('query');
            $table->string('target_table');
            $table->string('resolver_class')->nullable();
            $table->string('queue')->nullable();
            $table->unsignedInteger('chunk_size')->nullable(); // keyset-paginate the export when set
            $table->string('key_column')->nullable(); // keyset cursor column; defaults to "id"
            $table->string('folder_path')->nullable();
            $table->string('file_name')->nullable();
            $table->timestamp('last_downloaded_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique('name');
            $table->index(['group', 'display_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_sources');
    }
};
