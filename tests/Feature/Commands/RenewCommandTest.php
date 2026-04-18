<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use CoyoteCert\CoyoteCert;
use CoyoteCert\Enums\KeyType;
use CoyoteCert\Laravel\CoyoteCertManager;
use CoyoteCert\Storage\StoredCertificate;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Mockery;
use Mockery\MockInterface;

it('renews all configured domains and reports success', function (): void {
    config(['coyotecert.domains' => ['example.com', 'www.example.com']]);

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

    /** @var MockInterface&CoyoteCertManager $manager */
    $manager = Mockery::mock(CoyoteCertManager::class);
    $manager->shouldReceive('for')->andReturn($coyoteCert);

    $this->instance(CoyoteCertManager::class, $manager);

    $this->artisan('cert:renew')
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('Renewed: example.com')
        ->expectsOutputToContain('Renewed: www.example.com');
});

it('renews a single domain when --domain is given', function (): void {
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

    /** @var MockInterface&CoyoteCertManager $manager */
    $manager = Mockery::mock(CoyoteCertManager::class);
    $manager->shouldReceive('for')->with('example.com')->andReturn($coyoteCert);

    $this->instance(CoyoteCertManager::class, $manager);

    $this->artisan('cert:renew', ['--domain' => 'example.com'])
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

    $this->artisan('cert:renew', ['--domain' => 'example.com', '--force' => true])
        ->assertExitCode(Command::SUCCESS);
});

it('returns failure when a domain renewal throws', function (): void {
    config(['coyotecert.domains' => ['example.com']]);

    /** @var MockInterface&CoyoteCert $coyoteCert */
    $coyoteCert = Mockery::mock(CoyoteCert::class);
    $coyoteCert->shouldReceive('issueOrRenew')->once()->andThrow(new \RuntimeException('ACME error'));

    /** @var MockInterface&CoyoteCertManager $manager */
    $manager = Mockery::mock(CoyoteCertManager::class);
    $manager->shouldReceive('for')->andReturn($coyoteCert);

    $this->instance(CoyoteCertManager::class, $manager);

    $this->artisan('cert:renew')
        ->assertExitCode(Command::FAILURE)
        ->expectsOutputToContain('Failed [example.com]');
});
