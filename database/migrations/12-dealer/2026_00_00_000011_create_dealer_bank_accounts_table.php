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
        Schema::create('dealer_bank_accounts', function (Blueprint $table) {
            $table->bigIncrements('sync_id');
            $table->unsignedBigInteger('id')->unique();
            $table->unsignedBigInteger('bank_id')->nullable();
            $table->string('account_no')->nullable();
            $table->string('account_holder_type')->nullable();
            $table->string('account_holder_id')->nullable();
            $table->string('account_holder_name')->nullable();
            $table->unsignedBigInteger('status_id')->nullable();
            $table->boolean('is_default')->nullable();
            $table->boolean('is_active')->nullable();
            $table->boolean('has_acknowledge_verification')->nullable();
            $table->unsignedBigInteger('proof_file_id')->nullable();
            $table->text('remarks')->nullable();
            $table->json('additional_data')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamp('sync_created_at')->nullable();
            $table->timestamp('sync_updated_at')->nullable();
            $table->timestamp('sync_last_synced_at')->nullable();
            $table->index('bank_id');
            $table->index('status_id');
            $table->index('account_no');
            $table->index(['account_holder_type', 'account_holder_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dealer_bank_accounts');
    }
};
