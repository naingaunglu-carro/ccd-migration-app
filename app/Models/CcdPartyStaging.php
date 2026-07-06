<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $reference_id
 * @property string $reference_name
 * @property string|null $name
 * @property string|null $person_first_name
 * @property string|null $person_last_name
 * @property string|null $person_gender
 * @property Carbon|null $person_date_of_birth
 * @property string|null $person_national_id
 * @property string|null $person_passport_number
 * @property string|null $identification_key
 * @property string|null $identification_column
 * @property string|null $status
 * @property string|null $reason
 * @property Carbon|null $source_updated_at
 * @property int|null $canonical_reference_id
 * @property string|null $merged_reference_ids
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CcdPartyStaging extends Model
{
    protected $table = 'ccd_party_staging';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'person_date_of_birth' => 'date',
            'source_updated_at'    => 'datetime',
        ];
    }
}
