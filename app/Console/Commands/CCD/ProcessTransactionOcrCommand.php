<?php

namespace App\Console\Commands\CCD;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Queue + OCR stg_transaction_files rows into stg_transaction_ocr.
 *
 * Walks stg_transaction_files latest transaction first, scoped to a country
 * (via its dealer_transactions.country_id), for rows that have actually been
 * downloaded to disk (downloaded_path set — see
 * ccd:download-transaction-files). Each matching row is upserted into
 * stg_transaction_ocr keyed on file_id (queue step — never clobbers
 * ocr_status/ocr_* on a row already queued/processed), then every row still
 * at --status (default pending) is handed in one batch to the RapidOCR
 * wrapper (scripts/ocr/ocr_process.py), which extracts name + national
 * id/passport, and the ocr_* columns are written back.
 *
 * Re-running only reprocesses rows still at --status — done/not_detected
 * files are skipped, so this is resumable across batches/tags.
 */
class ProcessTransactionOcrCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'ccd:process-transaction-ocr
        {--country= : Only stg_transaction_files whose transaction is for this dealer_countries.id or .country_code (all when omitted)}
        {--tag_name= : Only stg_transaction_files rows with this tag_name (all when omitted)}
        {--status=pending : Only queued rows at this ocr_status get OCR\'d}
        {--limit= : Max transaction files to queue/process (all when omitted)}
        {--python= : Path to the OCR venv python (default: scripts/ocr/.venv/bin/python)}';

    /**
     * @var string
     */
    protected $description = 'Queue downloaded stg_transaction_files into stg_transaction_ocr and OCR them via scripts/ocr';

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

        $tagName = $this->option('tag_name') !== null ? trim((string) $this->option('tag_name')) : null;
        $status  = (string) $this->option('status');
        $limit   = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $python = $this->option('python') ?: base_path('scripts/ocr/.venv/bin/python');
        $script = base_path('scripts/ocr/ocr_process.py');

        if (! is_file($python)) {
            $this->error("OCR python not found at {$python}.");
            $this->line('  Set it up: cd scripts/ocr && python3.12 -m venv .venv && .venv/bin/pip install -r requirements-rapid.txt');

            return self::FAILURE;
        }

        if (! is_file($script)) {
            $this->error("OCR wrapper not found at {$script}.");

            return self::FAILURE;
        }

        // Queue: pull downloaded stg_transaction_files rows, latest transaction
        // first, and upsert them into stg_transaction_ocr — never touching
        // ocr_status/ocr_* on a row that's already queued/processed.
        $query = DB::table('stg_transaction_files as s')
            ->join('dealer_transactions as t', 't.id', '=', 's.transaction_id')
            ->leftJoin('dealer_files as f', 'f.id', '=', 's.file_id')
            ->whereNotNull('s.file_id')
            ->whereNotNull('s.downloaded_path')
            ->whereNull('t.deleted_at')
            ->when($countryId !== null, fn ($q) => $q->where('t.country_id', $countryId))
            ->when($tagName !== null, fn ($q) => $q->where('s.tag_name', $tagName))
            ->orderByDesc('s.transaction_id')
            ->select([
                's.transaction_id', 's.file_id', 's.tag_name',
                DB::raw("f.custom_properties::jsonb->'tags' as tags"),
            ]);

        if ($limit !== null) {
            $query->limit($limit);
        }

        $rows = $query->get();

        if ($rows->isEmpty()) {
            $this->info('No downloaded stg_transaction_files rows match'
                . ($countryId !== null ? " country #{$countryId}" : '')
                . ($tagName !== null ? " tag '{$tagName}'" : '') . '.');

            return self::SUCCESS;
        }

        $now = now();

        $queueRows = $rows->map(fn ($row) => [
            'transaction_id' => $row->transaction_id,
            'file_id'        => $row->file_id,
            'tag_name'       => $row->tag_name,
            'tags'           => $row->tags,
            'ocr_status'     => 'pending',
            'created_at'     => $now,
            'updated_at'     => $now,
        ])->all();

        foreach (array_chunk($queueRows, 500) as $chunk) {
            DB::table('stg_transaction_ocr')->upsert(
                $chunk,
                ['file_id'],
                ['transaction_id', 'tag_name', 'tags', 'updated_at'],
            );
        }

        $this->info('Queued ' . count($queueRows) . ' file(s) into stg_transaction_ocr'
            . ($countryId !== null ? " (country #{$countryId})" : '')
            . ($tagName !== null ? " (tag '{$tagName}')" : '') . '.');

        // Process: only rows still at --status, joined back to stg_transaction_files
        // for the local path (downloaded_path lives there, not on stg_transaction_ocr).
        $fileIds = collect($queueRows)->pluck('file_id');

        $toProcess = DB::table('stg_transaction_ocr as q')
            ->join('stg_transaction_files as s', 's.file_id', '=', 'q.file_id')
            ->where('q.ocr_status', $status)
            ->whereIn('q.file_id', $fileIds)
            ->select(['q.file_id', 's.downloaded_path'])
            ->get();

        if ($toProcess->isEmpty()) {
            $this->info("No queued rows at status \"{$status}\" to OCR.");

            return self::SUCCESS;
        }

        $manifest = tempnam(sys_get_temp_dir(), 'transaction_ocr_');
        $handle   = fopen($manifest, 'w');
        $missing  = [];

        foreach ($toProcess as $row) {
            if ($row->downloaded_path !== null && is_file($row->downloaded_path)) {
                fwrite($handle, "{$row->file_id}\t{$row->downloaded_path}\n");
            } else {
                $missing[] = $row->file_id;
            }
        }

        fclose($handle);

        if ($missing !== []) {
            DB::table('stg_transaction_ocr')
                ->whereIn('file_id', $missing)
                ->update([
                    'ocr_status'  => 'missing_file',
                    'ocr_message' => 'downloaded_path not set/found on disk',
                    'updated_at'  => now(),
                ]);

            $this->warn(count($missing) . ' file(s) not downloaded — marked missing_file. Run ccd:download-transaction-files first.');
        }

        $present = $toProcess->count() - count($missing);

        if ($present === 0) {
            @unlink($manifest);
            $this->info('No files available on disk to OCR.');

            return self::SUCCESS;
        }

        $this->info("OCR-processing {$present} file(s) via {$python}…");

        // Stream the wrapper's JSONL back and apply each result as it arrives.
        $process = new Process([$python, $script, '--manifest', $manifest], base_path(), null, null, null);

        $stats  = ['done' => 0, 'not_detected' => 0, 'failed' => 0];
        $buffer = '';

        $process->run(function ($type, $chunk) use (&$buffer, &$stats) {
            if ($type === Process::ERR) {
                $this->output->write($chunk); // progress/log lines from python

                return;
            }

            $buffer .= $chunk;

            while (($nl = strpos($buffer, "\n")) !== false) {
                $line   = substr($buffer, 0, $nl);
                $buffer = substr($buffer, $nl + 1);
                $this->applyResult(trim($line), $stats);
            }
        });

        if ($buffer !== '') {
            $this->applyResult(trim($buffer), $stats);
        }

        @unlink($manifest);

        if (! $process->isSuccessful()) {
            $this->error('OCR wrapper failed: ' . trim($process->getErrorOutput()));

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Done. done={$stats['done']}, not_detected={$stats['not_detected']}, failed={$stats['failed']}"
            . ($missing !== [] ? ', missing_file=' . count($missing) : ''));

        return self::SUCCESS;
    }

    /**
     * Apply one JSONL result record from the wrapper to its stg_transaction_ocr row.
     *
     * @param  array<string, int>  $stats
     */
    private function applyResult(string $line, array &$stats): void
    {
        if ($line === '') {
            return;
        }

        $rec = json_decode($line, true);

        if (! is_array($rec) || ! isset($rec['file_id'])) {
            return;
        }

        // error → keep it retryable as "failed"; done/not_detected are terminal.
        $status = match ($rec['status'] ?? 'error') {
            'done'         => 'done',
            'not_detected' => 'not_detected',
            default        => 'failed',
        };

        DB::table('stg_transaction_ocr')
            ->where('file_id', $rec['file_id'])
            ->update([
                'ocr_slug'                   => $rec['name_slug'] ?? null,
                'ocr_name'                   => $rec['name'] ?? null,
                'ocr_person_nationality'     => $rec['nationality'] ?? null,
                'ocr_person_national_id'     => $rec['national_id'] ?? null,
                'ocr_person_passport_number' => $rec['passport_number'] ?? null,
                'ocr_type'                   => $rec['doc_type'] ?? null,
                'ocr_status'                 => $status,
                'ocr_message'                => $rec['message'] ?? null,
                'updated_at'                 => now(),
            ]);

        $stats[$status]++;
    }
}
