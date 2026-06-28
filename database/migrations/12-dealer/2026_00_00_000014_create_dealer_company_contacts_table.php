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
        Schema::create('dealer_company_contacts', function (Blueprint $table) {
            $table->bigIncrements('sync_id');
            $table->unsignedBigInteger('id')->unique();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('group_id')->nullable();
            $table->unsignedBigInteger('country_id')->nullable();
            $table->unsignedBigInteger('province_id')->nullable();
            $table->unsignedBigInteger('city_id')->nullable();
            $table->string('name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('unique_entity_number')->nullable();
            $table->string('tax_identification_number')->nullable();
            $table->string('national_identity_number')->nullable();
            $table->date('date_of_incorporation')->nullable();
            $table->json('additional_data')->nullable();
            $table->json('search_cache')->nullable();
            $table->string('search_cache_status')->nullable();
            $table->timestamp('search_cache_updated_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamp('sync_created_at')->nullable();
            $table->timestamp('sync_updated_at')->nullable();
            $table->timestamp('sync_last_synced_at')->nullable();
            $table->index('group_id');
            $table->index('country_id');
            $table->index('email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dealer_company_contacts');
    }
};
