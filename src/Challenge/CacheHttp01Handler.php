<?php

declare(strict_types=1);

namespace CoyoteCert\Laravel\Challenge;

use CoyoteCert\Enums\AuthorizationChallengeEnum;
use CoyoteCert\Interfaces\ChallengeHandlerInterface;
use Illuminate\Contracts\Cache\Repository;

final class CacheHttp01Handler implements ChallengeHandlerInterface
{
    public function __construct(
        private readonly Repository $cache,
        public readonly string $prefix = 'acme-challenge',
    ) {}

    public function supports(AuthorizationChallengeEnum $type): bool
    {
        return $type === AuthorizationChallengeEnum::HTTP;
    }

    public function deploy(string $domain, string $token, string $keyAuthorization): void
    {
        $this->cache->put("{$this->prefix}:{$token}", $keyAuthorization, 3600);
    }

    public function cleanup(string $domain, string $token): void
    {
        $this->cache->forget("{$this->prefix}:{$token}");
    }
}
