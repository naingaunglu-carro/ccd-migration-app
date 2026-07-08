<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $country_id
 * @property int $reference_id
 * @property string $reference_name
 * @property string|null $ocr_name_slug
 * @property string|null $ocr_name
 * @property string|null $ocr_person_nationality
 * @property string|null $ocr_person_national_id
 * @property string|null $ocr_person_passport_number
 * @property string|null $ocr_type
 * @property float|null $confidence_score
 * @property bool $is_verified
 * @property string $original_name_slug
 * @property string $original_name
 * @property string|null $original_first_name
 * @property string|null $original_last_name
 * @property string|null $original_nationality
 * @property string|null $original_national_id
 * @property string|null $original_passport_number
 * @property string|null $original_gender
 * @property Carbon|null $original_date_of_birth
 * @property float|null $confidence_score_for_original
 * @property bool $is_original_verified
 * @property string|null $identification_key
 * @property string|null $identification_column
 * @property string|null $possible_names
 * @property string|null $possible_national_ids
 * @property string|null $possible_passport_numbers
 * @property string|null $transaction_ids
 * @property string|null $file_ids
 * @property string|null $status
 * @property string|null $reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class StgOneParty extends Model
{
    protected $table = 'stg_one_parties';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'original_date_of_birth'        => 'date',
            'is_verified'                   => 'boolean',
            'is_original_verified'          => 'boolean',
            'confidence_score'              => 'float',
            'confidence_score_for_original' => 'float',
        ];
    }
}
