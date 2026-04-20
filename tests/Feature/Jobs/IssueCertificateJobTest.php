<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use CoyoteCert\CoyoteCert;
use CoyoteCert\Enums\KeyType;
use CoyoteCert\Laravel\CoyoteCertManager;
use CoyoteCert\Laravel\Jobs\IssueCertificateJob;
use CoyoteCert\Storage\StoredCertificate;
use DateTimeImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Mockery;
use Mockery\MockInterface;

it('calls issueOrRenew with the default renewal window', function (): void {
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
    $coyoteCert->shouldReceive('issueOrRenew')->once()->with(30)->andReturn($cert);

    /** @var MockInterface&CoyoteCertManager $manager */
    $manager = Mockery::mock(CoyoteCertManager::class);
    $manager->shouldReceive('for')->with('example.com')->andReturn($coyoteCert);

    $job = new IssueCertificateJob('example.com');
    $job->handle($manager);
});

it('passes a custom renewal window to issueOrRenew', function (): void {
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
    $coyoteCert->shouldReceive('issueOrRenew')->once()->with(14)->andReturn($cert);

    /** @var MockInterface&CoyoteCertManager $manager */
    $manager = Mockery::mock(CoyoteCertManager::class);
    $manager->shouldReceive('for')->with('example.com')->andReturn($coyoteCert);

    $job = new IssueCertificateJob('example.com', renewalDays: 14);
    $job->handle($manager);
});

it('issues a SAN certificate when identities is an array', function (): void {
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
    $coyoteCert->shouldReceive('issueOrRenew')->once()->with(30)->andReturn($cert);

    /** @var MockInterface&CoyoteCertManager $manager */
    $manager = Mockery::mock(CoyoteCertManager::class);
    $manager->shouldReceive('for')->with(['example.com', 'www.example.com'])->andReturn($coyoteCert);

    $job = new IssueCertificateJob(['example.com', 'www.example.com']);
    $job->handle($manager);
});

it('implements ShouldQueue', function (): void {
    expect(new IssueCertificateJob('example.com'))->toBeInstanceOf(ShouldQueue::class);
});
