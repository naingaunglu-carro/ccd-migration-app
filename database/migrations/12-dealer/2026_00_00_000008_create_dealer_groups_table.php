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
        Schema::create('dealer_groups', function (Blueprint $table) {
            $table->bigIncrements('sync_id');
            $table->unsignedBigInteger('id')->unique();
            $table->integer('level')->nullable();
            $table->unsignedBigInteger('group_id')->nullable();
            $table->unsignedBigInteger('make_id')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('display_name')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_internal')->nullable();
            $table->boolean('is_subsidiary')->nullable();
            $table->unsignedBigInteger('country_id')->nullable();
            $table->unsignedBigInteger('province_id')->nullable();
            $table->unsignedBigInteger('city_id')->nullable();
            $table->string('location_text')->nullable();
            $table->string('address')->nullable();
            $table->string('business_letter_address')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('size_of_showroom')->nullable();
            $table->string('company_registration_number')->nullable();
            $table->string('sst_registration_number')->nullable();
            $table->string('business_registration_number')->nullable();
            $table->string('tax_identification_number')->nullable();
            $table->string('contact_person')->nullable();
            $table->string('contact_number')->nullable();
            $table->boolean('display_bid_count')->nullable();
            $table->json('preference')->nullable();
            $table->json('whitelist')->nullable();
            $table->json('auction_meta')->nullable();
            $table->json('location_coordinates')->nullable();
            $table->json('social_data')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->unsignedBigInteger('relation_manager_id')->nullable();
            $table->json('working_hours')->nullable();
            $table->unsignedBigInteger('membership_tier_id')->nullable();
            $table->timestamp('sync_created_at')->nullable();
            $table->timestamp('sync_updated_at')->nullable();
            $table->timestamp('sync_last_synced_at')->nullable();
            $table->index('name');
            $table->index('country_id');
            $table->index('group_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dealer_groups');
    }
};
