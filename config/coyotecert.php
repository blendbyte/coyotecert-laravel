<?php

return [
    'provider' => env('COYOTECERT_PROVIDER', 'letsencrypt'),
    'email' => env('COYOTECERT_EMAIL'),
    'challenge' => env('COYOTECERT_CHALLENGE', 'http-01'),
    'storage' => env('COYOTECERT_STORAGE', 'database'),
    'key_type' => env('COYOTECERT_KEY_TYPE', 'EC_P256'),
    'renewal_days' => (int) env('COYOTECERT_RENEWAL_DAYS', 30),
    'domains' => [],
];
