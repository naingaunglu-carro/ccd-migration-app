<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per (transaction, tag) — tracks whether a dealer_files object
     * matching that tag (e.g. bos, voc, vrc, owner_ic) exists for the
     * transaction (file_id set), and when/where it was downloaded locally
     * (downloaded_at/downloaded_path, both null until then).
     */
    public function up(): void
    {
        Schema::create('stg_transaction_files', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('transaction_id'); // dealer_transactions.id
            $table->unsignedBigInteger('file_id')->nullable(); // dealer_files.id — null until a match is found
            $table->string('tag_name'); // custom_properties.tags entry, e.g. bos, voc, vrc, owner_ic

            $table->timestamp('downloaded_at')->nullable(); // when the matched file was downloaded locally
            $table->string('downloaded_path')->nullable(); // local path once downloaded

            $table->timestamps();

            $table->unique(['transaction_id', 'tag_name']);
            $table->index('file_id');
            $table->index('downloaded_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stg_transaction_files');
    }
};
