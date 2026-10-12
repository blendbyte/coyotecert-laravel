<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use CoyoteCert\CoyoteCert;
use CoyoteCert\Enums\KeyType;
use CoyoteCert\Enums\RevocationReason;
use CoyoteCert\Exceptions\StorageException;
use CoyoteCert\Laravel\CoyoteCertManager;
use CoyoteCert\Storage\StorageInterface;
use Illuminate\Console\Command;
use Mockery;
use Mockery\MockInterface;

/** @return array{0: MockInterface&StorageInterface, 1: MockInterface&CoyoteCertManager} */
function revokeMocks(): array
{
    /** @var MockInterface&StorageInterface $storage */
    $storage = Mockery::mock(StorageInterface::class);

    /** @var MockInterface&CoyoteCertManager $manager */
    $manager = Mockery::mock(CoyoteCertManager::class);
    $manager->shouldReceive('resolveKeyType')->andReturn(KeyType::EC_P256);
    $manager->shouldReceive('storage')->andReturn($storage);

    test()->instance(CoyoteCertManager::class, $manager);

    return [$storage, $manager];
}

it('revokes and deletes a certificate for a known domain', function (string $reasonArg, RevocationReason $reason): void {
    $cert = fakeCertificate();

    /** @var MockInterface&CoyoteCert $coyoteCert */
    $coyoteCert = Mockery::mock(CoyoteCert::class);
    $coyoteCert->shouldReceive('revoke')->once()->with($cert, $reason);

    [$storage, $manager] = revokeMocks();
    $storage->shouldReceive('getCertificate')->with('example.com', KeyType::EC_P256)->andReturn($cert);
    $storage->shouldReceive('deleteCertificate')->once()->with('example.com', KeyType::EC_P256);
    $manager->shouldReceive('for')->with('example.com')->andReturn($coyoteCert);

    $this->artisan('cert:revoke', ['identity' => 'Example.com', '--reason' => $reasonArg])
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('revoked and deleted');
})->with([
    'default'        => ['0', RevocationReason::Unspecified],
    'key compromise' => ['1', RevocationReason::KeyCompromise],
]);

it('returns failure and prints an error when no certificate is found', function (): void {
    [$storage] = revokeMocks();
    $storage->shouldReceive('getCertificate')->andReturn(null);

    $this->artisan('cert:revoke', ['identity' => 'unknown.example.com'])
        ->assertExitCode(Command::FAILURE)
        ->expectsOutputToContain('No certificate found for [unknown.example.com]');
});

it('returns failure when the stored certificate cannot be read', function (): void {
    [$storage] = revokeMocks();
    $storage->shouldReceive('getCertificate')->andThrow(new StorageException('bad json'));

    $this->artisan('cert:revoke', ['identity' => 'example.com'])
        ->assertExitCode(Command::FAILURE)
        ->expectsOutputToContain('Failed to revoke certificate for [example.com]: bad json');
});

it('returns failure and shows an error when the revoke call throws', function (): void {
    /** @var MockInterface&CoyoteCert $coyoteCert */
    $coyoteCert = Mockery::mock(CoyoteCert::class);
    $coyoteCert->shouldReceive('revoke')->once()->andThrow(new \RuntimeException('ACME revocation error'));

    [$storage, $manager] = revokeMocks();
    $storage->shouldReceive('getCertificate')->andReturn(fakeCertificate());
    $storage->shouldNotReceive('deleteCertificate');
    $manager->shouldReceive('for')->andReturn($coyoteCert);

    $this->artisan('cert:revoke', ['identity' => 'example.com'])
        ->assertExitCode(Command::FAILURE)
        ->expectsOutputToContain('Failed to revoke certificate for [example.com]: ACME revocation error');
});

it('reports when the certificate was revoked but could not be deleted', function (): void {
    /** @var MockInterface&CoyoteCert $coyoteCert */
    $coyoteCert = Mockery::mock(CoyoteCert::class);
    $coyoteCert->shouldReceive('revoke')->once();

    [$storage, $manager] = revokeMocks();
    $storage->shouldReceive('getCertificate')->andReturn(fakeCertificate());
    $storage->shouldReceive('deleteCertificate')->andThrow(new StorageException('database is locked'));
    $manager->shouldReceive('for')->andReturn($coyoteCert);

    $this->artisan('cert:revoke', ['identity' => 'example.com'])
        ->assertExitCode(Command::FAILURE)
        ->expectsOutputToContain('was revoked, but deleting it from storage failed: database is locked');
});

it('returns failure and shows valid codes when an invalid reason is given', function (string $reason): void {
    $this->artisan('cert:revoke', ['identity' => 'example.com', '--reason' => $reason])
        ->assertExitCode(Command::FAILURE)
        ->expectsOutputToContain("Invalid reason code [{$reason}]")
        ->expectsOutputToContain('Valid codes:');
})->with(['99', '7', 'abc', '-1', '1x']);
