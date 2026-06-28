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
        Schema::create('dealer_statuses', function (Blueprint $table) {
            // Imported columns — mirror the source `statuses` table verbatim.
            $table->unsignedBigInteger('id')->primary();
            $table->string('name');
            $table->string('display_name');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();

            // Local bookkeeping — stamped by the import pipeline.
            $table->timestamp('local_created_at')->nullable();
            $table->timestamp('local_updated_at')->nullable();
            $table->timestamp('local_synced_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dealer_statuses');
    }
};
