<?php

declare(strict_types=1);

namespace Tests\Unit;

use CoyoteCert\Enums\AuthorizationChallengeEnum;
use CoyoteCert\Laravel\Challenge\CacheHttp01Handler;
use Illuminate\Contracts\Cache\Repository;
use Mockery;
use Mockery\MockInterface;

it('stores key authorization under the correct cache key with 3600-second TTL', function (): void {
    /** @var MockInterface&Repository $cache */
    $cache = Mockery::mock(Repository::class);
    $cache->shouldReceive('put')
        ->once()
        ->with('acme-challenge:abc123', 'abc123.thumbprint', 3600);

    $handler = new CacheHttp01Handler($cache);
    $handler->deploy('example.com', 'abc123', 'abc123.thumbprint');
});

it('forgets the correct cache key on cleanup', function (): void {
    /** @var MockInterface&Repository $cache */
    $cache = Mockery::mock(Repository::class);
    $cache->shouldReceive('forget')
        ->once()
        ->with('acme-challenge:abc123');

    $handler = new CacheHttp01Handler($cache);
    $handler->cleanup('example.com', 'abc123');
});

it('supports the HTTP-01 challenge type', function (): void {
    /** @var Repository $cache */
    $cache   = Mockery::mock(Repository::class);
    $handler = new CacheHttp01Handler($cache);

    expect($handler->supports(AuthorizationChallengeEnum::HTTP))->toBeTrue();
});

it('does not support the DNS-01 challenge type', function (): void {
    /** @var Repository $cache */
    $cache   = Mockery::mock(Repository::class);
    $handler = new CacheHttp01Handler($cache);

    expect($handler->supports(AuthorizationChallengeEnum::DNS))->toBeFalse();
});

it('does not support the TLS-ALPN-01 challenge type', function (): void {
    /** @var Repository $cache */
    $cache   = Mockery::mock(Repository::class);
    $handler = new CacheHttp01Handler($cache);

    expect($handler->supports(AuthorizationChallengeEnum::TLS_ALPN))->toBeFalse();
});

it('uses a custom prefix when provided', function (): void {
    /** @var MockInterface&Repository $cache */
    $cache = Mockery::mock(Repository::class);
    $cache->shouldReceive('put')
        ->once()
        ->with('custom-prefix:abc123', 'key-auth', 3600);

    $handler = new CacheHttp01Handler($cache, 'custom-prefix');
    $handler->deploy('example.com', 'abc123', 'key-auth');
});
