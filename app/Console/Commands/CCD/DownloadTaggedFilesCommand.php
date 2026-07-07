<?php

namespace App\Console\Commands\CCD;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Download dealer_files objects from S3 for rows whose custom_properties.tags
 * match one of the requested tags (e.g. bos, voc, vrc, owner_ic).
 *
 * The tag-filtered lookup runs here (Postgres JSON); the actual S3 transfer is
 * delegated to scripts/download_dealer_files.py using boto3 from the project
 * virtualenv (.venv). AWS credentials are taken from the environment — export
 * AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY / AWS_SESSION_TOKEN before running.
 *
 * S3 object keys follow Spatie Media Library's "{id}/{file_name}" layout.
 *
 * Resumable: after a run that completes cleanly (0 denied, 0 failed — a run
 * with denied/archived objects does NOT advance it, since those still need a
 * real S3 restore, not just a retry), the (model_id, id) of the last row
 * processed is written to a checkpoint file next to --dest. Since rows are
 * walked latest-transaction first, the next plain re-run automatically picks
 * up only what's older than that checkpoint instead of re-listing/re-matching
 * everything again. Pass --restart to ignore the checkpoint and do a full pass.
 *
 * --retries (default 2) re-invokes the python script in place against the
 * same manifest if it exits non-zero (already-downloaded files are
 * fast-skipped, so a retry only redoes what actually failed/was denied).
 * Every attempt — success or failure — is appended as one JSON line to
 * --status-log (default storage/logs/dealer-download-status.jsonl) so run
 * history (matched count, ok/skip/missing/denied/failed, exit code) survives
 * across invocations.
 */
class DownloadTaggedFilesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'dealer:download-tagged-files
        {--tags=bos,voc,vrc,owner_ic : Comma-separated custom_properties.tags to match (any-of)}
        {--disk= : Only files on this dealer_files.disk (e.g. s3, private_s3)}
        {--bucket=carro.co : S3 bucket to download from}
        {--dest= : Local destination directory (default: storage/app/dealer-files)}
        {--region= : AWS region (default: AWS_DEFAULT_REGION or ap-southeast-1)}
        {--concurrency=8 : Parallel downloads}
        {--overwrite : Re-download even if the local file already exists}
        {--dry-run : Plan only — build the manifest but do not download}
        {--restart : Ignore any saved checkpoint and do a full pass from the latest transaction}
        {--before= : Only files with created_at before this date/month (e.g. 2025-12 or 2025-12-01) — for continuing a run done before the checkpoint existed}
        {--retries=2 : Re-run the download in place up to N times if it exits non-zero}
        {--status-log= : Path to append JSON-lines run status to (default storage/logs/dealer-download-status.jsonl)}
        {--python= : Path to the venv python (default: .venv/bin/python)}';

    /**
     * @var string
     */
    protected $description = 'Download dealer_files objects matching custom_properties tags from S3 via boto3';

    public function handle(): int
    {
        $tags = collect(explode(',', (string) $this->option('tags')))
            ->map(fn ($t) => preg_replace('/[^A-Za-z0-9_]/', '', trim($t)))
            ->filter()
            ->unique()
            ->values();

        if ($tags->isEmpty()) {
            $this->error('No valid tags given via --tags.');

            return self::FAILURE;
        }

        $python = $this->option('python') ?: base_path('.venv/bin/python');
        $script = base_path('scripts/download_dealer_files.py');
        $dest   = $this->option('dest') ?: storage_path('app/dealer-files');

        if (! is_file($python)) {
            $this->error("Python venv not found at {$python}. Create it: python3 -m venv .venv && .venv/bin/pip install boto3");

            return self::FAILURE;
        }

        if (! is_file($script)) {
            $this->error("Downloader script not found at {$script}.");

            return self::FAILURE;
        }

        // Match rows where custom_properties.tags overlaps the requested tags.
        // jsonb_exists_any is the function form of the ?| operator — the operator
        // itself can't be used here because PDO treats "?" as a bind placeholder.
        $pgArray = '{' . $tags->implode(',') . '}';

        $query = DB::table('dealer_files')
            ->whereNotNull('file_name')
            ->whereRaw("jsonb_exists_any(custom_properties::jsonb->'tags', ?::text[])", [$pgArray]);

        if ($disk = $this->option('disk')) {
            $query->where('disk', $disk);
        }

        if ($before = $this->option('before')) {
            try {
                $beforeDate = Carbon::parse($before)->startOfDay();
            } catch (\Exception) {
                $this->error("Invalid --before date: {$before}");

                return self::FAILURE;
            }

            $query->where('created_at', '<', $beforeDate);
        }

        $checkpointPath = rtrim($dest, '/') . '/.dealer-files-checkpoint';
        $checkpoint     = $this->option('restart') ? null : $this->readCheckpoint($checkpointPath);

        if ($checkpoint !== null) {
            // Rows are walked latest-transaction first (model_id, id both
            // descending) — only fetch what's older than the last row a
            // clean run finished on.
            $query->whereRaw('(model_id, id) < (?, ?)', [$checkpoint['model_id'], $checkpoint['id']]);
        }

        $count = (clone $query)->count();

        if ($count === 0) {
            $this->info("No dealer_files rows match tags [{$tags->implode(', ')}]"
                . ($disk ? " on disk {$disk}" : '')
                . ($checkpoint !== null ? ' older than the saved checkpoint' : '')
                . '.');

            return self::SUCCESS;
        }

        // Write the S3 keys ("{key}\t{disk}") to a manifest via a cursor, so
        // memory stays flat regardless of how many rows match. Ordered by
        // model_id (the owning transaction's id) descending — latest
        // transaction first — with id as a tie-break for a stable order,
        // since chunkById can't paginate cleanly on the non-unique model_id.
        $manifest = tempnam(sys_get_temp_dir(), 'dealer_files_');
        $handle   = fopen($manifest, 'w');
        $skipped  = 0;
        $lastRow  = null;

        foreach ($query->orderByDesc('model_id')->orderByDesc('id')->cursor() as $row) {
            $lastRow = $row;
            $key     = $this->objectKey($row);

            if ($key === null) {
                $skipped++;

                continue;
            }

            fwrite($handle, "{$key}\t{$row->disk}\n");
        }

        fclose($handle);

        $this->info("Matched {$count} file(s) for tags [{$tags->implode(', ')}]"
            . ($disk ? " on disk {$disk}" : '')
            . ($checkpoint !== null ? " (resuming after model_id #{$checkpoint['model_id']})" : '')
            . (isset($beforeDate) ? " (before {$beforeDate->toDateString()})" : '')
            . " → s3://{$this->option('bucket')} → {$dest}"
            . ($skipped ? " ({$skipped} skipped — no created_at/model_type/file_name)" : ''));

        // carro.co lives in ap-southeast-1; .env commonly defaults
        // AWS_DEFAULT_REGION elsewhere (e.g. us-east-1), which makes SigV4 sign
        // against the wrong endpoint and S3 answers 403. Pin it, --region wins.
        $region = $this->option('region') ?: 'ap-southeast-1';

        $args = [
            $python, $script,
            '--bucket', (string) $this->option('bucket'),
            '--dest', $dest,
            '--manifest', $manifest,
            '--concurrency', (string) max(1, (int) $this->option('concurrency')),
            '--region', $region,
        ];

        if ($this->option('overwrite')) {
            $args[] = '--overwrite';
        }

        if ($this->option('dry-run')) {
            $args[] = '--dry-run';
        }

        // Forward the AWS credentials from the real shell environment. $_SERVER
        // holds the values PHP started with, so empty AWS_* keys loaded from .env
        // can't clobber the ones the user exported. Region is pinned here too so
        // it overrides whatever .env set.
        // Silence boto3's Python-version deprecation warning — the pinned .venv
        // (Python 3.9) triggers it on every run and it's just noise on stderr,
        // not something actionable here.
        $env = [
            'AWS_DEFAULT_REGION' => $region,
            'AWS_REGION'         => $region,
            'PYTHONWARNINGS'     => 'ignore::DeprecationWarning',
        ];

        foreach (['AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_SESSION_TOKEN'] as $key) {
            $value = $_SERVER[$key] ?? getenv($key) ?: '';

            if ($value !== '') {
                $env[$key] = $value;
            }
        }

        if (! $this->option('dry-run') && empty($env['AWS_ACCESS_KEY_ID'])) {
            @unlink($manifest);
            $this->error('AWS credentials not found in the environment. Export them, then re-run:');
            $this->line('  export AWS_ACCESS_KEY_ID=... AWS_SECRET_ACCESS_KEY=... AWS_SESSION_TOKEN=...');

            return self::FAILURE;
        }

        $statusLog   = $this->option('status-log') ?: storage_path('logs/dealer-download-status.jsonl');
        $maxAttempts = 1 + max(0, (int) $this->option('retries'));
        $exit        = 1;
        $stats       = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if ($attempt > 1) {
                $this->warn("Attempt {$attempt}/{$maxAttempts} — retrying download in place (already-downloaded files are skipped)…");
            }

            $output  = '';
            $process = new Process($args, base_path(), $env, null, null);

            $exit = $process->run(function ($type, $buffer) use (&$output) {
                $this->output->write($buffer);
                $output .= $buffer;
            });

            $stats = $this->parseStats($output);

            $this->logRunStatus($statusLog, [
                'timestamp'    => now()->toIso8601String(),
                'command'      => 'dealer:download-tagged-files',
                'tags'         => $tags->values()->all(),
                'disk'         => $disk ?: null,
                'before'       => isset($beforeDate) ? $beforeDate->toDateString() : null,
                'resumed_from' => $checkpoint,
                'dest'         => $dest,
                'matched'      => $count,
                'attempt'      => $attempt,
                'max_attempts' => $maxAttempts,
                'dry_run'      => (bool) $this->option('dry-run'),
                'exit_code'    => $exit,
                'stats'        => $stats,
            ]);

            if ($exit === 0) {
                break;
            }
        }

        @unlink($manifest);

        // Only advance the checkpoint after a fully clean, real (non-dry-run)
        // pass: exit 0 alone isn't enough — the script also exits 0 when
        // objects are "denied" (e.g. archived to Glacier — InvalidObjectState)
        // as long as some succeeded, and those denied objects still need a
        // real S3 restore, not just a retry. Advancing the checkpoint past
        // them would silently drop them from every future incremental run.
        $cleanPass = $exit === 0 && $stats !== null && $stats['denied'] === 0 && $stats['failed'] === 0;

        if ($cleanPass && ! $this->option('dry-run') && $lastRow !== null) {
            $this->writeCheckpoint($checkpointPath, (int) $lastRow->model_id, (int) $lastRow->id);
        } elseif ($exit === 0 && ! $cleanPass && ! $this->option('dry-run')) {
            $this->warn('Checkpoint not advanced: run had denied/failed objects (e.g. archived files needing an S3 restore) — re-run to retry them.');
        }

        return $exit === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Pull the "ok/skip/missing/denied/failed" counts out of the script's
     * final "Done: ..." line, so the status log records real per-attempt
     * numbers instead of just the exit code.
     *
     * @return array{ok: int, skip: int, missing: int, denied: int, failed: int}|null
     */
    private function parseStats(string $output): ?array
    {
        if (! preg_match('/Done: ok (\d+), skip (\d+), missing (\d+), denied (\d+), failed (\d+)\./', $output, $m)) {
            return null;
        }

        return [
            'ok'      => (int) $m[1],
            'skip'    => (int) $m[2],
            'missing' => (int) $m[3],
            'denied'  => (int) $m[4],
            'failed'  => (int) $m[5],
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function logRunStatus(string $path, array $entry): void
    {
        $dir = dirname($path);

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($path, json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * @return array{model_id: int, id: int}|null
     */
    private function readCheckpoint(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded) || ! isset($decoded['model_id'], $decoded['id'])) {
            return null;
        }

        return ['model_id' => (int) $decoded['model_id'], 'id' => (int) $decoded['id']];
    }

    private function writeCheckpoint(string $path, int $modelId, int $id): void
    {
        $dir = dirname($path);

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($path, json_encode(['model_id' => $modelId, 'id' => $id]));
    }

    /**
     * Build the S3 object key for a dealer_files row.
     *
     * Carro's media path generator lays objects out as
     * "{Y}/{m}/{ModelClass}/{media_id}/{file_name}", where the year/month come
     * from the file's created_at and ModelClass is the basename of model_type
     * (e.g. App\Modules\Transaction\Models\Transaction → "Transaction").
     * Returns null when a row lacks the columns needed to build a key.
     */
    private function objectKey(object $row): ?string
    {
        $timestamp = $row->created_at ?: ($row->updated_at ?? null);

        if (empty($row->id) || empty($row->file_name) || empty($row->model_type) || empty($timestamp)) {
            return null;
        }

        $yearMonth = Carbon::parse($timestamp)->format('Y/m');
        $model     = class_basename($row->model_type);

        return "{$yearMonth}/{$model}/{$row->id}/{$row->file_name}";
    }
}
