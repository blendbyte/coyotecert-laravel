<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use CoyoteCert\Enums\KeyType;
use CoyoteCert\Exceptions\StorageException;
use CoyoteCert\Laravel\CoyoteCertManager;
use CoyoteCert\Storage\StorageInterface;
use Illuminate\Console\Command;
use Mockery;
use Mockery\MockInterface;

/** @return MockInterface&StorageInterface */
function statusStorage(): MockInterface
{
    /** @var MockInterface&StorageInterface $storage */
    $storage = Mockery::mock(StorageInterface::class);

    /** @var MockInterface&CoyoteCertManager $manager */
    $manager = Mockery::mock(CoyoteCertManager::class);
    $manager->shouldReceive('resolveKeyType')->andReturn(KeyType::EC_P256);
    $manager->shouldReceive('storage')->andReturn($storage);

    test()->instance(CoyoteCertManager::class, $manager);

    return $storage;
}

it('shows the certificate status table for a known domain', function (): void {
    statusStorage()->shouldReceive('getCertificate')
        ->with('example.com', KeyType::EC_P256)
        ->andReturn(fakeCertificate(['example.com', 'www.example.com']));

    $this->artisan('cert:status', ['identity' => 'Example.com'])
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('example.com, www.example.com')
        ->expectsOutputToContain('letsencrypt');
});

it('returns failure and prints an error when no certificate is found', function (): void {
    statusStorage()->shouldReceive('getCertificate')->andReturn(null);

    $this->artisan('cert:status', ['identity' => 'unknown.example.com'])
        ->assertExitCode(Command::FAILURE)
        ->expectsOutputToContain('No certificate found for [unknown.example.com]');
});

it('returns failure when the stored certificate cannot be read', function (): void {
    statusStorage()->shouldReceive('getCertificate')->andThrow(new StorageException('bad json'));

    $this->artisan('cert:status', ['identity' => 'example.com'])
        ->assertExitCode(Command::FAILURE)
        ->expectsOutputToContain('Failed to read the certificate for [example.com]: bad json');
});
