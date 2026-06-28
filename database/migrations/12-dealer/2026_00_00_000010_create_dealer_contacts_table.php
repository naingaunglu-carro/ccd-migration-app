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
        Schema::create('dealer_contacts', function (Blueprint $table) {
            $table->bigIncrements('sync_id');
            $table->unsignedBigInteger('id')->unique();
            $table->string('uuid')->nullable();
            $table->unsignedBigInteger('group_id')->nullable();
            $table->unsignedBigInteger('country_id')->nullable();
            $table->unsignedBigInteger('province_id')->nullable();
            $table->string('city_id')->nullable();
            $table->string('slug')->nullable();
            $table->string('towkay_uuid')->nullable();
            $table->string('name')->nullable();
            $table->string('display_name')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('phone')->nullable();
            $table->json('secondary_phone')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('address_2')->nullable();
            $table->string('postcode')->nullable();
            $table->string('national_identity_number')->nullable();
            $table->string('tax_identification_number')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->json('secondary_email')->nullable();
            $table->string('password')->nullable();
            $table->json('fcm_uuids')->nullable();
            $table->json('social_accounts')->nullable();
            $table->json('notification_preferences')->nullable();
            $table->boolean('app_allow_notification')->nullable();
            $table->boolean('app_allow_sound')->nullable();
            $table->boolean('accept_marketing_updates')->nullable();
            $table->json('locale')->nullable();
            $table->timestamp('first_login')->nullable();
            $table->timestamp('last_login')->nullable();
            $table->timestamp('first_login_app')->nullable();
            $table->timestamp('last_login_app')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->string('google_account_id')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('stripe_account_id')->nullable();
            $table->boolean('has_payment_card')->nullable();
            $table->string('default_payment_card')->nullable();
            $table->string('provider_name')->nullable();
            $table->string('provider_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->boolean('is_active')->nullable();
            $table->string('line_user_id')->nullable();
            $table->string('line_display_name')->nullable();
            $table->string('line_account_status')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('note_updated_at')->nullable();
            $table->unsignedBigInteger('last_note_by')->nullable();
            $table->boolean('is_external_agent')->nullable();
            $table->timestamp('sync_created_at')->nullable();
            $table->timestamp('sync_updated_at')->nullable();
            $table->timestamp('sync_last_synced_at')->nullable();
            $table->index('uuid');
            $table->index('slug');
            $table->index('group_id');
            $table->index('country_id');
            $table->index('email');
            $table->index('phone');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dealer_contacts');
    }
};
