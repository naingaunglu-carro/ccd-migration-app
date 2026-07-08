<?php

namespace App\Console\Commands\CCD_V2;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Populate stg_one_parties: one row per (country_id, reference_id,
 * reference_name), walking dealer_transactions latest-first (transaction_id
 * descending) for a country and collecting, per Contact-type party, every
 * transaction_id it's associated with (all of them) and every file_id that
 * was actually OCR'd for those transactions (stg_transaction_ocr.file_id,
 * ocr_status = 'done' — not every dealer_files row on the transaction).
 *
 * Only App\Modules\Contact\Models\Contact parties are considered — no other
 * dealer_transaction_parties.type is staged here.
 *
 * Two kinds of fields, refreshed differently on re-runs:
 *   - original_* (name, national id, gender, dob, …) come straight from
 *     dealer_contacts and are frozen the first time a reference_id is staged.
 *   - ocr_* come from stg_transaction_ocr (ocr_status = 'done') for the
 *     contact's transactions and ARE refreshed every run — OCR results land
 *     asynchronously after a contact is first staged, so a later run can
 *     upgrade an 'unidentified' row once its OCR data becomes available.
 *
 * An OCR row is accepted when its national id/passport matches the contact's
 * original_national_id, or — when the contact has none — when its
 * transaction has exactly one Contact party (no ambiguity about whose
 * document it is), latest transaction first. The latter is a guess, not a
 * verified identity check, so confidence_score/is_verified track which case
 * applied (1.00/true only for an id-cross-validated match, ~0.50/false for
 * the sole-Contact-party heuristic) — and ocr_name/ocr_name_slug/
 * ocr_person_national_id/ocr_person_passport_number are only populated when
 * verified, so an unverified guess never shows up as if it were reliable OCR
 * data (it still resolves identification_key below, though — it's the best
 * available signal even when unverified).
 *
 * identification_key/status/reason are recomputed every run:
 * original_national_id wins outright when it's usable — either OCR
 * cross-validated it (is_verified) or, absent that, it structurally passes
 * country-specific validation (currently just Malaysia's 12-digit IC format,
 * see isValidMyNationalId()); a national id that fails this check (e.g.
 * placeholder junk like "XX0000000000") is never used for identification,
 * landing the row as 'unidentified' / reason 'invalid_national_id_format'
 * instead. confidence_score_for_original/is_original_verified track this
 * independently of the OCR confidence_score/is_verified pair: 1.00/true when
 * OCR-confirmed, ~0.75/true when only format-valid, 0.00/false when
 * format-invalid, null/false when there's no id or no validation rule for
 * the country. Otherwise a matched OCR passport becomes the key.
 * transaction_ids/file_ids/possible_names/possible_national_ids/
 * possible_passport_numbers are all "|"-separated, latest-first, and merge
 * newly-seen values into the existing list rather than overwriting it.
 *
 * possible_* is deliberately broader than ocr_*: it's every name/national
 * id/passport OCR'd across ALL of the contact's transactions, not just the
 * one confident match — because on a multi-party transaction, a given file
 * might belong to a co-signer rather than this contact, so it's tracked as a
 * "possible" value rather than assumed correct.
 */
class StageOnePartiesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'ccd:stage-one-parties
        {--country= : dealer_countries.id or .country_code (e.g. MY)}
        {--limit= : Max dealer_transactions to process (all when omitted)}
        {--offset=0 : Skip this many transactions before starting (resume point after a failure)}';

    /**
     * @var string
     */
    protected $description = 'Populate stg_one_parties from dealer_transactions (latest first), Contact-type parties only';

    private const CONTACT_MODEL = 'App\Modules\Contact\Models\Contact';

    private const FLUSH_EVERY = 200;

    private const MALAYSIA_COUNTRY_ID = 49;

    /**
     * Tokens that signal placeholder / junk free-text data rather than a real
     * national id.
     */
    private const PLACEHOLDER_TOKENS = [
        'N/A', 'NA', 'NIL', 'NULL', 'NONE', 'NO', 'TEST', 'TESTING', 'ABC',
        'XXX', 'XXXX', 'ASDF', 'QWERTY', 'EXAMPLE', 'DUMMY', 'UNKNOWN', 'TBA', 'TBD', '0', '-',
    ];

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

        $base = DB::table('dealer_transactions')
            ->whereNull('deleted_at')
            ->when($countryId !== null, fn ($q) => $q->where('country_id', $countryId));

        $remaining = max(0, (clone $base)->count() - $offset);
        $total     = $limit !== null ? min($limit, $remaining) : $remaining;

        if ($total === 0) {
            $this->info('No dealer_transactions match' . ($countryId !== null ? " country #{$countryId}." : '.'));

            return self::SUCCESS;
        }

        $query = $base->orderByDesc('id')->offset($offset);

        if ($limit !== null) {
            $query->limit($limit);
        }

        $this->info("Staging {$total} transaction(s)" . ($countryId !== null ? " for country #{$countryId}" : '') . " (offset {$offset})…");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $processed = $offset;
        $buffer    = [];

        foreach ($query->cursor() as $transaction) {
            $buffer[] = $transaction;
            $processed++;
            $bar->advance();

            if (count($buffer) >= self::FLUSH_EVERY) {
                $this->flush($buffer, $countryId);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            $this->flush($buffer, $countryId);
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Done. Processed {$processed} transaction(s).");

        if ($limit !== null && $total === $limit) {
            $this->newLine();
            $this->comment("Next batch: php artisan ccd:stage-one-parties --offset={$processed} --limit={$limit}"
                . ($countryOpt !== null ? " --country={$countryOpt}" : ''));
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<object>  $transactions  dealer_transactions rows, latest first
     */
    private function flush(array $transactions, ?int $countryId): void
    {
        $transactionIds = array_map(fn ($t) => (int) $t->id, $transactions);

        $parties = DB::table('dealer_transaction_parties')
            ->whereIn('transaction_id', $transactionIds)
            ->where('type', self::CONTACT_MODEL)
            ->whereNull('deleted_at')
            ->select('transaction_id', 'type_id')
            ->get();

        if ($parties->isEmpty()) {
            return;
        }

        // Transaction ids per contact, in the order transactions were streamed
        // (latest first) — array_unique keeps the first (highest) occurrence.
        $txIdsByContact = [];

        foreach ($parties as $party) {
            $txIdsByContact[(int) $party->type_id][] = (int) $party->transaction_id;
        }

        $contactIds = array_keys($txIdsByContact);

        $contacts = DB::table('dealer_contacts')->whereIn('id', $contactIds)->get()->keyBy('id');

        $existing = DB::table('stg_one_parties')
            ->where('reference_name', 'contact')
            ->when($countryId !== null, fn ($q) => $q->where('country_id', $countryId))
            ->whereIn('reference_id', $contactIds)
            ->get()
            ->keyBy('reference_id');

        // file_ids tracks OCR-read files only (stg_transaction_ocr, not every
        // dealer_files row on the transaction) — same source doubles as the
        // OCR-matching input below (only actually used for contacts not
        // already staged; the $existingRow branch preserves identification
        // as-is — but cheapest to fetch once per chunk regardless).
        $ocrByTransaction = DB::table('stg_transaction_ocr')
            ->where('ocr_status', 'done')
            ->whereIn('transaction_id', $transactionIds)
            ->get()
            ->groupBy('transaction_id');

        $fileIdsByTransaction = $ocrByTransaction
            ->map(fn ($rows) => $rows->pluck('file_id')->map(fn ($id) => (int) $id)->all());

        $partyCounts = DB::table('dealer_transaction_parties')
            ->where('type', self::CONTACT_MODEL)
            ->whereNull('deleted_at')
            ->whereIn('transaction_id', $transactionIds)
            ->select('transaction_id', DB::raw('count(*) as cnt'))
            ->groupBy('transaction_id')
            ->pluck('cnt', 'transaction_id');

        $now  = now();
        $rows = [];

        foreach ($contactIds as $contactId) {
            $contact = $contacts->get($contactId);

            if ($contact === null) {
                continue; // dangling reference — contact not (yet) synced
            }

            $chunkTxIds = array_values(array_unique($txIdsByContact[$contactId]));

            $chunkFileIds = [];
            foreach ($chunkTxIds as $txId) {
                foreach ($fileIdsByTransaction->get($txId, []) as $fileId) {
                    $chunkFileIds[] = $fileId;
                }
            }
            $chunkFileIds = array_values(array_unique($chunkFileIds));

            $existingRow = $existing->get($contactId);

            $transactionIdsMerged = $this->mergeIds($existingRow->transaction_ids ?? null, $chunkTxIds);
            $fileIdsMerged        = $this->mergeIds($existingRow->file_ids ?? null, $chunkFileIds);

            // original_* is frozen the first time a reference_id is staged;
            // everything else (ocr_*, identification, status/reason) is
            // recomputed every run from the current OCR data.
            if ($existingRow !== null) {
                $originalNameSlug   = $existingRow->original_name_slug;
                $originalName       = $existingRow->original_name;
                $originalFirstName  = $existingRow->original_first_name;
                $originalLastName   = $existingRow->original_last_name;
                $originalNationality = $existingRow->original_nationality;
                $originalNationalId = $existingRow->original_national_id;
                $originalGender     = $existingRow->original_gender;
                $originalDob        = $existingRow->original_date_of_birth;
            } else {
                $originalName = $contact->display_name
                    ?? $contact->name
                    ?? trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? ''));
                $originalName = filled($originalName) ? $originalName : "contact #{$contactId}";

                $originalNameSlug    = Str::slug($originalName);
                $originalFirstName   = $contact->first_name ?? null;
                $originalLastName    = $contact->last_name ?? null;
                $originalNationality = $contact->nationality ?? null;
                // Standardize identification_key: dealer_contacts stores
                // national ids both formatted ("920210-01-5860") and bare
                // ("920210015860") — strip separators so the same real person
                // always produces the same key, matching the OCR side (which
                // is already stripped) instead of splitting into two keys.
                $originalNationalId  = $this->cleanIdentifier($this->stripFormatting($contact->national_identity_number ?? null));
                $originalGender      = $contact->gender ?? null;
                $originalDob         = isset($contact->date_of_birth) ? substr($contact->date_of_birth, 0, 10) : null;
            }

            [$ocrMatch, $ocrReason, $ocrVerified] = $this->matchOcr($chunkTxIds, $ocrByTransaction, $partyCounts, $originalNationalId);

            // Raw values off the matched candidate, used below to resolve
            // identification_key regardless of confidence (an unverified
            // sole-Contact-party guess is still the best available signal for
            // merging) — kept separate from the $ocr* values actually stored
            // in the columns below, which are gated on confidence.
            $ocrNameSlugRaw   = $ocrMatch->ocr_slug ?? null;
            $ocrNameRaw       = $this->cleanIdentifier($ocrMatch->ocr_name ?? null);
            $ocrNationality   = $this->cleanIdentifier($ocrMatch->ocr_person_nationality ?? null);
            // OCR extracts national ids/passports with their printed
            // formatting (e.g. "860225-43-5276"), but dealer_contacts.
            // national_identity_number never has separators — strip them so
            // both the stored value and matchOcr()'s comparison line up.
            $ocrNationalIdRaw = $this->cleanIdentifier($this->stripFormatting($ocrMatch->ocr_person_national_id ?? null));
            $ocrPassportRaw   = $this->cleanIdentifier($this->stripFormatting($ocrMatch->ocr_person_passport_number ?? null));
            $ocrType          = $ocrMatch->ocr_type ?? null;

            // Confidence in ocr_name/ocr_person_national_id/
            // ocr_person_passport_number: 1.00 when matchOcr() independently
            // cross-validated the id, ~0.50 when it only cleared the
            // sole-Contact-party heuristic (a guess), null when there was no
            // OCR match at all to score.
            $confidenceScore = match (true) {
                $ocrMatch === null => null,
                $ocrVerified        => 1.00,
                default              => 0.50,
            };
            $isVerified = $ocrMatch !== null && $ocrVerified;

            // Only surface name/national id/passport in the dedicated ocr_*
            // columns once independently verified — a low-confidence guess
            // still resolves identification_key below (it's the best
            // available signal), but shouldn't be presented as reliable OCR
            // data here.
            $ocrNameSlug   = $isVerified ? $ocrNameSlugRaw : null;
            $ocrName       = $isVerified ? $ocrNameRaw : null;
            $ocrNationalId = $isVerified ? $ocrNationalIdRaw : null;
            $ocrPassport   = $isVerified ? $ocrPassportRaw : null;

            $rowCountryId = $existingRow->country_id ?? ($countryId ?? $contact->country_id);

            // Confidence in original_national_id specifically: OCR
            // cross-validation (above) already proves it; absent that, fall
            // back to structural validation where we have a rule for the
            // contact's country (currently just Malaysia's 12-digit IC).
            // A national id that fails this check is treated as unusable for
            // identification below — it's likely placeholder junk (e.g.
            // "XX0000000000") that the generic cleanIdentifier() pass didn't catch.
            $originalNationalIdFormatValid = $originalNationalId === null
                ? null
                : ((int) $rowCountryId === self::MALAYSIA_COUNTRY_ID ? $this->isValidMyNationalId($originalNationalId) : null);

            [$confidenceScoreForOriginal, $isOriginalVerified] = match (true) {
                $originalNationalId === null   => [null, false],
                $isVerified                    => [1.00, true],
                $originalNationalIdFormatValid === true  => [0.75, true],
                $originalNationalIdFormatValid === false => [0.00, false],
                // No country-specific rule to check against and no OCR
                // confirmation — can't assert validity either way.
                default => [null, false],
            };

            // OCR independently confirming the exact same value outweighs the
            // format heuristic — isValidMyNationalId()'s state-code range is a
            // simplification (real MyKad numbers use broader foreign/special
            // codes it doesn't cover), so an id both sources agree on is
            // trusted even if it falls outside that simplified range.
            $originalNationalIdUsable = $originalNationalId !== null
                && ($isVerified || $originalNationalIdFormatValid !== false);

            [$identificationKey, $identificationColumn, $status, $reason] = match (true) {
                $originalNationalIdUsable => [$originalNationalId, 'national_id', 'identified', 'national_id'],
                $ocrPassportRaw !== null  => [$ocrPassportRaw, 'passport_number', 'identified', 'ocr_passport_match'],
                $originalNationalId !== null => [null, null, 'unidentified', 'invalid_national_id_format'],
                // $ocrReason is null when matchOcr() actually found a candidate
                // (e.g. matched by name only) but it had no usable passport number.
                default => [null, null, 'unidentified', $ocrReason ?? 'ocr_no_passport'],
            };

            // possible_* scans every OCR'd file on the contact's transactions
            // — not just matchOcr()'s single confident pick — because on a
            // multi-party transaction a given file might belong to a
            // co-signer, not this contact. ocr_* above stays the confident
            // single match; possible_* is the broader, unfiltered candidate
            // pool for later review/merge decisions.
            [$candidateNames, $candidateNationalIds, $candidatePassports] = $this->collectOcrCandidates($chunkTxIds, $ocrByTransaction);

            $possibleNames = $this->mergeValues(
                $existingRow->possible_names ?? null,
                [$originalName, ...$candidateNames],
            );
            $possibleNationalIds = $this->mergeValues(
                $existingRow->possible_national_ids ?? null,
                [$originalNationalId, ...$candidateNationalIds],
            );
            $possiblePassportNumbers = $this->mergeValues($existingRow->possible_passport_numbers ?? null, $candidatePassports);

            $rows[] = [
                'country_id'                 => $rowCountryId,
                'reference_id'               => $contactId,
                'reference_name'             => 'contact',
                'ocr_name_slug'              => $ocrNameSlug,
                'ocr_name'                   => $ocrName,
                'ocr_person_nationality'     => $ocrNationality,
                'ocr_person_national_id'     => $ocrNationalId,
                'ocr_person_passport_number' => $ocrPassport,
                'ocr_type'                   => $ocrType,
                'confidence_score'           => $confidenceScore,
                'is_verified'                => $isVerified,
                'original_name_slug'         => $originalNameSlug,
                'original_name'              => $originalName,
                'original_first_name'        => $originalFirstName,
                'original_last_name'         => $originalLastName,
                'original_nationality'       => $originalNationality,
                'original_national_id'       => $originalNationalId,
                'original_passport_number'   => null, // dealer_contacts has no passport column
                'original_gender'            => $originalGender,
                'original_date_of_birth'     => $originalDob,
                'confidence_score_for_original' => $confidenceScoreForOriginal,
                'is_original_verified'       => $isOriginalVerified,
                'identification_key'         => $identificationKey,
                'identification_column'      => $identificationColumn,
                'possible_names'             => $possibleNames,
                'possible_national_ids'      => $possibleNationalIds,
                'possible_passport_numbers'  => $possiblePassportNumbers,
                'transaction_ids'            => $transactionIdsMerged,
                'file_ids'                   => $fileIdsMerged,
                'status'                     => $status,
                'reason'                     => $reason,
                'updated_at'                 => $now,
                'created_at'                 => $existingRow->created_at ?? $now,
            ];
        }

        if ($rows === []) {
            return;
        }

        DB::table('stg_one_parties')->upsert(
            $rows,
            ['country_id', 'reference_id', 'reference_name'],
            [
                'ocr_name_slug', 'ocr_name', 'ocr_person_nationality', 'ocr_person_national_id',
                'ocr_person_passport_number', 'ocr_type', 'confidence_score', 'is_verified',
                'original_name_slug', 'original_name', 'original_first_name', 'original_last_name',
                'original_nationality', 'original_national_id', 'original_passport_number',
                'original_gender', 'original_date_of_birth',
                'confidence_score_for_original', 'is_original_verified',
                'identification_key', 'identification_column',
                'possible_names', 'possible_national_ids', 'possible_passport_numbers',
                'transaction_ids', 'file_ids', 'status', 'reason', 'updated_at',
            ],
        );
    }

    /**
     * Merge a "|"-separated list of ids already on the row with a freshly
     * seen list, de-duplicated and sorted descending (latest first).
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
     * Merge a "|"-separated list of strings already on the row with freshly
     * seen values, de-duplicated, newest values first. Unlike mergeIds this
     * has no natural sort order to fall back on, so the existing list's
     * relative order is preserved and new values are simply prepended.
     *
     * @param  list<string|null>  $newValues
     */
    private function mergeValues(?string $existing, array $newValues): string
    {
        $values = array_values(array_filter($newValues, fn ($v) => $v !== null && $v !== ''));

        if ($existing !== null && $existing !== '') {
            foreach (explode('|', $existing) as $v) {
                if ($v !== '') {
                    $values[] = $v;
                }
            }
        }

        return implode('|', array_values(array_unique($values)));
    }

    /**
     * Find the OCR row (if any) that belongs to this contact: matched by
     * national id/passport when the contact has a national id, otherwise
     * accepted unconditionally only when the transaction has exactly one
     * Contact party (so there's no ambiguity about who the document belongs to).
     *
     * $transactionIds is expected latest-first (see the caller), so the first
     * match found is from the contact's most recent transaction.
     *
     * When nothing matches, the second element explains why:
     *   - 'no_ocr_data'   — none of the contact's transactions have any
     *                        completed (ocr_status = 'done') OCR rows at all.
     *   - 'ocr_ambiguous' — OCR rows exist, but every candidate transaction
     *                        had more than one Contact party.
     *   - 'ocr_unmatched' — an unambiguous (or id-matching) candidate existed
     *                        but its OCR name/passport fields were blank, or
     *                        (with a national id) no candidate's ids matched.
     *
     * The third element is true only when the match was independently
     * cross-validated (the candidate's id equalled the contact's national
     * id) — false when accepted purely via the sole-Contact-party heuristic,
     * since that's a guess, not a verified identity check.
     *
     * @param  list<int>  $transactionIds
     * @param  \Illuminate\Support\Collection<int|string, \Illuminate\Support\Collection<int, object>>  $ocrByTransaction
     * @param  \Illuminate\Support\Collection<int|string, int>  $partyCounts
     * @return array{0: ?object, 1: ?string, 2: bool}
     */
    private function matchOcr(array $transactionIds, $ocrByTransaction, $partyCounts, ?string $nationalId): array
    {
        $sawOcr         = false;
        $sawSingleParty = false;

        foreach ($transactionIds as $transactionId) {
            foreach ($ocrByTransaction->get($transactionId, collect()) as $candidate) {
                $sawOcr = true;

                $isSingleParty  = ($partyCounts[$transactionId] ?? 0) === 1;
                $sawSingleParty = $sawSingleParty || $isSingleParty;

                $idVerified = $nationalId !== null && (
                    $this->stripFormatting($candidate->ocr_person_national_id) === $nationalId
                    || $this->stripFormatting($candidate->ocr_person_passport_number) === $nationalId
                );

                $idMatches = $nationalId !== null ? $idVerified : $isSingleParty;

                if ($idMatches && (filled($candidate->ocr_name) || filled($candidate->ocr_person_passport_number))) {
                    return [$candidate, null, $idVerified];
                }
            }
        }

        if (! $sawOcr) {
            return [null, 'no_ocr_data', false];
        }

        if ($nationalId === null && ! $sawSingleParty) {
            return [null, 'ocr_ambiguous', false];
        }

        return [null, 'ocr_unmatched', false];
    }

    /**
     * Every name/national id/passport number OCR'd on any of the contact's
     * transactions — unfiltered by matchOcr()'s single-candidate confidence
     * check, since on a multi-party transaction a given file might not
     * actually be this contact's document. Feeds possible_* only; the
     * confident single match (ocr_* columns) still comes from matchOcr().
     *
     * @param  list<int>  $transactionIds
     * @param  \Illuminate\Support\Collection<int|string, \Illuminate\Support\Collection<int, object>>  $ocrByTransaction
     * @return array{0: list<string>, 1: list<string>, 2: list<string>}
     */
    private function collectOcrCandidates(array $transactionIds, $ocrByTransaction): array
    {
        $names       = [];
        $nationalIds = [];
        $passports   = [];

        foreach ($transactionIds as $transactionId) {
            foreach ($ocrByTransaction->get($transactionId, collect()) as $candidate) {
                $names[]       = $this->cleanIdentifier($candidate->ocr_name ?? null);
                $nationalIds[] = $this->cleanIdentifier($this->stripFormatting($candidate->ocr_person_national_id ?? null));
                $passports[]   = $this->cleanIdentifier($this->stripFormatting($candidate->ocr_person_passport_number ?? null));
            }
        }

        return [$names, $nationalIds, $passports];
    }

    /**
     * Strip everything but letters/digits — OCR extracts national
     * ids/passports with their printed separators (e.g. "860225-43-5276" or
     * "S 1234567 A"), but the source dealer_contacts value never has them.
     */
    private function stripFormatting(?string $value): ?string
    {
        return $value === null ? null : preg_replace('/[^A-Za-z0-9]/', '', $value);
    }

    /**
     * Structurally validate a Malaysian IC (MyKad) number: 12 digits,
     * formatted YYMMDD-PB-XXXX with separators already stripped. Rejects:
     *   - anything not exactly 12 digits (e.g. "XX0000000000" — has letters)
     *   - all-same-digit strings (e.g. "000000000000", "999999999999")
     *   - a YYMMDD that isn't a real calendar date
     *   - a place-of-birth/state code (digits 7-8) outside 01-59 (00 is
     *     never issued; codes above 59 are reserved/unused)
     * This is a format check only — it can't confirm the id belongs to this
     * specific person, just that it isn't obvious placeholder junk.
     */
    private function isValidMyNationalId(string $value): bool
    {
        $digits = $this->stripFormatting($value);

        if ($digits === null || ! preg_match('/^\d{12}$/', $digits)) {
            return false;
        }

        if (preg_match('/^(\d)\1{11}$/', $digits) === 1) {
            return false;
        }

        $yy     = (int) substr($digits, 0, 2);
        $month  = (int) substr($digits, 2, 2);
        $day    = (int) substr($digits, 4, 2);
        $state  = (int) substr($digits, 6, 2);

        if ($state < 1 || $state > 59) {
            return false;
        }

        // Birth year could be this or last century — accept either.
        $currentYy = (int) date('y');
        $century   = $yy <= $currentYy ? 2000 : 1900;

        return checkdate($month, $day, $century + $yy);
    }

    /**
     * Trim + reject placeholder/junk values, returning null instead of an
     * empty/junk string.
     */
    private function cleanIdentifier(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '' || in_array(strtoupper($value), self::PLACEHOLDER_TOKENS, true)) {
            return null;
        }

        return preg_match('/[A-Za-z0-9]/', $value) === 1 ? $value : null;
    }
}
