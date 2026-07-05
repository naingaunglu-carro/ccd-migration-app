<?php

namespace App\Console\Commands\CCD;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Run OCR over queued transaction_file_ocr rows.
 *
 * For each pending row it resolves the file's local path (mirroring the layout
 * written by dealer:download-tagged-files), hands the batch to the RapidOCR
 * wrapper (scripts/ocr/ocr_process.py) which extracts name + NRIC/passport, and
 * writes the ocr_* columns back with a done / not_detected / failed status.
 */
class ProcessFileOcrCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'ccd:process-file-ocr
        {--status=pending : Only rows with this ocr_status}
        {--limit= : Max rows to process this run}
        {--dealer-dir= : Directory the files were downloaded to (default: storage/app/dealer-files)}
        {--python= : Path to the OCR venv python (default: scripts/ocr/.venv/bin/python)}';

    /**
     * @var string
     */
    protected $description = 'OCR queued transaction files and store the extracted name/id into transaction_file_ocr';

    public function handle(): int
    {
        $python = $this->option('python') ?: base_path('scripts/ocr/.venv/bin/python');
        $script = base_path('scripts/ocr/ocr_process.py');
        $dir    = rtrim($this->option('dealer-dir') ?: storage_path('app/dealer-files'), '/');

        if (! is_file($python)) {
            $this->error("OCR python not found at {$python}.");
            $this->line('  Set it up: cd scripts/ocr && python3.12 -m venv .venv && .venv/bin/pip install -r requirements-rapid.txt');

            return self::FAILURE;
        }

        if (! is_file($script)) {
            $this->error("OCR wrapper not found at {$script}.");

            return self::FAILURE;
        }

        $status = (string) $this->option('status');
        $limit  = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        // Pull queued rows and join dealer_files for the columns needed to
        // reconstruct the object key: {Y}/{m}/{ModelClass}/{id}/{file_name}.
        $query = DB::table('transaction_file_ocr as q')
            ->join('dealer_files as f', 'f.id', '=', 'q.file_id')
            ->where('q.ocr_status', $status)
            ->orderByDesc('q.file_id')
            ->select(['q.file_id', 'f.file_name', 'f.model_type', 'f.created_at', 'f.updated_at']);

        if ($limit !== null) {
            $query->limit($limit);
        }

        $rows = $query->get();

        if ($rows->isEmpty()) {
            $this->info("No transaction_file_ocr rows with status \"{$status}\".");

            return self::SUCCESS;
        }

        // Build the manifest of files that actually exist locally; the rest are
        // flagged so they can be (re-)downloaded before OCR.
        $manifest = tempnam(sys_get_temp_dir(), 'ocr_');
        $handle   = fopen($manifest, 'w');
        $present  = 0;
        $missing  = [];

        foreach ($rows as $row) {
            $key  = $this->objectKey($row);
            $path = $key ? "{$dir}/{$key}" : null;

            if ($path !== null && is_file($path)) {
                fwrite($handle, "{$row->file_id}\t{$path}\n");
                $present++;
            } else {
                $missing[] = $row->file_id;
            }
        }

        fclose($handle);

        if ($missing !== []) {
            DB::table('transaction_file_ocr')
                ->whereIn('file_id', $missing)
                ->update([
                    'ocr_status'  => 'missing_file',
                    'ocr_message' => 'file not found under ' . $dir,
                    'updated_at'  => now(),
                ]);

            $this->warn(count($missing) . ' file(s) not downloaded — marked missing_file. Run dealer:download-tagged-files first.');
        }

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
     * Apply one JSONL result record from the wrapper to its transaction_file_ocr row.
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

        DB::table('transaction_file_ocr')
            ->where('file_id', $rec['file_id'])
            ->update([
                'ocr_name'                   => $rec['name'] ?? null,
                'ocr_name_slug'              => $rec['name_slug'] ?? null,
                'ocr_person_nationality'     => $rec['nationality'] ?? null,
                'ocr_person_national_id'     => $rec['national_id'] ?? null,
                'ocr_person_passport_number' => $rec['passport_number'] ?? null,
                'ocr_status'                 => $status,
                'ocr_message'                => $rec['message'] ?? null,
                'updated_at'                 => now(),
            ]);

        $stats[$status === 'done' ? 'done' : ($status === 'not_detected' ? 'not_detected' : 'failed')]++;
    }

    /**
     * Rebuild the object key for a dealer_files row:
     * "{Y}/{m}/{ModelClass}/{media_id}/{file_name}" (created_at + model_type),
     * matching dealer:download-tagged-files so paths line up on disk.
     */
    private function objectKey(object $row): ?string
    {
        $timestamp = $row->created_at ?: ($row->updated_at ?? null);

        if (empty($row->file_id) || empty($row->file_name) || empty($row->model_type) || empty($timestamp)) {
            return null;
        }

        return Carbon::parse($timestamp)->format('Y/m')
            . '/' . class_basename($row->model_type)
            . '/' . $row->file_id
            . '/' . $row->file_name;
    }
}
