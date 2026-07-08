<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per real-world party, merged from one or more stg_one_parties
     * rows that share an identity: unique by (country_id, person_nationality,
     * person_national_id) OR (country_id, person_nationality,
     * person_passport_number) — nationality disambiguates id/passport formats
     * that can otherwise collide across countries. Postgres allows multiple
     * NULLs per unique index, so rows with no national id (or no passport)
     * simply don't participate in that particular uniqueness check.
     */
    public function up(): void
    {
        Schema::create('stg_two_parties', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('country_id');
            $table->string('type'); // 'person' | 'company'

            $table->string('name');
            $table->string('person_first_name')->nullable();
            $table->string('person_last_name')->nullable();
            $table->string('person_gender')->nullable();
            $table->date('person_date_of_birth')->nullable();
            $table->string('person_nationality')->nullable();
            $table->string('person_national_id')->nullable();
            $table->string('person_passport_number')->nullable();
            $table->string('company_registration_number')->nullable();
            $table->string('company_tax_id')->nullable();

            $table->text('merged_reference_ids')->nullable(); // source reference ids (e.g. dealer_contacts.id) merged into this party, "|"-separated
            $table->text('merged_stg_one_party_ids')->nullable(); // stg_one_parties.id merged into this party, "|"-separated

            $table->text('merged_names')->nullable(); // every name variant seen across the merged stg_one_parties rows, "||"-separated
            $table->decimal('merged_confidence_score', 3, 2)->nullable(); // overall confidence of this merge, derived from the contributing rows' confidence scores

            $table->string('status')->nullable();
            $table->string('reason')->nullable();

            $table->timestamps();

            $table->unique(['country_id', 'person_nationality', 'person_national_id']);
            $table->unique(['country_id', 'person_nationality', 'person_passport_number']);
            $table->index('status');
            $table->index('reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stg_two_parties');
    }
};
