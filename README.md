<img alt="coyotecert-banner-2560x1706" src="https://github.com/user-attachments/assets/d5510075-b62c-462f-a941-1d31b48bbec3" />

# CoyoteCert for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/blendbyte/coyotecert-laravel.svg?style=flat-square)](https://packagist.org/packages/blendbyte/coyotecert-laravel)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg?style=flat-square)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-787cb5?style=flat-square)](https://www.php.net)
[![Laravel](https://img.shields.io/badge/Laravel-12%2B-FF2D20?style=flat-square)](https://laravel.com)
[![Tests](https://img.shields.io/github/actions/workflow/status/blendbyte/coyotecert-laravel/tests.yml?branch=main&style=flat-square&label=tests)](https://github.com/blendbyte/coyotecert-laravel/actions/workflows/tests.yml)
[![Static Analysis](https://img.shields.io/github/actions/workflow/status/blendbyte/coyotecert-laravel/static-analysis.yml?branch=main&style=flat-square&label=phpstan)](https://github.com/blendbyte/coyotecert-laravel/actions/workflows/static-analysis.yml)
[![Coverage](https://img.shields.io/codecov/c/github/blendbyte/coyotecert-laravel?style=flat-square)](https://codecov.io/gh/blendbyte/coyotecert-laravel)
[![Plumb](https://plumbphp.dev/badges/blendbyte/coyotecert-laravel/composite.svg)](https://plumbphp.dev/blendbyte/coyotecert-laravel)

Free TLS certificates, straight from the ACME catalog. No nginx reloads, no cron entries, no shell scripts, no separate container. Just a service provider, a config file, and a couple of Artisan commands.

---

## Why the Laravel package and not the core library?

[blendbyte/coyotecert](https://github.com/blendbyte/coyotecert) is the raw ACME client. It does the cryptography, talks to the CA, validates your domains, and hands back a certificate. It is a library, so you wire everything yourself: storage, provider config, a challenge handler that can actually serve the token, a cron job to renew, error handling, logging.

This package does all of that for you inside Laravel. Here is the difference:

**Core library on its own:**

```php
// You write this, somewhere, and you maintain it forever.
$cert = CoyoteCert::with(new LetsEncrypt())
    ->email(env('CERT_EMAIL'))
    ->storage(new DatabaseStorage($pdo, 'certs'))
    ->logger(Log::channel('certs'))
    ->identifiers('example.com')
    ->challenge(new MyCustomHttp01Handler('/var/www/.well-known/acme-challenge'))
    ->keyType(KeyType::EC_P256)
    ->onIssued(fn ($cert) => /* reload nginx, notify Slack, update secrets... */)
    ->issueOrRenew();
```

You also need to write `MyCustomHttp01Handler`, manage the webroot directory, configure nginx to serve those files, set up a cron job, handle the `CertificateIssued` side-effects yourself, and restart nginx when the cert changes.

**This package:**

```bash
php artisan cert:issue example.com
```

The service provider wires the storage, the logger, the challenge handler, and the provider from your config file. The HTTP-01 challenge is served by a registered route backed by your cache store. No webroot, no nginx config, no extra cron.

---

## Requirements

- PHP 8.3+
- Laravel 12.0+ or 13.0+
- [blendbyte/coyotecert](https://github.com/blendbyte/coyotecert) 2.x (installed automatically)

Upgrading from 1.x? See [UPGRADING.md](UPGRADING.md).

---

## Installation

```bash
composer require blendbyte/coyotecert-laravel
```

Publish the config file:

```bash
php artisan vendor:publish --tag=coyotecert-config
```

If you want to store certificates in the database, publish and run the migration:

```bash
php artisan vendor:publish --tag=coyotecert-migrations
php artisan migrate
```

---

## Configuration

Open `config/coyotecert.php`. Everything has a sensible default, but you need to pick a provider before the CA will issue anything.

```php
return [
    // Which CA to use. Required — no default, pick one consciously.
    // letsencrypt | letsencrypt-staging | zerossl | google | sslcom | custom
    'provider' => env('COYOTECERT_PROVIDER'),

    // Contact email registered on your ACME account. The CA uses it for
    // important account notices.
    'email' => env('COYOTECERT_EMAIL'),

    // Challenge type. http-01 works out of the box via the built-in route.
    // dns-01 uses the ChallengeHandlerInterface bound in the container.
    'challenge' => env('COYOTECERT_CHALLENGE', 'http-01'),

    // Where to persist certificates and the ACME account key.
    // database | filesystem
    'storage' => env('COYOTECERT_STORAGE', 'database'),

    // Certificate key algorithm.
    // EC_P256 | EC_P384 | RSA_2048 | RSA_4096
    'key_type' => env('COYOTECERT_KEY_TYPE', 'EC_P256'),

    // Empty (recommended): renew when the CA's ARI window opens, or once a
    // third of the lifetime remains. A number of days also renews whenever
    // that many days or fewer remain.
    'renewal_days' => env('COYOTECERT_RENEWAL_DAYS'),

    // ACME profile to request, e.g. Let's Encrypt's "shortlived". Empty = CA default.
    'profile' => env('COYOTECERT_PROFILE'),

    // Root CA common name to prefer when the CA offers alternate chains.
    'preferred_chain' => env('COYOTECERT_PREFERRED_CHAIN'),

    // Register a daily cert:renew in the scheduler.
    'schedule' => (bool) env('COYOTECERT_SCHEDULE', true),

    // Identities that cert:renew processes automatically (without --identity).
    'identities' => [
        // 'example.com',
        // ['example.com', 'www.example.com'],   // one SAN certificate
    ],

    // Provider-specific credentials.
    'providers' => [
        'zerossl' => [
            'api_key' => env('COYOTECERT_ZEROSSL_API_KEY'),
        ],
        'google' => [
            'eab_kid'  => env('COYOTECERT_GOOGLE_EAB_KID'),
            'eab_hmac' => env('COYOTECERT_GOOGLE_EAB_HMAC'),
        ],
        'sslcom' => [
            'eab_kid'  => env('COYOTECERT_SSLCOM_EAB_KID'),     // optional
            'eab_hmac' => env('COYOTECERT_SSLCOM_EAB_HMAC'),    // optional
            'ecc'      => (bool) env('COYOTECERT_SSLCOM_ECC', false),
        ],
        'custom' => [
            'directory_url' => env('COYOTECERT_CUSTOM_DIRECTORY_URL'),
            'eab_kid'       => env('COYOTECERT_CUSTOM_EAB_KID'),  // optional
            'eab_hmac'      => env('COYOTECERT_CUSTOM_EAB_HMAC'), // optional
        ],
    ],

    // Filesystem storage path (when storage = filesystem).
    'filesystem' => [
        'path' => env('COYOTECERT_FILESYSTEM_PATH', storage_path('coyotecert')),
    ],

    // Database storage options (when storage = database).
    'database' => [
        'connection' => env('COYOTECERT_DB_CONNECTION'),        // null = default
        'table'      => env('COYOTECERT_DB_TABLE', 'coyote_cert_storage'),
    ],
];
```

Minimal `.env` to get started with Let's Encrypt:

```env
COYOTECERT_PROVIDER=letsencrypt
COYOTECERT_EMAIL=ops@example.com
COYOTECERT_STORAGE=database
```

---

## HTTP-01 challenge, automatically

When you use `http-01`, the CA needs to fetch a token from
`http://yourdomain.com/.well-known/acme-challenge/<token>`.

This package registers that route automatically. The challenge handler stores the token in your Laravel cache store when the ACME handshake starts, and the route reads it back and serves it as `text/plain`. When the handshake completes, the token is removed.

That means:

- No webroot directory to manage.
- No nginx location block to add.
- Works behind a load balancer as long as the cache store is shared (Redis, Memcached, database).
- Works on read-only filesystems and in containers.
- Works with `php artisan route:cache`.

The only thing you need is a working cache driver and DNS pointing at your app. Let's Encrypt does the rest.

Before asking the CA to validate, CoyoteCert fetches the token from your domain itself. That self-test follows redirects only to http and https on ports 80 and 443, as Let's Encrypt does, so an HTTP-to-HTTPS redirect is fine.

Wildcard names (`*.example.com`) can't be validated over HTTP; they need [DNS-01](#dns-01-challenge).

---

## Artisan commands

### cert:issue

Issue a fresh certificate unconditionally. Use this for first-time setup or to force a re-issue.

```bash
php artisan cert:issue example.com
```

```
Certificate issued successfully.
Identities: example.com
Expires: 2025-07-18 14:22:05
Days remaining: 89
```

Pass several names for one SAN certificate. The first is the primary name it is stored under:

```bash
php artisan cert:issue example.com www.example.com
```

### cert:renew

Renew certificates that are due. By default, processes every identity in `coyotecert.identities`, and issues certificates that don't exist yet.

```bash
php artisan cert:renew
```

```
Renewed: example.com
Issued: api.example.com
Skipped: www.example.com (61 days remaining)
```

A certificate is due when:

- the CA's [ARI](https://datatracker.ietf.org/doc/rfc9773/) renewal window has opened (Let's Encrypt and most other CAs publish one), or
- the CA has no ARI and a third of the certificate's lifetime remains (30 days for a 90-day certificate), or
- `renewal_days` is set and that many days or fewer remain, or
- it has expired, can't be read, or no longer covers every name configured for it. Add a name to an entry in `coyotecert.identities` and the next run renews it.

Renew a single identity. A configured entry with that primary name is renewed with all its names; otherwise the names on the stored certificate are kept:

```bash
php artisan cert:renew --identity=example.com
```

Force re-issue regardless of expiry:

```bash
php artisan cert:renew --identity=example.com --force
```

If an identity fails, the command reports the error, continues to the next one, and exits with a non-zero status code at the end. Your monitoring picks that up.

### cert:list

Show the status of every identity in `coyotecert.identities` at a glance.

```bash
php artisan cert:list
```

```
+------------------+---------------------+---------------------+----------------+---------+
| Identity         | Issued At           | Expires At          | Days Remaining | Expired |
+------------------+---------------------+---------------------+----------------+---------+
| example.com      | 2025-04-19 14:22:05 | 2025-07-18 14:22:05 | 89             | No      |
| www.example.com  | Not issued          | Not issued          | -              | -       |
+------------------+---------------------+---------------------+----------------+---------+
```

### cert:status

Check the current certificate for an identity without hitting the CA at all.

```bash
php artisan cert:status example.com
```

```
+----------------+------------------------------+
| Field          | Value                        |
+----------------+------------------------------+
| Identity       | example.com                  |
| Identifiers    | example.com                  |
| Key Type       | EC_P256                       |
| Issued At      | 2025-04-19 14:22:05          |
| Expires At     | 2025-07-18 14:22:05          |
| Days Remaining | 89                           |
| Expired        | No                           |
+----------------+------------------------------+
```

### cert:revoke

Revoke a certificate and remove it from storage. Useful when rotating for security reasons.

```bash
php artisan cert:revoke example.com
```

```
Certificate for [example.com] has been revoked and deleted.
```

Pass an ACME revocation reason code (RFC 8555 section 7.2):

```bash
php artisan cert:revoke example.com --reason=1  # keyCompromise
```

---

## Events

Four events are dispatched through the Laravel event bus, so you can react to certificate changes with standard Laravel listeners.

| Event | When |
|---|---|
| `CertificateIssued` | After any successful issuance |
| `CertificateRenewed` | After a certificate is replaced (fires alongside `CertificateIssued`) |
| `CertificateExpiring` | When `cert:renew` finds an existing certificate due for renewal, before it renews |
| `CertificateFailed` | When `cert:issue`, `cert:renew` or `IssueCertificateJob` fails |

`CertificateIssued`, `CertificateRenewed` and `CertificateExpiring` carry a `StoredCertificate $certificate` and a `string $identity` (the lower-cased primary name). `CertificateExpiring` also carries `int $daysUntilExpiry`. `CertificateFailed` carries `string $identity` and `Throwable $exception`.

### Reloading nginx after issuance

```php
// app/Listeners/ReloadNginxOnCertChange.php
use CoyoteCert\Laravel\Events\CertificateIssued;

class ReloadNginxOnCertChange
{
    public function handle(CertificateIssued $event): void
    {
        // Write the new cert to disk then reload nginx.
        $cert = $event->certificate;
        file_put_contents('/etc/nginx/certs/' . $event->identity . '.pem', $cert->fullchain);
        shell_exec('nginx -s reload');
    }
}
```

Register it in `EventServiceProvider` (or with `#[AsEventListener]` in Laravel 11+):

```php
protected $listen = [
    CertificateIssued::class => [
        ReloadNginxOnCertChange::class,
    ],
];
```

### Sending expiry alerts

```php
use CoyoteCert\Laravel\Events\CertificateExpiring;

class SendExpiryAlert
{
    public function handle(CertificateExpiring $event): void
    {
        // This fires before the renewal attempt, so you know a renewal
        // is in progress. If it succeeds, CertificateRenewed fires next.
        // If it fails, your monitoring has the failure and this alert
        // gives you a head start.
        Notification::route('mail', 'ops@example.com')
            ->notify(new CertExpiringNotification($event->identity, $event->daysUntilExpiry));
    }
}
```

### Queued listeners

The certificate events work with queued listeners out of the box because they carry a `StoredCertificate`, which is a plain readonly class that serialises cleanly.

```php
use Illuminate\Contracts\Queue\ShouldQueue;

class PushCertToVault implements ShouldQueue
{
    public function handle(CertificateIssued $event): void
    {
        // Push the new cert to HashiCorp Vault, AWS Secrets Manager, etc.
        Vault::write('secret/tls/' . $event->identity, [
            'cert'    => $event->certificate->certificate,
            'key'     => $event->certificate->privateKey,
            'chain'   => $event->certificate->fullchain,
        ]);
    }
}
```

---

## Queue job

For DNS-01 challenges, or any case where you want issuance to happen off the main process, dispatch `IssueCertificateJob`:

```php
use CoyoteCert\Laravel\Jobs\IssueCertificateJob;

// Issue, or renew when due (same rules as cert:renew).
IssueCertificateJob::dispatch('example.com');

// A SAN certificate.
IssueCertificateJob::dispatch(['example.com', 'www.example.com']);

// Also renew whenever 14 days or fewer remain (overrides renewal_days).
IssueCertificateJob::dispatch('example.com', renewalDays: 14);
```

The job calls `issueOrRenew()` on the manager. If the certificate does not need renewal it exits immediately. If it does, it issues and fires the events. When the job has used up its attempts, `CertificateFailed` is dispatched.

---

## Scheduled renewal

The service provider registers a daily `cert:renew` in Laravel's scheduler automatically, with `withoutOverlapping()` and `onOneServer()` so several app servers don't renew the same certificate at once. You do not need to add anything to `routes/console.php` or `app/Console/Kernel.php`. As long as your app has the standard scheduler cron entry (`* * * * * php artisan schedule:run >> /dev/null 2>&1`), renewals happen on their own.

Identities are read from `coyotecert.identities` in your config. Add every identity you want to auto-renew:

```php
'identities' => [
    'example.com',
    ['shop.example.com', 'www.shop.example.com'],  // one SAN certificate
    'api.example.com',
],
```

Set `COYOTECERT_SCHEDULE=false` to schedule `cert:renew` yourself.

---

## Storage backends

### Database (default)

Stores the ACME account key and all certificates in a `coyote_cert_storage` table, through Laravel's query builder. Works with any database Laravel supports, honours the connection's table prefix, and reconnects when a long-running worker's connection has dropped. Run the migration once (it uses the connection and table configured below):

```bash
php artisan vendor:publish --tag=coyotecert-migrations
php artisan migrate
```

Use a non-default connection if your certs should live in a separate database:

```env
COYOTECERT_DB_CONNECTION=certs_db
```

### Filesystem

Writes each certificate as a set of files under a directory. Good for single-server setups or when you need files on disk directly.

```env
COYOTECERT_STORAGE=filesystem
COYOTECERT_FILESYSTEM_PATH=/etc/certs
```

---

## Certificate authorities

Set `COYOTECERT_PROVIDER` to one of these:

| Value | CA | Notes |
|---|---|---|
| `letsencrypt` | Let's Encrypt (production) | No extra config. |
| `letsencrypt-staging` | Let's Encrypt (staging) | For testing. Issues untrusted certs. |
| `zerossl` | ZeroSSL | Requires `COYOTECERT_ZEROSSL_API_KEY`. |
| `google` | Google Trust Services | Requires `COYOTECERT_GOOGLE_EAB_KID` and `COYOTECERT_GOOGLE_EAB_HMAC`. |
| `sslcom` | SSL.com | EAB credentials optional. `COYOTECERT_SSLCOM_ECC=true` for the ECC directory. |
| `custom` | Any RFC 8555 CA | Requires `COYOTECERT_CUSTOM_DIRECTORY_URL`; EAB credentials optional. |

Buypass ended its ACME service in April 2026; `buypass` and `buypass-staging` are no longer supported.

---

## DNS-01 challenge

DNS-01 needs API access to your DNS provider, so you bind the handler yourself. It is required for wildcard certificates. CoyoteCert ships handlers for Cloudflare, DigitalOcean, Hetzner (Cloud API), AWS Route 53, ClouDNS, and arbitrary shell commands. Bind one in a service provider:

```php
use CoyoteCert\Challenge\Dns\CloudflareDns01Handler;
use CoyoteCert\Interfaces\ChallengeHandlerInterface;

$this->app->bind(ChallengeHandlerInterface::class, fn () => new CloudflareDns01Handler(
    apiToken: config('services.cloudflare.token'),
));
```

Then set `COYOTECERT_CHALLENGE=dns-01` in your `.env`. The manager resolves the binding whenever it builds a certificate request.

```php
'identities' => [
    ['example.com', '*.example.com'],
],
```

For any other DNS provider, implement `ChallengeHandlerInterface` (or extend `CoyoteCert\Challenge\Dns\AbstractDns01Handler`, which waits for the record to propagate):

```php
use CoyoteCert\Enums\AuthorizationChallengeEnum;
use CoyoteCert\Interfaces\ChallengeHandlerInterface;

class MyDnsHandler implements ChallengeHandlerInterface
{
    public function supports(AuthorizationChallengeEnum $type): bool
    {
        return $type === AuthorizationChallengeEnum::DNS;
    }

    public function deploy(string $domain, string $token, string $keyAuthorization): void
    {
        // Create the _acme-challenge TXT record for $domain with the value $keyAuthorization.
    }

    public function cleanup(string $domain, string $token): void
    {
        // Delete the TXT record once the challenge is complete.
    }
}
```

---

## Maintained by Blendbyte

<br>

<p align="center">
  <a href="https://www.blendbyte.com">
    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="https://www.blendbyte.com/logo_horizontal_light.png">
      <img src="https://www.blendbyte.com/logo_horizontal.png" alt="Blendbyte" width="360">
    </picture>
  </a>
</p>

<p align="center">
  <strong><a href="https://www.blendbyte.com">Blendbyte</a></strong> builds cloud infrastructure, web apps, and developer tools.<br>
  We've been shipping software to production for 20+ years.
</p>

<p align="center">
  This package runs in our own stack, which is why we keep it maintained.<br>
  Issues and PRs get read. Good ones get merged.
</p>

<br>

<p align="center">
  <a href="https://www.blendbyte.com">blendbyte.com</a> · <a href="mailto:hello@blendbyte.com">hello@blendbyte.com</a>
</p>
