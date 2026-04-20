<?php

declare(strict_types=1);

namespace Tests\Unit;

use CoyoteCert\Laravel\Storage\ReconnectingPdo;
use Mockery;
use Mockery\MockInterface;
use PDO;
use PDOStatement;

it('prepare() delegates to the PDO returned by the closure', function (): void {
    /** @var MockInterface&PDOStatement $statement */
    $statement = Mockery::mock(PDOStatement::class);

    /** @var MockInterface&PDO $pdo */
    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('prepare')
        ->once()
        ->with('SELECT 1', [])
        ->andReturn($statement);

    $proxy = new ReconnectingPdo(fn(): PDO => $pdo);

    expect($proxy->prepare('SELECT 1'))->toBe($statement);
});

it('prepare() passes options to the underlying PDO', function (): void {
    /** @var MockInterface&PDOStatement $statement */
    $statement = Mockery::mock(PDOStatement::class);

    /** @var MockInterface&PDO $pdo */
    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('prepare')
        ->once()
        ->with('INSERT INTO t VALUES (?)', [PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY])
        ->andReturn($statement);

    $proxy = new ReconnectingPdo(fn(): PDO => $pdo);

    expect($proxy->prepare('INSERT INTO t VALUES (?)', [PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY]))->toBe($statement);
});

it('getAttribute() delegates to the PDO returned by the closure', function (): void {
    /** @var MockInterface&PDO $pdo */
    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('getAttribute')
        ->once()
        ->with(PDO::ATTR_DRIVER_NAME)
        ->andReturn('sqlite');

    $proxy = new ReconnectingPdo(fn(): PDO => $pdo);

    expect($proxy->getAttribute(PDO::ATTR_DRIVER_NAME))->toBe('sqlite');
});

it('invokes the closure on every operation to allow transparent reconnection', function (): void {
    $callCount = 0;

    /** @var MockInterface&PDO $pdo */
    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('prepare')->twice()->andReturn(false);

    $proxy = new ReconnectingPdo(function () use ($pdo, &$callCount): PDO {
        $callCount++;

        return $pdo;
    });

    $proxy->prepare('SELECT 1');
    $proxy->prepare('SELECT 2');

    expect($callCount)->toBe(2);
});
