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
        Schema::table('sync_sources', function (Blueprint $table) {
            // Explicit key range, so a chunked download can skip the
            // MIN(key)/MAX(key) lookup entirely on huge/filtered tables.
            $table->string('key_min')->nullable()->after('key_column');
            $table->string('key_max')->nullable()->after('key_min');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sync_sources', function (Blueprint $table) {
            $table->dropColumn(['key_min', 'key_max']);
        });
    }
};
