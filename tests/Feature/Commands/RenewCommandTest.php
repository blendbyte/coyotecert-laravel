<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use CoyoteCert\CoyoteCert;
use CoyoteCert\Enums\KeyType;
use CoyoteCert\Laravel\CoyoteCertManager;
use CoyoteCert\Laravel\Events\CertificateExpiring;
use CoyoteCert\Storage\StorageInterface;
use CoyoteCert\Storage\StoredCertificate;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Event;
use Mockery;
use Mockery\MockInterface;

it('renews all configured identities and reports success', function (): void {
    config(['coyotecert.identities' => ['example.com', 'www.example.com']]);

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

    /** @var MockInterface&CoyoteCert $coyoteCert */
    $coyoteCert = Mockery::mock(CoyoteCert::class);
    $coyoteCert->shouldReceive('issueOrRenew')->twice()->andReturn($cert);

    /** @var MockInterface&StorageInterface $storage */
    $storage = Mockery::mock(StorageInterface::class);
    $storage->shouldReceive('getCertificate')->andReturn(null);

    /** @var MockInterface&CoyoteCertManager $manager */
    $manager = Mockery::mock(CoyoteCertManager::class);
    $manager->shouldReceive('storage')->andReturn($storage);
    $manager->shouldReceive('for')->andReturn($coyoteCert);

    $this->instance(CoyoteCertManager::class, $manager);

    $this->artisan('cert:renew')
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('Renewed: example.com')
        ->expectsOutputToContain('Renewed: www.example.com');
});

it('renews a single identity when --identity is given', function (): void {
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

    /** @var MockInterface&CoyoteCert $coyoteCert */
    $coyoteCert = Mockery::mock(CoyoteCert::class);
    $coyoteCert->shouldReceive('issueOrRenew')->once()->andReturn($cert);

    /** @var MockInterface&StorageInterface $storage */
    $storage = Mockery::mock(StorageInterface::class);
    $storage->shouldReceive('getCertificate')
        ->with('example.com', KeyType::EC_P256)
        ->andReturn(null);

    /** @var MockInterface&CoyoteCertManager $manager */
    $manager = Mockery::mock(CoyoteCertManager::class);
    $manager->shouldReceive('storage')->andReturn($storage);
    $manager->shouldReceive('for')->with('example.com')->andReturn($coyoteCert);

    $this->instance(CoyoteCertManager::class, $manager);

    $this->artisan('cert:renew', ['--identity' => 'example.com'])
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('Renewed: example.com');
});

it('calls issue() instead of issueOrRenew() when --force is given', function (): void {
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

    /** @var MockInterface&CoyoteCert $coyoteCert */
    $coyoteCert = Mockery::mock(CoyoteCert::class);
    $coyoteCert->shouldReceive('issue')->once()->andReturn($cert);
    $coyoteCert->shouldNotReceive('issueOrRenew');

    /** @var MockInterface&CoyoteCertManager $manager */
    $manager = Mockery::mock(CoyoteCertManager::class);
    $manager->shouldReceive('for')->with('example.com')->andReturn($coyoteCert);

    $this->instance(CoyoteCertManager::class, $manager);

    $this->artisan('cert:renew', ['--identity' => 'example.com', '--force' => true])
        ->assertExitCode(Command::SUCCESS);
});

it('returns failure when an identity renewal throws', function (): void {
    config(['coyotecert.identities' => ['example.com']]);

    /** @var MockInterface&CoyoteCert $coyoteCert */
    $coyoteCert = Mockery::mock(CoyoteCert::class);
    $coyoteCert->shouldReceive('issueOrRenew')->once()->andThrow(new \RuntimeException('ACME error'));

    /** @var MockInterface&StorageInterface $storage */
    $storage = Mockery::mock(StorageInterface::class);
    $storage->shouldReceive('getCertificate')->andReturn(null);

    /** @var MockInterface&CoyoteCertManager $manager */
    $manager = Mockery::mock(CoyoteCertManager::class);
    $manager->shouldReceive('storage')->andReturn($storage);
    $manager->shouldReceive('for')->andReturn($coyoteCert);

    $this->instance(CoyoteCertManager::class, $manager);

    $this->artisan('cert:renew')
        ->assertExitCode(Command::FAILURE)
        ->expectsOutputToContain('Failed [example.com]');
});

it('dispatches CertificateExpiring when the cert is within the renewal window', function (): void {
    config(['coyotecert.identities' => ['example.com'], 'coyotecert.renewal_days' => 30]);

    $expiringSoon = new StoredCertificate(
        certificate: '---cert---',
        privateKey: '---key---',
        fullchain: '---fullchain---',
        caBundle: '---ca---',
        issuedAt: new DateTimeImmutable('-60 days'),
        expiresAt: new DateTimeImmutable('+20 days'),
        domains: ['example.com'],
        keyType: KeyType::EC_P256,
    );

    $renewed = new StoredCertificate(
        certificate: '---new-cert---',
        privateKey: '---new-key---',
        fullchain: '---new-fullchain---',
        caBundle: '---new-ca---',
        issuedAt: new DateTimeImmutable(),
        expiresAt: new DateTimeImmutable('+90 days'),
        domains: ['example.com'],
        keyType: KeyType::EC_P256,
    );

    /** @var MockInterface&CoyoteCert $coyoteCert */
    $coyoteCert = Mockery::mock(CoyoteCert::class);
    $coyoteCert->shouldReceive('issueOrRenew')->once()->andReturn($renewed);

    /** @var MockInterface&StorageInterface $storage */
    $storage = Mockery::mock(StorageInterface::class);
    $storage->shouldReceive('getCertificate')
        ->with('example.com', KeyType::EC_P256)
        ->andReturn($expiringSoon);

    /** @var MockInterface&CoyoteCertManager $manager */
    $manager = Mockery::mock(CoyoteCertManager::class);
    $manager->shouldReceive('storage')->andReturn($storage);
    $manager->shouldReceive('for')->andReturn($coyoteCert);

    $this->instance(CoyoteCertManager::class, $manager);

    Event::fake([CertificateExpiring::class]);

    $this->artisan('cert:renew')->assertExitCode(Command::SUCCESS);

    Event::assertDispatched(
        CertificateExpiring::class,
        fn(CertificateExpiring $e) => $e->identity === 'example.com' && $e->daysUntilExpiry <= 30,
    );
});

it('skips an identity whose certificate is not within the renewal window', function (): void {
    config(['coyotecert.identities' => ['example.com'], 'coyotecert.renewal_days' => 30]);

    $freshCert = new StoredCertificate(
        certificate: '---cert---',
        privateKey: '---key---',
        fullchain: '---fullchain---',
        caBundle: '---ca---',
        issuedAt: new DateTimeImmutable(),
        expiresAt: new DateTimeImmutable('+60 days'),
        domains: ['example.com'],
        keyType: KeyType::EC_P256,
    );

    /** @var MockInterface&StorageInterface $storage */
    $storage = Mockery::mock(StorageInterface::class);
    $storage->shouldReceive('getCertificate')
        ->with('example.com', KeyType::EC_P256)
        ->andReturn($freshCert);

    /** @var MockInterface&CoyoteCertManager $manager */
    $manager = Mockery::mock(CoyoteCertManager::class);
    $manager->shouldReceive('storage')->andReturn($storage);
    $manager->shouldNotReceive('for');

    $this->instance(CoyoteCertManager::class, $manager);

    $this->artisan('cert:renew')
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('Skipped: example.com');
});

it('warns and returns success when no identities are configured', function (): void {
    config(['coyotecert.identities' => []]);

    $this->artisan('cert:renew')
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('No identities configured');
});
