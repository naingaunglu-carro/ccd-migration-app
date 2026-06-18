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
}
