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
        Schema::create('dealer_transactions', function (Blueprint $table) {
            $table->bigIncrements('sync_id');
            $table->timestamp('sync_created_at')->nullable();
            $table->timestamp('sync_updated_at')->nullable();
            $table->timestamp('sync_last_synced_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dealer_transactions');
    }
};
