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
        Schema::table('dealer_files', function (Blueprint $table) {
            $table->string('tag_name')->nullable()->after('generated_conversions');
            $table->index('tag_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dealer_files', function (Blueprint $table) {
            $table->dropIndex(['tag_name']);
            $table->dropColumn('tag_name');
        });
    }
};
