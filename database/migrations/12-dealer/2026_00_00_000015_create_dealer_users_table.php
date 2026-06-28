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
        Schema::create('dealer_users', function (Blueprint $table) {
            $table->bigIncrements('sync_id');
            $table->unsignedBigInteger('id')->unique();
            $table->string('sso_user_id')->nullable();
            $table->unsignedBigInteger('country_id')->nullable();
            $table->unsignedBigInteger('province_id')->nullable();
            $table->unsignedBigInteger('city_id')->nullable();
            $table->unsignedBigInteger('location_id')->nullable();
            $table->string('name')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('display_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('secondary_phone')->nullable();
            $table->string('company_name')->nullable();
            $table->string('company_registration_number')->nullable();
            $table->string('address')->nullable();
            $table->string('address_2')->nullable();
            $table->string('postcode')->nullable();
            $table->string('national_identity_number')->nullable();
            $table->string('tax_identification_number')->nullable();
            $table->string('gender')->nullable();
            $table->timestamp('date_of_birth')->nullable();
            $table->integer('win_auctions_count')->nullable();
            $table->integer('auctions_count')->nullable();
            $table->integer('bids_count')->nullable();
            $table->integer('reviews_count')->nullable();
            $table->integer('reviewers_count')->nullable();
            $table->json('reviews_rating')->nullable();
            $table->integer('point')->nullable();
            $table->string('membership_tier')->nullable();
            $table->integer('purchase_now_quota')->nullable();
            $table->string('fcm_uuid')->nullable();
            $table->json('fcm_uuids')->nullable();
            $table->string('password')->nullable();
            $table->boolean('is_active')->nullable();
            $table->boolean('is_verified')->nullable();
            $table->integer('is_blacklist')->nullable();
            $table->boolean('has_request_verification')->nullable();
            $table->timestamp('first_login')->nullable();
            $table->timestamp('last_login')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('first_login_app')->nullable();
            $table->timestamp('last_login_app')->nullable();
            $table->json('preferences')->nullable();
            $table->json('notification_preferences')->nullable();
            $table->json('auction_preferences')->nullable();
            $table->json('verification_data')->nullable();
            $table->json('additional_data')->nullable();
            $table->json('social_data')->nullable();
            $table->json('pubnub_data')->nullable();
            $table->json('whitelist')->nullable();
            $table->json('search_cache')->nullable();
            $table->timestamp('search_cache_updated_at')->nullable();
            $table->string('search_cache_status')->nullable();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->string('remember_token')->nullable();
            $table->boolean('app_allow_notification')->nullable();
            $table->boolean('app_allow_sound')->nullable();
            $table->timestamp('password_changed_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->string('language')->nullable();
            $table->json('locale')->nullable();
            $table->string('dealer_category')->nullable();
            $table->string('business_grade')->nullable();
            $table->string('activity_grade')->nullable();
            $table->text('extra_notes')->nullable();
            $table->boolean('is_auction')->nullable();
            $table->boolean('is_genie')->nullable();
            $table->timestamp('read_messages_at')->nullable();
            $table->string('facebook_account_id')->nullable();
            $table->string('facebook_access_token')->nullable();
            $table->string('lark_open_id')->nullable();
            $table->string('iid')->nullable();
            $table->unsignedBigInteger('active_group_id')->nullable();
            $table->unsignedBigInteger('onboarder_id')->nullable();
            $table->unsignedBigInteger('partnership_executive_id')->nullable();
            $table->json('app_preferences')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->unsignedBigInteger('creator_id')->nullable();
            $table->unsignedBigInteger('assignee_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('note_updated_at')->nullable();
            $table->unsignedBigInteger('last_note_by')->nullable();
            $table->boolean('is_manager')->nullable();
            $table->boolean('is_high_risk_buyer')->nullable();
            $table->string('stripe_id')->nullable();
            $table->string('pm_type')->nullable();
            $table->string('pm_last_four', 4)->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('sync_created_at')->nullable();
            $table->timestamp('sync_updated_at')->nullable();
            $table->timestamp('sync_last_synced_at')->nullable();
            $table->index('email');
            $table->index('phone');
            $table->index('sso_user_id');
            $table->index('organization_id');
            $table->index('active_group_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dealer_users');
    }
};
