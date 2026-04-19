<?php

return [
    'provider' => env('COYOTECERT_PROVIDER'),
    'email' => env('COYOTECERT_EMAIL'),
    'challenge' => env('COYOTECERT_CHALLENGE', 'http-01'),
    'storage' => env('COYOTECERT_STORAGE', 'database'),
    'key_type' => env('COYOTECERT_KEY_TYPE', 'EC_P256'),
    'renewal_days' => (int) env('COYOTECERT_RENEWAL_DAYS', 30),
    'identities' => [],

    'providers' => [
        'zerossl' => [
            'api_key' => env('COYOTECERT_ZEROSSL_API_KEY'),
        ],
        'google' => [
            'eab_kid' => env('COYOTECERT_GOOGLE_EAB_KID'),
            'eab_hmac' => env('COYOTECERT_GOOGLE_EAB_HMAC'),
        ],
        'custom' => [
            'directory_url' => env('COYOTECERT_CUSTOM_DIRECTORY_URL'),
        ],
    ],

    'filesystem' => [
        'path' => env('COYOTECERT_FILESYSTEM_PATH', storage_path('coyotecert')),
    ],

    'database' => [
        'connection' => env('COYOTECERT_DB_CONNECTION'),
        'table' => env('COYOTECERT_DB_TABLE', 'coyote_cert_storage'),
    ],
];
