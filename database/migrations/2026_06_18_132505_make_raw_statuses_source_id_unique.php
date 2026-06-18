<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The importer upserts on source_id (ON CONFLICT), which Postgres only
     * allows against a unique constraint — promote the plain index to unique.
     */
    public function up(): void
    {
        Schema::table('raw_statuses', function (Blueprint $table) {
            $table->dropIndex(['source_id']);
            $table->unique('source_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('raw_statuses', function (Blueprint $table) {
            $table->dropUnique(['source_id']);
            $table->index('source_id');
        });
    }
};
