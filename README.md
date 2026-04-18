<img alt="coyotecert-banner-2560x1706" src="https://github.com/user-attachments/assets/d5510075-b62c-462f-a941-1d31b48bbec3" />

# CoyoteCert for Laravel

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg?style=flat-square)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-787cb5?style=flat-square)](https://www.php.net)
[![Laravel](https://img.shields.io/badge/Laravel-11%2B-FF2D20?style=flat-square)](https://laravel.com)

**First-party Laravel integration for [CoyoteCert](https://github.com/blendbyte/coyotecert).** The PHP ACME v2 client for issuing, renewing, and revoking TLS certificates with Let's Encrypt, ZeroSSL, Google Trust Services, and any RFC 8555-compliant CA.

This package wires CoyoteCert into the Laravel ecosystem: config-driven setup, Artisan commands, HTTP-01 challenge served through your app, Laravel Events, queue support, and scheduled automatic renewal. No boilerplate, no separate cron entries, no nginx config changes for HTTP-01.

> **This package is under active development and not yet released.**

---

## What this package adds

### HTTP-01 challenge via Laravel route
The biggest operational improvement. Instead of writing token files to the webroot and configuring nginx to serve them statically, the integration serves ACME challenge tokens directly through your app using the cache store. Works behind load balancers with Redis, requires zero web server changes, and works on read-only filesystems.

### Service Provider + config
A single `config/coyotecert.php` drives provider selection, storage backend, challenge type, email, renewal window, and key type. The service provider binds a pre-configured `CoyoteCert` instance into the container and auto-wires Laravel's logger. No manual wiring anywhere in your app.

### Artisan commands
```bash
php artisan cert:issue example.com     # issue or renew a specific domain
php artisan cert:renew                 # renew all configured domains
php artisan cert:status example.com    # expiry info and SANs
php artisan cert:revoke example.com    # revoke and remove from storage
```
Run inside your app's container context, with access to your config, environment, and service container, unlike the standalone `coyote` CLI.

### Laravel Events
`CertificateIssued`, `CertificateRenewed`, and `CertificateExpiring` events replace closure callbacks. Wire standard Laravel listeners (including queued ones) for reloading nginx, pushing secrets to a vault, sending Slack notifications, or anything else your app needs to do when a certificate changes.

### Queue support
`IssueCertificateJob` wraps the full issuance flow as a dispatchable job. Essential for DNS-01 challenges, where propagation across all authoritative nameservers can take several minutes. Dispatch and let the queue handle the wait rather than blocking a request or a synchronous command.

### Scheduled renewal
The service provider registers a daily scheduled task via Laravel's scheduler. No separate cron entry required beyond the standard `php artisan schedule:run` line your app already has.

### Publishable migration
```bash
php artisan vendor:publish --tag=coyotecert-migrations
```
Delivers the `DatabaseStorage` schema as a standard Laravel migration.

---

## Requirements

- PHP ^8.3
- Laravel ^11.0, ^12.0, or ^13.0
- [blendbyte/coyotecert](https://github.com/blendbyte/coyotecert)

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
