<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use CoyoteCert\CoyoteCert;
use CoyoteCert\Enums\KeyType;
use CoyoteCert\Enums\RevocationReason;
use CoyoteCert\Laravel\CoyoteCertManager;
use CoyoteCert\Storage\StorageInterface;
use CoyoteCert\Storage\StoredCertificate;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Mockery;
use Mockery\MockInterface;

it('revokes and deletes a certificate for a known domain', function (): void {
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

    /** @var MockInterface&CoyoteCert $coyoteCert */
    $coyoteCert = Mockery::mock(CoyoteCert::class);
    $coyoteCert->shouldReceive('revoke')
        ->once()
        ->with($cert, RevocationReason::Unspecified)
        ->andReturn(true);

    /** @var MockInterface&StorageInterface $storage */
    $storage = Mockery::mock(StorageInterface::class);
    $storage->shouldReceive('getCertificate')
        ->with('example.com', KeyType::EC_P256)
        ->andReturn($cert);
    $storage->shouldReceive('deleteCertificate')
        ->once()
        ->with('example.com', KeyType::EC_P256);

    /** @var MockInterface&CoyoteCertManager $manager */
    $manager = Mockery::mock(CoyoteCertManager::class);
    $manager->shouldReceive('storage')->andReturn($storage);
    $manager->shouldReceive('for')->with('example.com')->andReturn($coyoteCert);

    $this->instance(CoyoteCertManager::class, $manager);

    $this->artisan('cert:revoke', ['domain' => 'example.com'])
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('revoked and deleted');
});

it('returns failure and prints an error when no certificate is found', function (): void {
    /** @var MockInterface&StorageInterface $storage */
    $storage = Mockery::mock(StorageInterface::class);
    $storage->shouldReceive('getCertificate')->andReturn(null);

    /** @var MockInterface&CoyoteCertManager $manager */
    $manager = Mockery::mock(CoyoteCertManager::class);
    $manager->shouldReceive('storage')->andReturn($storage);

    $this->instance(CoyoteCertManager::class, $manager);

    $this->artisan('cert:revoke', ['domain' => 'unknown.example.com'])
        ->assertExitCode(Command::FAILURE)
        ->expectsOutputToContain('No certificate found for [unknown.example.com]');
});

it('returns failure and shows valid codes when an invalid reason is given', function (): void {
    $this->artisan('cert:revoke', ['domain' => 'example.com', '--reason' => '99'])
        ->assertExitCode(Command::FAILURE)
        ->expectsOutputToContain('Invalid reason code [99]')
        ->expectsOutputToContain('Valid codes:');
});
