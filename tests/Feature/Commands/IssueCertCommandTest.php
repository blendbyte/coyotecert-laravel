<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use CoyoteCert\CoyoteCert;
use CoyoteCert\Enums\KeyType;
use CoyoteCert\Laravel\CoyoteCertManager;
use CoyoteCert\Laravel\Events\CertificateFailed;
use CoyoteCert\Storage\StoredCertificate;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Event;
use Mockery;
use Mockery\MockInterface;

it('issues a certificate and reports success', function (): void {
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

    /** @var MockInterface&CoyoteCertManager $manager */
    $manager = Mockery::mock(CoyoteCertManager::class);
    $manager->shouldReceive('for')->with('example.com')->andReturn($coyoteCert);

    $this->instance(CoyoteCertManager::class, $manager);

    $this->artisan('cert:issue', ['identity' => 'example.com'])
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('Certificate issued successfully.');
});

it('issues a SAN certificate when multiple identities are given', function (): void {
    $cert = new StoredCertificate(
        certificate: '---cert---',
        privateKey: '---key---',
        fullchain: '---fullchain---',
        caBundle: '---ca---',
        issuedAt: new DateTimeImmutable(),
        expiresAt: new DateTimeImmutable('+90 days'),
        domains: ['example.com', 'www.example.com'],
        keyType: KeyType::EC_P256,
    );

    /** @var MockInterface&CoyoteCert $coyoteCert */
    $coyoteCert = Mockery::mock(CoyoteCert::class);
    $coyoteCert->shouldReceive('issue')->once()->andReturn($cert);

    /** @var MockInterface&CoyoteCertManager $manager */
    $manager = Mockery::mock(CoyoteCertManager::class);
    $manager->shouldReceive('for')->with(['example.com', 'www.example.com'])->andReturn($coyoteCert);

    $this->instance(CoyoteCertManager::class, $manager);

    $this->artisan('cert:issue', ['identity' => ['example.com', 'www.example.com']])
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('Certificate issued successfully.')
        ->expectsOutputToContain('example.com, www.example.com');
});

it('returns failure and shows an error when issuance throws', function (): void {
    /** @var MockInterface&CoyoteCert $coyoteCert */
    $coyoteCert = Mockery::mock(CoyoteCert::class);
    $coyoteCert->shouldReceive('issue')->once()->andThrow(new \RuntimeException('ACME error'));

    /** @var MockInterface&CoyoteCertManager $manager */
    $manager = Mockery::mock(CoyoteCertManager::class);
    $manager->shouldReceive('for')->with('example.com')->andReturn($coyoteCert);

    $this->instance(CoyoteCertManager::class, $manager);

    $this->artisan('cert:issue', ['identity' => 'example.com'])
        ->assertExitCode(Command::FAILURE)
        ->expectsOutputToContain('Failed to issue certificate for [example.com]: ACME error');
});

it('dispatches CertificateFailed when issuance throws', function (): void {
    Event::fake([CertificateFailed::class]);

    /** @var MockInterface&CoyoteCert $coyoteCert */
    $coyoteCert = Mockery::mock(CoyoteCert::class);
    $coyoteCert->shouldReceive('issue')->once()->andThrow(new \RuntimeException('ACME error'));

    /** @var MockInterface&CoyoteCertManager $manager */
    $manager = Mockery::mock(CoyoteCertManager::class);
    $manager->shouldReceive('for')->with('example.com')->andReturn($coyoteCert);

    $this->instance(CoyoteCertManager::class, $manager);

    $this->artisan('cert:issue', ['identity' => 'example.com'])->assertExitCode(Command::FAILURE);

    Event::assertDispatched(
        CertificateFailed::class,
        fn(CertificateFailed $e) => $e->identity === 'example.com' && $e->exception->getMessage() === 'ACME error',
    );
});
