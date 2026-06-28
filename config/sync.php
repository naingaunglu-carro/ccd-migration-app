<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Client binaries
    |--------------------------------------------------------------------------
    |
    | CLI used to export source data, keyed by driver. A source connection
    | picks one of these via its `driver` key.
    |
    */

    'binaries' => [
        'mysql' => env('SYNC_MYSQL_BINARY', 'mysql'),
        'pgsql' => env('SYNC_PSQL_BINARY', 'psql'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Raw data output directory
    |--------------------------------------------------------------------------
    |
    | Where exported files are written. Each download writes to
    | "{output_path}/{target_table}/{timestamp}.{file_type}".
    |
    */

    'output_path' => env('SYNC_OUTPUT_PATH', storage_path('app/raw_data')),

    /*
    |--------------------------------------------------------------------------
    | Per-driver export defaults
    |--------------------------------------------------------------------------
    |
    | Each driver exports in its native, streamable format:
    |   - mysql: `mysql --batch --raw` → tab-separated, header row, \N for NULL
    |   - pgsql: `psql \copy ... CSV HEADER` → comma-separated, header row
    |
    */

    'drivers' => [
        'mysql' => [
            'file_type' => 'tsv',
            // --batch (no --raw) tab-separates rows AND escapes \t, \n, \\ inside
            //   values, so multi-line text columns (e.g. notes) can't break the TSV
            //   structure — the importer un-escapes these back. Keeping --raw would
            //   emit literal newlines that split one row into many, shifting columns.
            // --default-character-set=utf8mb4 forces UTF-8 output; without it the
            //   client falls back to latin1 and mangles multibyte chars (é, ¥, ô …)
            //   into invalid byte sequences that Postgres rejects on import.
            'flags' => ['--batch', '--quick', '--default-character-set=utf8mb4'],
        ],
        'pgsql' => [
            'file_type' => 'csv',
            'flags' => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Source connections
    |--------------------------------------------------------------------------
    |
    | Each key is referenced by the `connection` column on a sync_sources row.
    | `driver` selects mysql or pgsql. Credentials come from the environment so
    | they never live in the database.
    |
    */

    'connections' => [

        'dealer' => [
            'driver' => 'mysql',
            'host' => env('DEALER_DB_HOST'),
            'port' => env('DEALER_DB_PORT', 3306),
            'database' => env('DEALER_DB_DATABASE', 'dealer'),
            'username' => env('DEALER_DB_USERNAME'),
            'password' => env('DEALER_DB_PASSWORD'),
            'ssl_mode' => env('DEALER_DB_SSL_MODE', 'REQUIRED'),
            'connect_timeout' => env('DEALER_DB_CONNECT_TIMEOUT', 30),
        ],

        // Example Postgres source — duplicate and adjust per real source.
        'pg_example' => [
            'driver' => 'pgsql',
            'host' => env('PG_EXAMPLE_DB_HOST'),
            'port' => env('PG_EXAMPLE_DB_PORT', 5432),
            'database' => env('PG_EXAMPLE_DB_DATABASE'),
            'username' => env('PG_EXAMPLE_DB_USERNAME'),
            'password' => env('PG_EXAMPLE_DB_PASSWORD'),
            'ssl_mode' => env('PG_EXAMPLE_DB_SSL_MODE', 'require'),
            'connect_timeout' => env('PG_EXAMPLE_DB_CONNECT_TIMEOUT', 30),
        ],

    ],

];
