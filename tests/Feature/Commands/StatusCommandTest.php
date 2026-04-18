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

it('shows the certificate status table for a known domain', function (): void {
    $cert = new StoredCertificate(
        certificate: '---cert---',
        privateKey: '---key---',
        fullchain: '---fullchain---',
        caBundle: '---ca---',
        issuedAt: new DateTimeImmutable('-1 day'),
        expiresAt: new DateTimeImmutable('+89 days'),
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

    $this->artisan('cert:status', ['domain' => 'example.com'])
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('example.com');
});

it('returns failure and prints an error when no certificate is found', function (): void {
    /** @var MockInterface&StorageInterface $storage */
    $storage = Mockery::mock(StorageInterface::class);
    $storage->shouldReceive('getCertificate')->andReturn(null);

    /** @var MockInterface&CoyoteCertManager $manager */
    $manager = Mockery::mock(CoyoteCertManager::class);
    $manager->shouldReceive('storage')->andReturn($storage);

    $this->instance(CoyoteCertManager::class, $manager);

    $this->artisan('cert:status', ['domain' => 'unknown.example.com'])
        ->assertExitCode(Command::FAILURE)
        ->expectsOutputToContain('No certificate found for [unknown.example.com]');
});
