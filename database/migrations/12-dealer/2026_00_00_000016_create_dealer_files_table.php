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
        Schema::create('dealer_files', function (Blueprint $table) {
            $table->bigIncrements('sync_id');
            $table->unsignedBigInteger('id')->unique();
            $table->string('uuid', 36)->nullable();
            $table->string('slug')->nullable();
            $table->unsignedBigInteger('model_id')->nullable();
            $table->string('model_type')->nullable();
            $table->string('collection_name')->nullable();
            $table->string('name')->nullable();
            $table->string('file_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->string('disk')->nullable();
            $table->string('conversions_disk')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->bigInteger('original_size')->nullable();
            $table->json('manipulations')->nullable();
            $table->json('custom_properties')->nullable();
            $table->bigInteger('order_column')->nullable();
            $table->boolean('is_optimized')->nullable();
            $table->string('lark_code')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->json('responsive_images')->nullable();
            $table->json('generated_conversions')->nullable();
            $table->string('tag_name')->nullable();
            $table->timestamp('sync_created_at')->nullable();
            $table->timestamp('sync_updated_at')->nullable();
            $table->timestamp('sync_last_synced_at')->nullable();
            $table->index('model_id');
            $table->index('tag_name');
            $table->index(['model_id', 'model_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dealer_files');
    }
};
