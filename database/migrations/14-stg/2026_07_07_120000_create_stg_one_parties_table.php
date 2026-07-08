<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per (country_id, reference_id, reference_name) — the
     * consolidated view of a Contact across every dealer_transaction it
     * appears on, before any cross-record merge-by-identification is applied
     * (unique by reference_id only, for now).
     */
    public function up(): void
    {
        Schema::create('stg_one_parties', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('country_id');
            $table->unsignedBigInteger('reference_id'); // dealer_contacts.id
            $table->string('reference_name'); // 'contact'

            $table->string('ocr_name_slug')->nullable();
            $table->string('ocr_name')->nullable();
            $table->string('ocr_person_nationality')->nullable();
            $table->string('ocr_person_national_id')->nullable();
            $table->string('ocr_person_passport_number')->nullable();
            $table->string('ocr_type')->nullable();

            // Confidence in ocr_name/ocr_person_national_id specifically: 1.00
            // when independently cross-validated against original_national_id
            // (or a passport match), ~0.50 when accepted only via the
            // sole-Contact-party heuristic (no independent id check), null
            // when there was no OCR match to score at all.
            $table->decimal('confidence_score', 3, 2)->nullable();
            $table->boolean('is_verified')->default(false); // true only when confidence_score = 1.00 (id-cross-validated)

            $table->string('original_name_slug');
            $table->string('original_name');
            $table->string('original_first_name')->nullable();
            $table->string('original_last_name')->nullable();
            $table->string('original_nationality')->nullable();
            $table->string('original_national_id')->nullable();
            $table->string('original_passport_number')->nullable();
            $table->string('original_gender')->nullable();
            $table->date('original_date_of_birth')->nullable();

            // Confidence in original_national_id specifically (independent of
            // OCR): 1.00 when OCR cross-validated it (mirrors is_verified),
            // 0.75 when no OCR confirmation exists but it structurally passes
            // country-specific national id validation (Malaysia: 12-digit IC
            // with a real birth date and valid state code — rejects
            // placeholder junk like "XX0000000000"), null when there's no
            // national id or no validation rule for the contact's country.
            $table->decimal('confidence_score_for_original', 3, 2)->nullable();
            $table->boolean('is_original_verified')->default(false);

            // Merge key: whichever id column above is usable (national id, or
            // an OCR-matched passport) — not merged across records yet.
            $table->string('identification_key')->nullable();
            $table->string('identification_column')->nullable(); // 'national_id' | 'passport_number'
        
            $table->text('possible_names')->nullable(); // all names seen for this party, "|"-separated, latest first
            $table->text('possible_national_ids')->nullable(); // all national ids seen for this party, "|"-separated, latest first
            $table->text('possible_passport_numbers')->nullable(); // all passport numbers seen for

            $table->text('transaction_ids')->nullable(); // dealer_transactions.id, "|"-separated, latest first (e.g. "3|2|1")
            $table->text('file_ids')->nullable(); // dealer_files.id, "|"-separated, latest first

            $table->string('status')->nullable();
            $table->string('reason')->nullable();

            $table->timestamps();

            $table->unique(['country_id', 'reference_id', 'reference_name']);
            $table->index('identification_key');
            $table->index('status');
            $table->index('reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stg_one_parties');
    }
};
