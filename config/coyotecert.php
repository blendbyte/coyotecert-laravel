<?php

return [

    /*
    |--------------------------------------------------------------------------
    | ACME Provider
    |--------------------------------------------------------------------------
    |
    | The Certificate Authority to use for issuing certificates. Must be set
    | before any certificate operations can run.
    |
    | Supported values:
    |   letsencrypt          — Let's Encrypt (production)
    |   letsencrypt-staging  — Let's Encrypt staging (for testing; untrusted certs)
    |   zerossl              — ZeroSSL (requires api_key below)
    |   google               — Google Trust Services (requires eab_kid + eab_hmac below)
    |   sslcom               — SSL.com (EAB credentials below are optional)
    |   custom               — Any ACME v2 CA (requires directory_url below)
    |
    */

    'provider' => env('COYOTECERT_PROVIDER'),

    /*
    |--------------------------------------------------------------------------
    | Account Email
    |--------------------------------------------------------------------------
    |
    | Email address registered as the contact on your ACME account. The CA
    | sends important account notices to this address.
    |
    */

    'email' => env('COYOTECERT_EMAIL'),

    /*
    |--------------------------------------------------------------------------
    | Challenge Type
    |--------------------------------------------------------------------------
    |
    | The ACME challenge method used to prove domain ownership.
    |
    | Supported values:
    |   http-01  — Serves a token over HTTP via Laravel's cache and a built-in
    |              route at /.well-known/acme-challenge/{token}. Works out of
    |              the box with no extra infrastructure.
    |   dns-01   — Uses the ChallengeHandlerInterface implementation bound in
    |              the service container (e.g. one of CoyoteCert's built-in DNS
    |              handlers). Required for wildcard certificates, and useful when
    |              HTTP access is not possible (e.g. internal servers).
    |
    */

    'challenge' => env('COYOTECERT_CHALLENGE', 'http-01'),

    /*
    |--------------------------------------------------------------------------
    | Certificate Storage Driver
    |--------------------------------------------------------------------------
    |
    | Where issued certificates and ACME account keys are persisted.
    |
    | Supported values:
    |   database    — Stores everything in a single database table (recommended).
    |                 Run `php artisan vendor:publish --tag=coyotecert-migrations`
    |                 then `php artisan migrate` before using this driver.
    |   filesystem  — Stores PEM files on disk. Configure the path below.
    |
    */

    'storage' => env('COYOTECERT_STORAGE', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Key Type
    |--------------------------------------------------------------------------
    |
    | The cryptographic key algorithm used when generating certificate keys.
    |
    | Supported values:
    |   EC_P256  — ECDSA P-256 (recommended; smaller, faster, widely supported)
    |   EC_P384  — ECDSA P-384 (higher security margin, slightly larger)
    |   RSA_2048 — RSA 2048-bit (broadest compatibility)
    |   RSA_4096 — RSA 4096-bit (maximum RSA security, slowest)
    |
    */

    'key_type' => env('COYOTECERT_KEY_TYPE', 'EC_P256'),

    /*
    |--------------------------------------------------------------------------
    | Renewal Window (days)
    |--------------------------------------------------------------------------
    |
    | Leave empty (recommended) to let CoyoteCert decide when to renew: inside
    | the renewal window the CA publishes via ARI, or, for CAs without ARI,
    | once a third of the certificate's lifetime remains (30 days for a 90-day
    | certificate, 2 for a 6-day one).
    |
    | Set a number of days to also renew whenever that many days or fewer
    | remain, in addition to the CA's ARI window.
    |
    */

    'renewal_days' => env('COYOTECERT_RENEWAL_DAYS'),

    /*
    |--------------------------------------------------------------------------
    | Certificate Profile
    |--------------------------------------------------------------------------
    |
    | An ACME profile to request (draft-ietf-acme-profiles), such as Let's
    | Encrypt's "classic", "tlsserver" or "shortlived". Leave empty for the
    | CA's default. A profile the CA doesn't list makes issuance fail.
    |
    */

    'profile' => env('COYOTECERT_PROFILE'),

    /*
    |--------------------------------------------------------------------------
    | Preferred Chain
    |--------------------------------------------------------------------------
    |
    | Common Name of the root you want the certificate chain to end at, when
    | the CA offers alternate chains (like certbot's --preferred-chain). It
    | must match the issuer of the chain's topmost certificate exactly
    | (case-insensitive), e.g. "ISRG Root X1". Leave empty for the default.
    |
    */

    'preferred_chain' => env('COYOTECERT_PREFERRED_CHAIN'),

    /*
    |--------------------------------------------------------------------------
    | Automatic Scheduling
    |--------------------------------------------------------------------------
    |
    | When enabled, CoyoteCert registers a daily `cert:renew` command in
    | Laravel's scheduler automatically (on one server, without overlapping). Set to false if you want to manage
    | the schedule yourself (e.g. to change the frequency or add before/after
    | hooks).
    |
    */

    'schedule' => (bool) env('COYOTECERT_SCHEDULE', true),

    /*
    |--------------------------------------------------------------------------
    | Identities
    |--------------------------------------------------------------------------
    |
    | The domains (or IP addresses) to manage certificates for. Each entry is
    | processed independently by cert:renew and the renewal scheduler.
    |
    | A string entry issues a single-domain certificate.
    | An array entry issues one SAN certificate covering all listed domains.
    | The first domain in an array is the primary identity used as the storage
    | key and is what you pass to cert:status, cert:revoke, and --identity.
    | Names are lower-cased. Adding a name to an entry renews its certificate
    | on the next cert:renew run. Wildcards (*.example.com) need dns-01.
    |
    | Examples:
    |   'example.com'                          → single-domain cert
    |   ['example.com', 'www.example.com']     → SAN cert (one cert, two names)
    |
    */

    'identities' => [],

    /*
    |--------------------------------------------------------------------------
    | Provider Credentials
    |--------------------------------------------------------------------------
    |
    | Provider-specific credentials. Only the section matching your chosen
    | provider needs to be filled in.
    |
    */

    'providers' => [

        // ZeroSSL — API key from https://app.zerossl.com/developer
        'zerossl' => [
            'api_key' => env('COYOTECERT_ZEROSSL_API_KEY'),
        ],

        // Google Trust Services — EAB credentials from Google Cloud Console
        'google' => [
            'eab_kid'  => env('COYOTECERT_GOOGLE_EAB_KID'),
            'eab_hmac' => env('COYOTECERT_GOOGLE_EAB_HMAC'),
        ],

        // SSL.com — EAB credentials are optional. `ecc` selects the ECC
        // directory instead of the RSA one.
        'sslcom' => [
            'eab_kid'  => env('COYOTECERT_SSLCOM_EAB_KID'),
            'eab_hmac' => env('COYOTECERT_SSLCOM_EAB_HMAC'),
            'ecc'      => (bool) env('COYOTECERT_SSLCOM_ECC', false),
        ],

        // Custom ACME v2 CA — full URL to the ACME directory endpoint, plus
        // EAB credentials if the CA requires them
        'custom' => [
            'directory_url' => env('COYOTECERT_CUSTOM_DIRECTORY_URL'),
            'eab_kid'       => env('COYOTECERT_CUSTOM_EAB_KID'),
            'eab_hmac'      => env('COYOTECERT_CUSTOM_EAB_HMAC'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Filesystem Storage
    |--------------------------------------------------------------------------
    |
    | Options for the filesystem storage driver. Ignored when using database
    | storage.
    |
    */

    'filesystem' => [
        // Absolute path to the directory where certificate files will be written.
        'path' => env('COYOTECERT_FILESYSTEM_PATH', storage_path('coyotecert')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Storage
    |--------------------------------------------------------------------------
    |
    | Options for the database storage driver. Ignored when using filesystem
    | storage.
    |
    */

    'database' => [
        // Laravel database connection name. Defaults to the application default.
        'connection' => env('COYOTECERT_DB_CONNECTION'),

        // Table name (the connection's table prefix is applied). The migration
        // reads this and the connection above.
        'table' => env('COYOTECERT_DB_TABLE', 'coyote_cert_storage'),
    ],

];
