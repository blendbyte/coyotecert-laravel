<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use CoyoteCert\Enums\KeyType;
use CoyoteCert\Laravel\CoyoteCertManager;
use CoyoteCert\Storage\StorageInterface;
use CoyoteCert\Storage\StoredCertificate;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Mockery;
use Mockery\MockInterface;

it('lists a domain with an active certificate', function (): void {
    config(['coyotecert.domains' => ['example.com']]);

    $cert = new StoredCertificate(
        certificate: '---cert---',
        privateKey: '---key---',
        fullchain: '---fullchain---',
        caBundle: '---ca---',
        issuedAt: new DateTimeImmutable(),
        expiresAt: new DateTimeImmutable('+90 days'),
        domains: ['example.com'],
        keyType: KeyType::EC_P256,
    );

    /** @var MockInterface&StorageInterface $storage */
    $storage = Mockery::mock(StorageInterface::class);
    $storage->shouldReceive('getCertificate')
        ->with('example.com', KeyType::EC_P256)
        ->andReturn($cert);

    /** @var MockInterface&CoyoteCertManager $manager */
    $manager = Mockery::mock(CoyoteCertManager::class);
    $manager->shouldReceive('storage')->andReturn($storage);

    $this->instance(CoyoteCertManager::class, $manager);

    $this->artisan('cert:list')
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('example.com');
});

it('lists a domain that has no certificate yet', function (): void {
    config(['coyotecert.domains' => ['example.com']]);

    /** @var MockInterface&StorageInterface $storage */
    $storage = Mockery::mock(StorageInterface::class);
    $storage->shouldReceive('getCertificate')
        ->with('example.com', KeyType::EC_P256)
        ->andReturn(null);

    /** @var MockInterface&CoyoteCertManager $manager */
    $manager = Mockery::mock(CoyoteCertManager::class);
    $manager->shouldReceive('storage')->andReturn($storage);

    $this->instance(CoyoteCertManager::class, $manager);

    $this->artisan('cert:list')
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('example.com');
});

it('shows expired status for an expired certificate', function (): void {
    config(['coyotecert.domains' => ['example.com']]);

    $expiredCert = new StoredCertificate(
        certificate: '---cert---',
        privateKey: '---key---',
        fullchain: '---fullchain---',
        caBundle: '---ca---',
        issuedAt: new DateTimeImmutable('-120 days'),
        expiresAt: new DateTimeImmutable('-30 days'),
        domains: ['example.com'],
        keyType: KeyType::EC_P256,
    );

    /** @var MockInterface&StorageInterface $storage */
    $storage = Mockery::mock(StorageInterface::class);
    $storage->shouldReceive('getCertificate')
        ->with('example.com', KeyType::EC_P256)
        ->andReturn($expiredCert);

    /** @var MockInterface&CoyoteCertManager $manager */
    $manager = Mockery::mock(CoyoteCertManager::class);
    $manager->shouldReceive('storage')->andReturn($storage);

    $this->instance(CoyoteCertManager::class, $manager);

    $this->artisan('cert:list')
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('Yes');
});

it('warns and returns success when no domains are configured', function (): void {
    config(['coyotecert.domains' => []]);

    $this->artisan('cert:list')
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('No domains configured');
});
