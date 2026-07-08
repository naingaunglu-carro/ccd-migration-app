<?php

namespace App\Console\Commands\CCD_V2;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Merge stg_one_parties rows that share an identity — (country_id,
 * person_nationality, national id) or (country_id, person_nationality,
 * passport number) — into one stg_two_parties row apiece.
 *
 * Only doubly-verified stg_one_parties rows are considered: status =
 * 'identified' AND is_verified = true (OCR independently cross-validated the
 * national id) AND is_original_verified = true (that id is itself confirmed
 * valid). This is a strict subset of 'identified' — it excludes
 * format-valid-but-OCR-unconfirmed rows (confidence 0.75) and passport-only
 * identifications (which never set is_verified) — everything else is left
 * for a later manual-review pass rather than merged on unconfirmed data.
 *
 * Per source row, "best available" person data is picked: ocr_name when
 * it's filled, otherwise the frozen original_name (in practice ocr_name is
 * almost always present here, since is_verified rows required an OCR match).
 * identification_column tells us which of national id/passport the row's
 * identification_key actually is (currently always 'national_id', given the
 * is_verified filter above). When two source rows disagree on name/person
 * details, the stg_two_parties row keeps whichever came from the
 * higher-confidence source (confidence_score vs confidence_score_for_original,
 * whichever is higher) — that confidence becomes merged_confidence_score.
 *
 * merged_reference_ids/merged_stg_one_party_ids ("|"-separated) and
 * merged_names ("||"-separated) accumulate every contributing row across
 * re-runs rather than being overwritten, so re-running is safe/idempotent.
 */
class StageTwoPartiesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'ccd:stage-two-parties
        {--country= : dealer_countries.id or .country_code (e.g. MY)}
        {--limit= : Max stg_one_parties rows to process (all when omitted)}
        {--offset=0 : Skip this many rows before starting (resume point after a failure)}';

    /**
     * @var string
     */
    protected $description = 'Merge identified stg_one_parties rows into stg_two_parties by shared identity';

    /**
     * In-run cache of resolved stg_two_parties rows, keyed by
     * "country|nationality|nationalId|passport", so repeat merges into the
     * same party within one run don't each re-query the database.
     *
     * @var array<string, object|null>
     */
    private array $twoPartyByKey = [];

    public function handle(): int
    {
        $countryOpt = $this->option('country') !== null ? trim((string) $this->option('country')) : null;
        $countryId  = null;

        if ($countryOpt !== null) {
            // Accept either a numeric dealer_countries.id or a country_code like "MY".
            $row = ctype_digit($countryOpt)
                ? DB::table('dealer_countries')->where('id', (int) $countryOpt)->first()
                : DB::table('dealer_countries')->where('country_code', strtoupper($countryOpt))->first();

            if ($row === null) {
                $this->error("Unknown dealer_countries entry: {$countryOpt}");

                return self::FAILURE;
            }

            $countryId = $row->id;
        }

        $limit  = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $offset = max(0, (int) $this->option('offset'));

        // Only doubly-verified rows: OCR independently cross-validated the
        // national id (is_verified) AND that id is itself confirmed valid
        // (is_original_verified) — excludes format-valid-but-OCR-unconfirmed
        // rows (confidence 0.75) and passport-only identifications (which
        // never set is_verified), not just anything status = 'identified'.
        $base = DB::table('stg_one_parties')
            ->where('status', 'identified')
            ->where('is_verified', true)
            ->where('is_original_verified', true)
            ->when($countryId !== null, fn ($q) => $q->where('country_id', $countryId));

        $remaining = max(0, (clone $base)->count() - $offset);
        $total     = $limit !== null ? min($limit, $remaining) : $remaining;

        if ($total === 0) {
            $this->info('No verified stg_one_parties rows match' . ($countryId !== null ? " country #{$countryId}." : '.'));

            return self::SUCCESS;
        }

        $query = $base->orderBy('id')->offset($offset);

        if ($limit !== null) {
            $query->limit($limit);
        }

        $this->info("Merging {$total} verified stg_one_parties row(s)" . ($countryId !== null ? " for country #{$countryId}" : '') . " (offset {$offset})…");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $processed = $offset;
        $created   = 0;
        $merged    = 0;

        foreach ($query->cursor() as $row) {
            if ($this->mergeRow($row)) {
                $created++;
            } else {
                $merged++;
            }

            $processed++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Done. Processed {$processed} row(s): {$created} new part(ies) created, {$merged} merged into an existing party.");

        if ($limit !== null && $total === $limit) {
            $this->newLine();
            $this->comment("Next batch: php artisan ccd:stage-two-parties --offset={$processed} --limit={$limit}"
                . ($countryOpt !== null ? " --country={$countryOpt}" : ''));
        }

        return self::SUCCESS;
    }

    /**
     * Merge one stg_one_parties row into its stg_two_parties party, creating
     * one if none exists yet. Returns true when a new party was created,
     * false when it merged into an existing one.
     */
    private function mergeRow(object $row): bool
    {
        $nationality = $row->original_nationality ?? $row->ocr_person_nationality;
        $preferOcr   = (bool) $row->is_verified;
        $name        = $preferOcr && filled($row->ocr_name) ? $row->ocr_name : $row->original_name;

        // first/last name are split from $name itself, not taken from
        // original_first_name/original_last_name — those are dealer_contacts'
        // own fields and don't line up once $name is the OCR-read name
        // instead (the common case here, since the is_verified filter above
        // means an OCR match was required).
        [$personFirstName, $personLastName] = $this->splitName($name);

        $nationalId = $row->identification_column === 'national_id' ? $row->identification_key : null;
        $passport   = $row->identification_column === 'passport_number' ? $row->identification_key : null;

        $confidence = max(
            (float) ($row->confidence_score ?? 0),
            (float) ($row->confidence_score_for_original ?? 0),
        );

        $countryId = (int) $row->country_id;
        $cacheKey  = implode('|', [$countryId, $nationality ?? '', $nationalId ?? '', $passport ?? '']);

        if (! array_key_exists($cacheKey, $this->twoPartyByKey)) {
            $this->twoPartyByKey[$cacheKey] = $this->findExisting($countryId, $nationality, $nationalId, $passport);
        }

        $existing = $this->twoPartyByKey[$cacheKey];
        $now      = now();

        if ($existing === null) {
            $id = DB::table('stg_two_parties')->insertGetId([
                'country_id'                 => $countryId,
                'type'                       => 'person',
                'name'                       => $name,
                'person_first_name'          => $personFirstName,
                'person_last_name'           => $personLastName,
                'person_gender'              => $row->original_gender,
                'person_date_of_birth'       => $row->original_date_of_birth,
                'person_nationality'         => $nationality,
                'person_national_id'         => $nationalId,
                'person_passport_number'     => $passport,
                'merged_reference_ids'       => (string) $row->reference_id,
                'merged_stg_one_party_ids'   => (string) $row->id,
                'merged_names'               => (string) $name,
                'merged_confidence_score'    => $confidence,
                'status'                     => 'merged',
                'reason'                     => $row->identification_column,
                'created_at'                 => $now,
                'updated_at'                 => $now,
            ]);

            $this->twoPartyByKey[$cacheKey] = (object) [
                'id'                       => $id,
                'merged_reference_ids'     => (string) $row->reference_id,
                'merged_stg_one_party_ids' => (string) $row->id,
                'merged_names'             => (string) $name,
                'merged_confidence_score'  => $confidence,
            ];

            return true;
        }

        $update = [
            'merged_reference_ids'     => $this->mergeIds($existing->merged_reference_ids, [(int) $row->reference_id]),
            'merged_stg_one_party_ids' => $this->mergeIds($existing->merged_stg_one_party_ids, [(int) $row->id]),
            'merged_names'             => $this->mergeValues($existing->merged_names, [$name], '||'),
            'updated_at'               => $now,
        ];

        // A higher-confidence source row's name/person details win.
        if ($confidence > (float) ($existing->merged_confidence_score ?? 0)) {
            $update['name']                   = $name;
            $update['person_first_name']      = $personFirstName;
            $update['person_last_name']       = $personLastName;
            $update['person_gender']          = $row->original_gender;
            $update['person_date_of_birth']   = $row->original_date_of_birth;
            $update['merged_confidence_score'] = $confidence;
        }

        DB::table('stg_two_parties')->where('id', $existing->id)->update($update);

        $this->twoPartyByKey[$cacheKey] = (object) array_merge((array) $existing, $update);

        return false;
    }

    /**
     * Find the stg_two_parties row (if any) matching this identity. Postgres
     * unique indexes treat NULL as distinct-from-everything, so a plain
     * WHERE person_nationality = NULL would never match — whereNull() is
     * used explicitly instead to still merge same-id-different-null-nationality
     * rows together within this command's own matching logic.
     */
    private function findExisting(int $countryId, ?string $nationality, ?string $nationalId, ?string $passport): ?object
    {
        $query = DB::table('stg_two_parties')->where('country_id', $countryId);

        $query = $nationality !== null
            ? $query->where('person_nationality', $nationality)
            : $query->whereNull('person_nationality');

        if ($nationalId !== null) {
            $query->where('person_national_id', $nationalId);
        } else {
            $query->where('person_passport_number', $passport);
        }

        return $query->first();
    }

    /**
     * Split a single name string into (first, last): the first word is the
     * first name, everything after it is the last name (null when there's
     * only one word). A simple heuristic — good enough for names like
     * "MUHAMMAD IZWAN BIN MOHD FAUZI", not locale-aware beyond that.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function splitName(?string $name): array
    {
        $parts = preg_split('/\s+/', trim((string) $name), 2);

        if ($parts === false || $parts === [] || $parts[0] === '') {
            return [null, null];
        }

        return [$parts[0], $parts[1] ?? null];
    }

    /**
     * Merge a "|"-separated list of ids already on the row with a freshly
     * seen list, de-duplicated and sorted descending.
     *
     * @param  list<int>  $newIds
     */
    private function mergeIds(?string $existing, array $newIds): string
    {
        $ids = $newIds;

        if ($existing !== null && $existing !== '') {
            foreach (explode('|', $existing) as $id) {
                if ($id !== '') {
                    $ids[] = (int) $id;
                }
            }
        }

        $ids = array_unique($ids);
        rsort($ids, SORT_NUMERIC);

        return implode('|', $ids);
    }

    /**
     * Merge a separator-joined list of strings already on the row with
     * freshly seen values, de-duplicated, existing order preserved and new
     * values appended.
     *
     * @param  list<string|null>  $newValues
     */
    private function mergeValues(?string $existing, array $newValues, string $separator): string
    {
        $values = [];

        if ($existing !== null && $existing !== '') {
            foreach (explode($separator, $existing) as $v) {
                if ($v !== '') {
                    $values[] = $v;
                }
            }
        }

        foreach ($newValues as $v) {
            if ($v !== null && $v !== '') {
                $values[] = $v;
            }
        }

        return implode($separator, array_values(array_unique($values)));
    }
}
