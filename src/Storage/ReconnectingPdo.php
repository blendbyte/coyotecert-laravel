<?php

declare(strict_types=1);

namespace CoyoteCert\Laravel\Storage;

use PDO;
use PDOStatement;

/**
 * PDO proxy that re-resolves the underlying handle on every operation.
 *
 * Passed to DatabaseStorage (which requires a \PDO) so that long-running
 * queue workers always use the live connection rather than a handle that
 * was captured at construction time and may have gone stale after a DB
 * timeout or reconnect.
 */
final class ReconnectingPdo extends PDO
{
    /** @var \Closure(): PDO */
    private \Closure $connector;

    /** @param \Closure(): PDO $connector */
    public function __construct(\Closure $connector)
    {
        $this->connector = $connector;
    }

    /** @param array<int|string, mixed> $options */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return ($this->connector)()->prepare($query, $options);
    }

    public function getAttribute(int $attribute): mixed
    {
        return ($this->connector)()->getAttribute($attribute);
    }
}
