<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * OCR staging: one row per dealer_files object queued for OCR, carrying the
     * owning transaction reference and the file's tags. The ocr_* columns start
     * null and are filled by the OCR pass; ocr_status/ocr_message track progress.
     */
    public function up(): void
    {
        Schema::create('transaction_file_ocr', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Source references (dealer_* tables).
            $table->unsignedBigInteger('transaction_id')->nullable();  // dealer_transactions.id
            $table->string('transaction_type')->nullable();            // dealer_transactions.type
            $table->unsignedBigInteger('file_id');                     // dealer_files.id
            $table->jsonb('file_tags')->nullable();                     // dealer_files.custom_properties.tags

            // OCR extraction results — null until the OCR pass fills them.
            $table->string('ocr_name_slug')->nullable();
            $table->string('ocr_name')->nullable();
            $table->string('ocr_person_nationality')->nullable();
            $table->string('ocr_person_national_id')->nullable();
            $table->string('ocr_person_passport_number')->nullable();

            // Processing state.
            $table->string('ocr_status')->default('pending');
            $table->text('ocr_message')->nullable();

            $table->timestamps();

            $table->unique('file_id'); // one OCR row per file (idempotent upserts)
            $table->index('transaction_id');
            $table->index('ocr_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaction_file_ocr');
    }
};
