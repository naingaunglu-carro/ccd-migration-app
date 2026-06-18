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
            $table->id();

            $table->string('name');
            $table->string('display_name');
            $table->unsignedBigInteger('source_id');
            $table->timestamp('source_created_at')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamp('source_deleted_at')->nullable();

            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique('source_id');
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
