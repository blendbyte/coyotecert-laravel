<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use CoyoteCert\CoyoteCert;
use CoyoteCert\Laravel\CoyoteCertManager;
use CoyoteCert\Laravel\Events\CertificateFailed;
use CoyoteCert\Laravel\Jobs\IssueCertificateJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Event;
use Mockery;
use Mockery\MockInterface;

/**
 * @param array<string>|string $identities
 *
 * @return MockInterface&CoyoteCertManager
 */
function jobManager(array|string $identities, ?int $expectedDays, ?int $configuredDays = null): MockInterface
{
    /** @var MockInterface&CoyoteCert $coyoteCert */
    $coyoteCert = Mockery::mock(CoyoteCert::class);
    $coyoteCert->shouldReceive('issueOrRenew')->once()->with($expectedDays)->andReturn(fakeCertificate());

    /** @var MockInterface&CoyoteCertManager $manager */
    $manager = Mockery::mock(CoyoteCertManager::class);
    $manager->shouldReceive('for')->with($identities)->andReturn($coyoteCert);
    $manager->shouldReceive('renewalDays')->andReturn($configuredDays);

    return $manager;
}

it('lets CoyoteCert decide when to renew by default', function (): void {
    (new IssueCertificateJob('example.com'))->handle(jobManager('example.com', null));
});

it('falls back to the configured renewal threshold', function (): void {
    (new IssueCertificateJob('example.com'))->handle(jobManager('example.com', 21, configuredDays: 21));
});

it('passes an explicit renewal threshold to issueOrRenew', function (): void {
    (new IssueCertificateJob('example.com', renewalDays: 14))->handle(jobManager('example.com', 14, configuredDays: 21));
});

it('issues a SAN certificate when identities is an array', function (): void {
    $identities = ['example.com', 'www.example.com'];

    (new IssueCertificateJob($identities))->handle(jobManager($identities, null));
});

it('dispatches CertificateFailed with the primary identity when the job fails', function (): void {
    Event::fake([CertificateFailed::class]);

    $exception = new \RuntimeException('ACME error');

    (new IssueCertificateJob(['Example.com', 'www.example.com']))->failed($exception);

    Event::assertDispatched(
        CertificateFailed::class,
        fn(CertificateFailed $e) => $e->identity === 'example.com' && $e->exception === $exception,
    );
});

it('implements ShouldQueue', function (): void {
    expect(new IssueCertificateJob('example.com'))->toBeInstanceOf(ShouldQueue::class);
});
