<img alt="coyotecert-banner-2560x1706" src="https://github.com/user-attachments/assets/d5510075-b62c-462f-a941-1d31b48bbec3" />

# CoyoteCert for Laravel

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg?style=flat-square)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-787cb5?style=flat-square)](https://www.php.net)
[![Laravel](https://img.shields.io/badge/Laravel-12%2B-FF2D20?style=flat-square)](https://laravel.com)

Free TLS certificates, straight from the ACME catalog. No nginx reloads, no cron entries, no shell scripts, no separate container. Just a service provider, a config file, and a couple of Artisan commands.

> **This package is under active development and not yet released.**

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
    ->issueOrRenew(30);
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

Open `config/coyotecert.php`. Everything has a sensible default but you need to set at least your email address before the CA will issue anything.

```php
return [
    // Which CA to use.
    // letsencrypt | letsencrypt-staging | buypass | buypass-staging
    // zerossl | google | custom
    'provider' => env('COYOTECERT_PROVIDER', 'letsencrypt'),

    // Your contact email. The CA uses this to warn you about expiring
    // certificates and account issues. Required.
    'email' => env('COYOTECERT_EMAIL'),

    // Challenge type. http-01 works out of the box via the built-in route.
    // dns-01 requires you to bind a custom ChallengeHandlerInterface.
    'challenge' => env('COYOTECERT_CHALLENGE', 'http-01'),

    // Where to persist certificates and the ACME account key.
    // database | filesystem
    'storage' => env('COYOTECERT_STORAGE', 'database'),

    // Certificate key algorithm.
    // EC_P256 | EC_P384 | RSA_2048 | RSA_4096
    'key_type' => env('COYOTECERT_KEY_TYPE', 'EC_P256'),

    // How many days before expiry to trigger renewal in cert:renew.
    'renewal_days' => (int) env('COYOTECERT_RENEWAL_DAYS', 30),

    // Domains that cert:renew processes automatically (without --domain).
    'domains' => [
        // 'example.com',
        // 'www.example.com',
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
        'custom' => [
            'directory_url' => env('COYOTECERT_CUSTOM_DIRECTORY_URL'),
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

The only thing you need is a working cache driver and DNS pointing at your app. Let's Encrypt does the rest.

---

## Artisan commands

### cert:issue

Issue a fresh certificate unconditionally. Use this for first-time setup or to force a re-issue.

```bash
php artisan cert:issue example.com
```

```
Certificate issued successfully.
Expires: 2025-07-18 14:22:05
Days remaining: 89
```

### cert:renew

Renew certificates that are within the renewal window. By default, processes every domain in `coyotecert.domains`.

```bash
php artisan cert:renew
```

```
Renewed: example.com
Renewed: www.example.com
```

Renew a single domain without touching the config:

```bash
php artisan cert:renew --domain=example.com
```

Force re-issue regardless of expiry:

```bash
php artisan cert:renew --domain=example.com --force
```

If a domain fails, the command reports the error, continues to the next domain, and exits with a non-zero status code at the end. Your monitoring picks that up.

### cert:status

Check the current certificate for a domain without hitting the CA at all.

```bash
php artisan cert:status example.com
```

```
+----------------+------------------------------+
| Field          | Value                        |
+----------------+------------------------------+
| Domain         | example.com                  |
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

Three events are dispatched through the Laravel event bus, so you can react to certificate changes with standard Laravel listeners.

| Event | When |
|---|---|
| `CertificateIssued` | After any successful issuance |
| `CertificateRenewed` | After a certificate is replaced (fires alongside `CertificateIssued`) |
| `CertificateExpiring` | When `cert:renew` detects a cert is within the renewal window, before it renews |

All three carry a `StoredCertificate $certificate` and a `string $domain`. `CertificateExpiring` also carries `int $daysUntilExpiry`.

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
        file_put_contents('/etc/nginx/certs/' . $event->domain . '.pem', $cert->fullchain);
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
            ->notify(new CertExpiringNotification($event->domain, $event->daysUntilExpiry));
    }
}
```

### Queued listeners

All three events work with queued listeners out of the box because they carry a `StoredCertificate`, which is a plain readonly class that serialises cleanly.

```php
use Illuminate\Contracts\Queue\ShouldQueue;

class PushCertToVault implements ShouldQueue
{
    public function handle(CertificateIssued $event): void
    {
        // Push the new cert to HashiCorp Vault, AWS Secrets Manager, etc.
        Vault::write('secret/tls/' . $event->domain, [
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

// Issue or renew with the default 30-day renewal window.
IssueCertificateJob::dispatch('example.com');

// Override the renewal window.
IssueCertificateJob::dispatch('example.com', renewalDays: 14);
```

The job calls `issueOrRenew()` on the manager. If the certificate does not need renewal it exits immediately. If it does, it issues and fires the events.

---

## Scheduled renewal

The service provider registers a daily `cert:renew` in Laravel's scheduler automatically. You do not need to add anything to `routes/console.php` or `app/Console/Kernel.php`. As long as your app has the standard scheduler cron entry (`* * * * * php artisan schedule:run >> /dev/null 2>&1`), renewals happen on their own.

Domains are read from `coyotecert.domains` in your config. Add every domain you want to auto-renew:

```php
'domains' => [
    'example.com',
    'www.example.com',
    'api.example.com',
],
```

---

## Storage backends

### Database (default)

Stores the ACME account key and all certificates in a `coyote_cert_storage` table. Works with any database Laravel supports. Run the migration once:

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
| `letsencrypt` | Let's Encrypt (production) | Default. No extra config. |
| `letsencrypt-staging` | Let's Encrypt (staging) | For testing. Issues untrusted certs. |
| `buypass` | Buypass Go SSL (production) | No extra config. |
| `buypass-staging` | Buypass Go SSL (staging) | For testing. |
| `zerossl` | ZeroSSL | Requires `COYOTECERT_ZEROSSL_API_KEY`. |
| `google` | Google Trust Services | Requires `COYOTECERT_GOOGLE_EAB_KID` and `COYOTECERT_GOOGLE_EAB_HMAC`. |
| `custom` | Any RFC 8555 CA | Requires `COYOTECERT_CUSTOM_DIRECTORY_URL`. |

---

## DNS-01 challenge

DNS-01 is not wired by default because it requires a DNS provider API, which varies per setup. You implement `ChallengeHandlerInterface` and bind it yourself:

```php
use CoyoteCert\Enums\AuthorizationChallengeEnum;
use CoyoteCert\Interfaces\ChallengeHandlerInterface;

class CloudflareDnsHandler implements ChallengeHandlerInterface
{
    public function supports(AuthorizationChallengeEnum $type): bool
    {
        return $type === AuthorizationChallengeEnum::DNS;
    }

    public function deploy(string $domain, string $token, string $keyAuthorization): void
    {
        // Create a _acme-challenge TXT record via the Cloudflare API.
        Cloudflare::dns()->createTxtRecord('_acme-challenge.' . $domain, $keyAuthorization);
    }

    public function cleanup(string $domain, string $token): void
    {
        // Delete the TXT record once the challenge is complete.
        Cloudflare::dns()->deleteTxtRecord('_acme-challenge.' . $domain);
    }
}
```

Bind it in a service provider:

```php
use CoyoteCert\Interfaces\ChallengeHandlerInterface;

$this->app->bind(ChallengeHandlerInterface::class, CloudflareDnsHandler::class);
```

Then set `COYOTECERT_CHALLENGE=dns-01` in your `.env`. The manager resolves the binding automatically.

---

## License

MIT

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
