<?php

namespace App\Services\DataSync;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Streams the physical objects recorded in dealer_files from one filesystem disk
 * (typically s3) to another (typically local), one keyset page at a time.
 *
 * The table can hold 100M+ rows, so nothing is ever loaded in bulk: rows are
 * pulled in id-keyset pages and each object is copied with a stream (read/write
 * handles), never read into memory. Copies are idempotent — an object already
 * present on the target is skipped unless `overwrite` is set — which makes the
 * whole run resumable: re-running simply picks up where it left off.
 */
class DealerFileDownloadService
{
    /**
     * Rows pulled from dealer_files per query while streaming a range.
     */
    private const READ_CHUNK = 1000;

    /**
     * Copy every dealer_files object whose id falls in [$idFrom, $idTo] from the
     * source disk to the target disk, mirroring the object key on both sides.
     *
     * @param  array{since?:?string,collection?:?string,model_type?:?string,file_name?:?string,overwrite?:bool}  $options
     * @return array{seen:int,copied:int,skipped:int,missing:int,failed:int,bytes:int}
     */
    public function downloadRange(
        int $idFrom,
        int $idTo,
        string $sourceDisk,
        string $targetDisk,
        string $keyPrefix = '',
        array $options = [],
    ): array {
        $source = Storage::disk($sourceDisk);
        $target = Storage::disk($targetDisk);
        $overwrite = (bool) ($options['overwrite'] ?? false);

        $stats = ['seen' => 0, 'copied' => 0, 'skipped' => 0, 'missing' => 0, 'failed' => 0, 'bytes' => 0];
        $cursor = $idFrom - 1;

        while (true) {
            $rows = DB::table('dealer_files')
                ->where('id', '>', $cursor)
                ->where('id', '<=', $idTo)
                ->when($options['collection'] ?? null, fn ($q, $v) => $q->where('collection_name', $v))
                ->when($options['model_type'] ?? null, fn ($q, $v) => $q->where('model_type', $v))
                ->when($options['file_name'] ?? null, fn ($q, $v) => $q->where('file_name', $v))
                ->when($options['since'] ?? null, fn ($q, $v) => $q->where('updated_at', '>=', $v))
                ->orderBy('id')
                ->limit(self::READ_CHUNK)
                ->get(['id', 'file_name', 'size']);

            if ($rows->isEmpty()) {
                break;
            }

            foreach ($rows as $row) {
                $stats['seen']++;
                $key = $this->objectKey($row, $keyPrefix);

                if ($key === null) {
                    $stats['failed']++;

                    continue;
                }

                try {
                    if (! $overwrite && $target->exists($key)) {
                        $stats['skipped']++;

                        continue;
                    }

                    if (! $source->exists($key)) {
                        $stats['missing']++;
                        Log::warning('dealer-file-download: object missing on source', [
                            'id' => $row->id, 'disk' => $sourceDisk, 'key' => $key,
                        ]);

                        continue;
                    }

                    $this->stream($source, $target, $key);

                    $stats['copied']++;
                    $stats['bytes'] += (int) ($row->size ?? 0);
                } catch (Throwable $e) {
                    $stats['failed']++;
                    Log::error('dealer-file-download: copy failed', [
                        'id' => $row->id, 'key' => $key, 'error' => $e->getMessage(),
                    ]);
                }
            }

            $cursor = (int) $rows->last()->id;

            if ($rows->count() < self::READ_CHUNK) {
                break;
            }
        }

        return $stats;
    }

    /**
     * Stream one object from the source disk to the target disk without ever
     * holding the whole file in memory. The read handle is always closed; the
     * target adapter takes ownership of the stream during writeStream().
     */
    protected function stream(Filesystem $source, Filesystem $target, string $key): void
    {
        $handle = $source->readStream($key);

        if ($handle === null) {
            throw new \RuntimeException("Unable to open read stream for [{$key}].");
        }

        try {
            $target->writeStream($key, $handle);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /**
     * Derive the storage object key for a dealer_files row.
     *
     * Spatie Media Library stores each file under "{media_id}/{file_name}"; here
     * the row id is that original media id (imported columns keep their source
     * names). An optional prefix nests the mirror under a sub-folder. Returns
     * null when the row lacks the columns to build a key (e.g. a null file_name).
     */
    protected function objectKey(object $row, string $keyPrefix): ?string
    {
        if (empty($row->id) || empty($row->file_name)) {
            return null;
        }

        $prefix = trim($keyPrefix, '/');
        $prefix = $prefix === '' ? '' : $prefix.'/';

        return $prefix.$row->id.'/'.$row->file_name;
    }
}
