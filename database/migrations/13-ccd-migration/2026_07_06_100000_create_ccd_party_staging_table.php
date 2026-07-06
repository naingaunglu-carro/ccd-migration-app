<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per distinct Contact-type dealer_contacts.id seen for a tenant.
     * Populated by ccd:stage-parties, consumed by ccd:import-staged-parties to
     * merge parties that share a national id / OCR-derived passport number.
     */
    public function up(): void
    {
        Schema::create('ccd_party_staging', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('reference_id'); // dealer_contacts.id
            $table->string('reference_name'); // 'contact'

            $table->string('name')->nullable(); // resolved (OCR-overridden when matched)
            $table->string('person_first_name')->nullable();
            $table->string('person_last_name')->nullable();
            $table->string('person_gender')->nullable();
            $table->date('person_date_of_birth')->nullable();
            $table->string('person_national_id')->nullable();
            $table->string('person_passport_number')->nullable(); // OCR-only, dealer_contacts has no column

            // Merge key: whichever of the two id columns above is non-null.
            $table->string('identification_key')->nullable();
            $table->string('identification_column')->nullable(); // 'person_national_id' | 'person_passport_number'

            // How identification_key was (or wasn't) resolved.
            $table->string('status')->nullable(); // 'identified' | 'unidentified'
            $table->string('reason')->nullable(); // 'national_id' | 'ocr_passport_match' | 'no_ocr_data' | 'ocr_ambiguous' | 'ocr_unmatched' | 'ocr_no_passport' | 'quarantined_placeholder'

            $table->timestamp('source_updated_at')->nullable(); // dealer_contacts.updated_at — tie-break input
            $table->unsignedBigInteger('canonical_reference_id')->nullable(); // filled by the 2nd pass
            $table->text('merged_reference_ids')->nullable(); // filled by the 2nd pass: every reference_id sharing this group's identification_key, comma-separated ascending (e.g. "1,2")

            $table->timestamps();

            $table->unique(['tenant_id', 'reference_id']);
            $table->index(['tenant_id', 'identification_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ccd_party_staging');
    }
};
