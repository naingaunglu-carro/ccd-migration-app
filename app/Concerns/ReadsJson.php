<?php

namespace App\Concerns;

use RuntimeException;

trait ReadsJson
{
    /**
     * Read and decode a JSON file into an associative array.
     *
     * @return array<mixed>
     *
     * @throws RuntimeException when the file is missing or contains invalid JSON
     */
    protected function readJson(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("JSON file not found: {$path}");
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            throw new RuntimeException("Invalid JSON in {$path}: ".json_last_error_msg());
        }

        return $decoded;
    }

    /**
     * Read every *.json file under a directory (recursively) into a flat list.
     *
     * Each file may hold a single object or an array of objects; both are
     * flattened into one list. Files are read in path order.
     *
     * @return array<int, array<mixed>>
     *
     * @throws RuntimeException when the directory is missing or a file is invalid
     */
    protected function readJsonDirectory(string $directory): array
    {
        if (! is_dir($directory)) {
            throw new RuntimeException("JSON directory not found: {$directory}");
        }

        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'json') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        $records = [];

        foreach ($files as $file) {
            $decoded = $this->readJson($file);

            if (array_is_list($decoded)) {
                array_push($records, ...$decoded);
            } else {
                $records[] = $decoded;
            }
        }

        return $records;
    }
}
