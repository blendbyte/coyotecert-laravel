<?php

declare(strict_types=1);

namespace CoyoteCert\Laravel\Storage;

use CoyoteCert\Enums\KeyType;
use CoyoteCert\Exceptions\StorageException;
use CoyoteCert\Storage\StorageInterface;
use CoyoteCert\Storage\StoredCertificate;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;

/**
 * Database storage on top of Laravel's query builder.
 *
 * Uses the same table layout and keys as CoyoteCert's PDO-based DatabaseStorage, so either
 * can read what the other wrote. Going through the connection, rather than its raw PDO handle,
 * honours the connection's table prefix, works with every driver Laravel supports, and lets
 * Laravel reconnect when a long-running worker's connection has dropped.
 */
final class LaravelDatabaseStorage implements StorageInterface
{
    public function __construct(
        private readonly ConnectionResolverInterface $db,
        private readonly ?string $connection = null,
        private readonly string $table = 'coyote_cert_storage',
    ) {}

    // ── Account key ──────────────────────────────────────────────────────────

    public function hasAccountKey(string $providerSlug): bool
    {
        return $this->get('account:' . $providerSlug . ':pem') !== null;
    }

    public function getAccountKey(string $providerSlug): string
    {
        return $this->get('account:' . $providerSlug . ':pem')
            ?? throw new StorageException('No account key found in database storage.');
    }

    public function getAccountKeyType(string $providerSlug): KeyType
    {
        $value = $this->get('account:' . $providerSlug . ':key_type')
            ?? throw new StorageException('No account key type found in database storage.');

        return KeyType::tryFrom($value)
            ?? throw new StorageException("Unknown account key type [{$value}] in database storage.");
    }

    public function saveAccountKey(string $providerSlug, #[\SensitiveParameter] string $pem, KeyType $type): void
    {
        // Both rows or neither: a key without its type can't be loaded again
        $this->run(fn(ConnectionInterface $connection) => $connection->transaction(function () use ($providerSlug, $pem, $type): void {
            $this->set('account:' . $providerSlug . ':pem', $pem);
            $this->set('account:' . $providerSlug . ':key_type', $type->value);
        }));
    }

    // ── Certificates ─────────────────────────────────────────────────────────

    public function hasCertificate(string $domain, KeyType $keyType): bool
    {
        return $this->get($this->certKey($domain, $keyType)) !== null;
    }

    public function getCertificate(string $domain, KeyType $keyType): ?StoredCertificate
    {
        $json = $this->get($this->certKey($domain, $keyType));

        return $json !== null ? StoredCertificate::fromJson($json) : null;
    }

    public function saveCertificate(string $domain, #[\SensitiveParameter] StoredCertificate $cert): void
    {
        $this->set($this->certKey($domain, $cert->keyType), json_encode($cert->toArray(), JSON_THROW_ON_ERROR));
    }

    public function deleteCertificate(string $domain, KeyType $keyType): void
    {
        $this->run(fn(ConnectionInterface $connection) => $this->query($connection)
            ->where('store_key', $this->certKey($domain, $keyType))
            ->delete());
    }

    // ── Query helpers ────────────────────────────────────────────────────────

    private function certKey(string $domain, KeyType $keyType): string
    {
        return 'cert:' . $domain . ':' . $keyType->value;
    }

    private function get(string $key): ?string
    {
        $value = $this->run(fn(ConnectionInterface $connection): mixed => $this->query($connection)
            ->where('store_key', $key)
            ->value('value'));

        return is_string($value) ? $value : null;
    }

    private function set(string $key, #[\SensitiveParameter] string $value): void
    {
        $this->run(fn(ConnectionInterface $connection) => $this->query($connection)
            ->upsert([['store_key' => $key, 'value' => $value]], ['store_key'], ['value']));
    }

    private function query(ConnectionInterface $connection): Builder
    {
        return $connection->table($this->table);
    }

    /**
     * Database errors become StorageException, as CoyoteCert expects from its storage. The
     * QueryException is not chained: its message inlines the bindings, which hold private keys.
     *
     * @template T
     *
     * @param \Closure(ConnectionInterface): T $callback
     *
     * @return T
     */
    private function run(\Closure $callback): mixed
    {
        try {
            return $callback($this->db->connection($this->connection));
        } catch (QueryException $e) {
            $cause = $e->getPrevious();

            throw new StorageException('Database storage query failed: ' . ($cause?->getMessage() ?? 'unknown error'), previous: $cause);
        }
    }
}
