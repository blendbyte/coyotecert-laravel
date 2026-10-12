<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use CoyoteCert\Enums\KeyType;
use CoyoteCert\Exceptions\StorageException;
use CoyoteCert\Laravel\CoyoteCertManager;
use CoyoteCert\Storage\StorageInterface;
use CoyoteCert\Storage\StoredCertificate;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Mockery;
use Mockery\MockInterface;

/**
 * @param list<non-empty-list<string>> $configured
 * @param array<string, StoredCertificate|\Throwable|null> $stored
 */
function listManager(array $configured, array $stored = []): void
{
    /** @var MockInterface&StorageInterface $storage */
    $storage = Mockery::mock(StorageInterface::class);

    foreach ($stored as $primary => $value) {
        $expectation = $storage->shouldReceive('getCertificate')->with($primary, KeyType::EC_P256);
        $value instanceof \Throwable ? $expectation->andThrow($value) : $expectation->andReturn($value);
    }

    /** @var MockInterface&CoyoteCertManager $manager */
    $manager = Mockery::mock(CoyoteCertManager::class);
    $manager->shouldReceive('configuredIdentities')->andReturn($configured);
    $manager->shouldReceive('resolveKeyType')->andReturn(KeyType::EC_P256);
    $manager->shouldReceive('storage')->andReturn($storage);

    test()->instance(CoyoteCertManager::class, $manager);
}

it('lists an identity with an active certificate', function (): void {
    listManager([['example.com']], ['example.com' => fakeCertificate()]);

    $this->artisan('cert:list')
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('example.com');
});

it('lists an identity that has no certificate yet', function (): void {
    listManager([['example.com']], ['example.com' => null]);

    $this->artisan('cert:list')
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('Not issued');
});

it('shows expired status for an expired certificate', function (): void {
    listManager([['example.com']], ['example.com' => fakeCertificate(expiresAt: '-30 days', issuedAt: '-120 days')]);

    $this->artisan('cert:list')
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('Yes');
});

it('lists a SAN identity group from config', function (): void {
    listManager([['example.com', 'www.example.com']], ['example.com' => fakeCertificate(['example.com', 'www.example.com'])]);

    $this->artisan('cert:list')
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('example.com, www.example.com');
});

it('marks a certificate that cannot be read and keeps listing', function (): void {
    listManager(
        [['broken.example.com'], ['example.com']],
        ['broken.example.com' => new StorageException('bad json'), 'example.com' => fakeCertificate()],
    );

    $this->artisan('cert:list')
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('Unreadable');
});

it('fails cleanly on invalid configuration', function (): void {
    /** @var MockInterface&CoyoteCertManager $manager */
    $manager = Mockery::mock(CoyoteCertManager::class);
    $manager->shouldReceive('configuredIdentities')->andReturn([['example.com']]);
    $manager->shouldReceive('resolveKeyType')->andThrow(new InvalidArgumentException('Invalid key type [X].'));

    $this->instance(CoyoteCertManager::class, $manager);

    $this->artisan('cert:list')
        ->assertExitCode(Command::FAILURE)
        ->expectsOutputToContain('Invalid key type [X].');
});

it('warns and returns success when no identities are configured', function (): void {
    listManager([]);

    $this->artisan('cert:list')
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('No identities configured');
});
