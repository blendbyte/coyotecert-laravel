# Upgrading

## From 1.x to 2.0

2.0 moves to [CoyoteCert 2.x](https://github.com/blendbyte/coyotecert/blob/main/UPGRADING.md). Most applications only need to check their `.env` and published config.

### Configuration

- **Buypass is gone.** Buypass ended its ACME service in April 2026. `COYOTECERT_PROVIDER=buypass` and `buypass-staging` now throw; pick another provider.
- **Renewal timing.** `renewal_days` no longer defaults to 30. Left empty, renewal follows the CA's ARI window, or, without ARI, starts once a third of the certificate's lifetime remains. If you published the config, change the line to `'renewal_days' => env('COYOTECERT_RENEWAL_DAYS'),` to get the new default; a number still works and applies in addition to the ARI window.
- **New options:** `profile`, `preferred_chain`, an `sslcom` provider, and optional `eab_kid` / `eab_hmac` for the `custom` provider. Copy them from the package's `config/coyotecert.php` if you published the config.
- **Preferred chain** must match the root's Common Name exactly (case-insensitive); partial names such as `X1` no longer match.

### Behaviour

- **`cert:renew` asks CoyoteCert whether a certificate is due** instead of comparing the expiry date with `renewal_days`. A certificate that has expired, can't be read, or no longer covers every name in its `coyotecert.identities` entry is renewed. The configured entry is now the source of truth for its names: names on the stored certificate that are not configured are dropped at the next renewal. `--identity` for a name that isn't configured still keeps the stored names.
- `cert:renew` prints `Issued:` for certificates that didn't exist yet, and `Renewed:` for replaced ones.
- **Identities are lower-cased** everywhere (storage keys, events, commands). A certificate that 1.x stored under a mixed-case primary name is not found and is issued again once.
- **dns-01 works.** `COYOTECERT_CHALLENGE=dns-01` now uses the `ChallengeHandlerInterface` bound in the container, as documented; 1.x always threw.
- **Wildcards need dns-01**: issuing `*.example.com` with http-01 fails before contacting the CA.
- **`email`** is sent to the CA as the account contact.
- `IssueCertificateJob` takes `?int $renewalDays = null` (null uses `renewal_days`) and dispatches `CertificateFailed` when it finally fails.
- The scheduled `cert:renew` runs with `withoutOverlapping()` and `onOneServer()`.
- The ACME challenge route is served by a controller, so `php artisan route:cache` works. Tokens outside the base64url alphabet get a 404.

### Storage

- **Database storage now goes through Laravel's query builder** (`CoyoteCert\Laravel\Storage\LaravelDatabaseStorage`) instead of a raw PDO handle. It reads and writes the same rows, so no data migration is needed. It now honours the connection's table prefix, works with every database Laravel supports (including SQL Server), and reconnects after a dropped connection. `CoyoteCert\Laravel\Storage\ReconnectingPdo` is removed.
- If your connection has a table `prefix`, 1.x wrote to the unprefixed table while the migration created the prefixed one. Check which table holds your rows before upgrading.
- The migration now reads `coyotecert.database.connection` and `coyotecert.database.table`, and makes `store_key` the primary key (no `id` column) for new installs. Existing tables keep working: `store_key` only needs to be unique.
- Filesystem storage writes private material with mode `0600`. Run `chmod 600` on existing `*.cert.json` files, which contain the private key.

### Manager

- `CoyoteCertManager`'s constructor takes `(ConfigRepository, Dispatcher, ConnectionResolverInterface, LoggerInterface, Container)`. This only matters if you construct it yourself instead of resolving it from the container.
- New public helpers: `renewalDays()`, `configuredIdentities()` and `normalizeIdentities()`.
