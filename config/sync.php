<?php

return [

    /*
    |--------------------------------------------------------------------------
    | MySQL client binary
    |--------------------------------------------------------------------------
    |
    | Path to the `mysql` CLI used to export source data into TSV files.
    | Override if it is not on the system PATH (e.g. /usr/local/bin/mysql).
    |
    */

    'mysql_binary' => env('SYNC_MYSQL_BINARY', 'mysql'),

    /*
    |--------------------------------------------------------------------------
    | Raw data output directory
    |--------------------------------------------------------------------------
    |
    | Where exported .tsv files are written. Each sync writes to
    | "{output_path}/{target_table}.tsv".
    |
    */

    'output_path' => env('SYNC_OUTPUT_PATH', storage_path('app/raw_data')),

    /*
    |--------------------------------------------------------------------------
    | Default mysql CLI flags
    |--------------------------------------------------------------------------
    |
    | Flags applied to every export. --batch --raw --quick stream rows as a
    | tab-separated, unbuffered result set (NULLs come through as \N).
    |
    */

    'flags' => ['--batch', '--raw', '--quick'],

    /*
    |--------------------------------------------------------------------------
    | Source connections
    |--------------------------------------------------------------------------
    |
    | Each key here is referenced by the `connection` column on a sync_sources
    | row. Credentials are read from the environment so they never live in the
    | database. SSL mode and connect timeout map to the mysql CLI options.
    |
    */

    'connections' => [

        'dealer' => [
            'host' => env('DEALER_DB_HOST'),
            'port' => env('DEALER_DB_PORT', 3306),
            'database' => env('DEALER_DB_DATABASE', 'dealer'),
            'username' => env('DEALER_DB_USERNAME'),
            'password' => env('DEALER_DB_PASSWORD'),
            'ssl_mode' => env('DEALER_DB_SSL_MODE', 'REQUIRED'),
            'connect_timeout' => env('DEALER_DB_CONNECT_TIMEOUT', 30),
        ],

    ],

];
