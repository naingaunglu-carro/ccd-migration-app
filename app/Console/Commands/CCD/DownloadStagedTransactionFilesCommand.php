<?php

namespace App\Console\Commands\CCD;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Download dealer_files objects referenced by stg_transaction_files (populated
 * by ccd:stage-transaction-files) from S3 via boto3.
 *
 * Walked latest transaction first (transaction_id descending). Only rows with
 * a matched file_id and no downloaded_at yet are downloaded — re-running only
 * picks up what's left, no checkpoint file needed (unlike
 * dealer:download-tagged-files) since downloaded_at itself is the resume
 * marker. Pass --overwrite to redo everything regardless of downloaded_at.
 *
 * The tag-filtered lookup and manifest build run here; the actual S3 transfer
 * is delegated to scripts/download_dealer_files.py (boto3, same as
 * dealer:download-tagged-files). AWS credentials are taken from the
 * environment — export AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY /
 * AWS_SESSION_TOKEN before running.
 *
 * The python script only reports aggregate ok/skip/missing/denied/failed
 * counts, not per-key success — so after it runs, every candidate row whose
 * local file now exists on disk is marked downloaded_at/downloaded_path,
 * mirroring the same existence check the script itself uses to skip
 * already-downloaded files.
 *
 * Objects archived to Glacier/Deep Archive come back "denied" with
 * InvalidObjectState — not a real failure, but they can't be fetched until
 * restored via S3 RestoreObject. Every attempt's stderr is scanned for these
 * and appended to a deduplicated restore-request list (--archived-log,
 * default storage/logs/transaction-files-archived.log) so nothing gets lost
 * even though the row stays un-downloaded (retries won't help — only an
 * actual S3 restore will).
 */
class DownloadStagedTransactionFilesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'ccd:download-transaction-files
        {--tag_name= : Only stg_transaction_files rows with this tag_name (all when omitted)}
        {--limit= : Max files to download (all when omitted)}
        {--disk= : Only files on this dealer_files.disk (e.g. s3, private_s3)}
        {--bucket=carro.co : S3 bucket to download from}
        {--dest= : Local destination directory (default: storage/app/dealer-files)}
        {--region= : AWS region (default: AWS_DEFAULT_REGION or ap-southeast-1)}
        {--concurrency=8 : Parallel downloads}
        {--overwrite : Re-download even if already marked downloaded / local file exists}
        {--dry-run : Plan only — build the manifest but do not download}
        {--retries=2 : Re-run the download in place up to N times if it exits non-zero}
        {--status-log= : Path to append JSON-lines run status to (default storage/logs/transaction-files-download-status.jsonl)}
        {--archived-log= : Path to a deduplicated restore-request list of archived (InvalidObjectState) S3 keys (default storage/logs/transaction-files-archived.log)}
        {--python= : Path to the venv python (default: .venv/bin/python)}';

    /**
     * @var string
     */
    protected $description = 'Download dealer_files objects referenced by stg_transaction_files from S3 via boto3';

    public function handle(): int
    {
        $tagName = $this->option('tag_name') !== null ? trim((string) $this->option('tag_name')) : null;
        $limit   = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $disk    = $this->option('disk');

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

        $query = DB::table('stg_transaction_files as s')
            ->join('dealer_files as f', 'f.id', '=', 's.file_id')
            ->whereNotNull('s.file_id')
            ->whereNull('f.deleted_at')
            ->when(! $this->option('overwrite'), fn ($q) => $q->whereNull('s.downloaded_at'))
            ->when($tagName !== null, fn ($q) => $q->where('s.tag_name', $tagName))
            ->when($disk, fn ($q) => $q->where('f.disk', $disk))
            ->orderByDesc('s.transaction_id')
            ->select([
                's.id as staging_id', 's.transaction_id',
                'f.id as file_id', 'f.file_name', 'f.model_type', 'f.disk',
                'f.created_at', 'f.updated_at',
            ]);

        if ($limit !== null) {
            $query->limit($limit);
        }

        $count = (clone $query)->count();

        if ($count === 0) {
            $this->info('No stg_transaction_files rows left to download'
                . ($tagName !== null ? " for tag '{$tagName}'" : '')
                . ($disk ? " on disk {$disk}" : '') . '.');

            return self::SUCCESS;
        }

        // Write the S3 keys ("{key}\t{disk}") to a manifest via a cursor, so
        // memory stays flat regardless of how many rows match. Ordered by
        // transaction_id descending — latest transaction first.
        $manifest = tempnam(sys_get_temp_dir(), 'stg_transaction_files_');
        $handle   = fopen($manifest, 'w');
        $skipped  = 0;

        /** @var array<int, array{key: string, transaction_id: int}> $rows staging_id => [key, transaction_id] */
        $rows = [];

        foreach ($query->cursor() as $row) {
            $key = $this->objectKey($row);

            if ($key === null) {
                $skipped++;

                continue;
            }

            fwrite($handle, "{$key}\t{$row->disk}\n");
            $rows[$row->staging_id] = ['key' => $key, 'transaction_id' => $row->transaction_id];
        }

        fclose($handle);

        if ($rows === []) {
            @unlink($manifest);
            $this->error("Matched {$count} row(s) but none had enough data to build an S3 key ({$skipped} skipped).");

            return self::FAILURE;
        }

        $this->info('Matched ' . count($rows) . ' file(s)'
            . ($tagName !== null ? " for tag '{$tagName}'" : '')
            . ($disk ? " on disk {$disk}" : '')
            . " → s3://{$this->option('bucket')} → {$dest}"
            . ($skipped ? " ({$skipped} skipped — missing file_name/model_type/created_at)" : '')
            . ' (transaction_id ' . reset($rows)['transaction_id'] . ' down to ' . end($rows)['transaction_id'] . ')');

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
        // it overrides whatever .env set. Silence boto3's Python-version
        // deprecation warning — the pinned .venv triggers it on every run and
        // it's just noise on stderr, not something actionable here.
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

        $statusLog   = $this->option('status-log') ?: storage_path('logs/transaction-files-download-status.jsonl');
        $archivedLog = $this->option('archived-log') ?: storage_path('logs/transaction-files-archived.log');
        $maxAttempts = 1 + max(0, (int) $this->option('retries'));
        $exit        = 1;
        $newArchived = 0;

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

            $this->logRunStatus($statusLog, [
                'timestamp'    => now()->toIso8601String(),
                'command'      => 'ccd:download-transaction-files',
                'tag_name'     => $tagName,
                'disk'         => $disk ?: null,
                'dest'         => $dest,
                'matched'      => count($rows),
                'attempt'      => $attempt,
                'max_attempts' => $maxAttempts,
                'dry_run'      => (bool) $this->option('dry-run'),
                'exit_code'    => $exit,
                'stats'        => $this->parseStats($output),
            ]);

            if (! $this->option('dry-run')) {
                $newArchived += $this->logArchivedKeys($archivedLog, $output);
            }

            if ($exit === 0) {
                break;
            }
        }

        @unlink($manifest);

        if ($newArchived > 0) {
            $this->newLine();
            $this->warn("{$newArchived} file(s) are archived in S3 (InvalidObjectState) — not downloadable until restored.");
            $this->warn("Restore-request list: {$archivedLog}");
        }

        $updated = 0;

        // Only stamp real (non-dry-run) downloads — verified from disk since
        // the python script only reports aggregate stats, not per-key success.
        if (! $this->option('dry-run')) {
            $now = now();

            foreach ($rows as $stagingId => $meta) {
                $localPath = rtrim($dest, '/') . '/' . $meta['key'];

                if (is_file($localPath) && filesize($localPath) > 0) {
                    DB::table('stg_transaction_files')->where('id', $stagingId)->update([
                        'downloaded_at'   => $now,
                        'downloaded_path' => $localPath,
                        'updated_at'      => $now,
                    ]);

                    $updated++;
                }
            }
        }

        $this->newLine();
        $this->info("Done. Marked {$updated} of " . count($rows) . ' row(s) downloaded in stg_transaction_files.');

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
     * Scan the python script's stderr for "[denied] <key> (InvalidObjectState)"
     * lines — objects archived to Glacier/Deep Archive/Intelligent-Tiering
     * Archive tier, which need an S3 RestoreObject request before they can be
     * fetched, not just a retry — and append any not already recorded to a
     * deduplicated restore-request list at $path (one S3 key per line, ready
     * to feed into `aws s3api restore-object`). Returns how many were new.
     */
    private function logArchivedKeys(string $path, string $output): int
    {
        if (! preg_match_all('/^ \[denied\] (.+) \(InvalidObjectState\)$/m', $output, $matches)) {
            return 0;
        }

        $found = array_unique($matches[1]);

        $dir = dirname($path);

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $existing = is_file($path)
            ? array_flip(array_filter(explode("\n", file_get_contents($path))))
            : [];

        $new = array_diff($found, array_keys($existing));

        if ($new === []) {
            return 0;
        }

        file_put_contents($path, implode("\n", $new) . "\n", FILE_APPEND | LOCK_EX);

        return count($new);
    }

    /**
     * Build the S3 object key for a dealer_files row, same layout as
     * dealer:download-tagged-files: Carro's media path generator lays objects
     * out as "{Y}/{m}/{ModelClass}/{media_id}/{file_name}", where the
     * year/month come from the file's created_at and ModelClass is the
     * basename of model_type. Returns null when a row lacks the columns
     * needed to build a key.
     */
    private function objectKey(object $row): ?string
    {
        $timestamp = $row->created_at ?: ($row->updated_at ?? null);

        if (empty($row->file_id) || empty($row->file_name) || empty($row->model_type) || empty($timestamp)) {
            return null;
        }

        $yearMonth = Carbon::parse($timestamp)->format('Y/m');
        $model     = class_basename($row->model_type);

        return "{$yearMonth}/{$model}/{$row->file_id}/{$row->file_name}";
    }
}
