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

        $count = (clone $query)->count();

        if ($count === 0) {
            $this->info("No dealer_files rows match tags [{$tags->implode(', ')}]"
                . ($disk ? " on disk {$disk}." : '.'));

            return self::SUCCESS;
        }

        // Write the S3 keys ("{key}\t{disk}") to a manifest, chunked so memory
        // stays flat regardless of how many rows match.
        $manifest = tempnam(sys_get_temp_dir(), 'dealer_files_');
        $handle   = fopen($manifest, 'w');
        $skipped  = 0;

        $query->orderBy('id')->chunkById(2000, function ($rows) use ($handle, &$skipped) {
            foreach ($rows as $row) {
                $key = $this->objectKey($row);

                if ($key === null) {
                    $skipped++;

                    continue;
                }

                fwrite($handle, "{$key}\t{$row->disk}\n");
            }
        });

        fclose($handle);

        $this->info("Matched {$count} file(s) for tags [{$tags->implode(', ')}]"
            . ($disk ? " on disk {$disk}" : '')
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
        $env = ['AWS_DEFAULT_REGION' => $region, 'AWS_REGION' => $region];

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

        $process = new Process($args, base_path(), $env, null, null);

        $exit = $process->run(function ($type, $buffer) {
            $this->output->write($buffer);
        });

        @unlink($manifest);

        return $exit === 0 ? self::SUCCESS : self::FAILURE;
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
