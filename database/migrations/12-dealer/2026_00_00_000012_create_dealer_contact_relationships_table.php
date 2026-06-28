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
        Schema::create('dealer_contact_relationships', function (Blueprint $table) {
            $table->bigIncrements('sync_id');
            $table->unsignedBigInteger('id')->unique();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedBigInteger('children_id')->nullable();
            $table->string('relation')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('sync_created_at')->nullable();
            $table->timestamp('sync_updated_at')->nullable();
            $table->timestamp('sync_last_synced_at')->nullable();
            $table->index('parent_id');
            $table->index('children_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dealer_contact_relationships');
    }
};
