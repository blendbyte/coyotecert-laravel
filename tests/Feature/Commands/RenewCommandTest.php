<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use CoyoteCert\CoyoteCert;
use CoyoteCert\Enums\KeyType;
use CoyoteCert\Exceptions\StorageException;
use CoyoteCert\Laravel\CoyoteCertManager;
use CoyoteCert\Laravel\Events\CertificateExpiring;
use CoyoteCert\Laravel\Events\CertificateFailed;
use CoyoteCert\Storage\StorageInterface;
use CoyoteCert\Storage\StoredCertificate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Mockery;
use Mockery\MockInterface;

/**
 * @param list<non-empty-list<string>> $configured
 * @param array<string, StoredCertificate|\Throwable|null> $stored keyed by primary identity
 *
 * @return MockInterface&CoyoteCertManager
 */
function renewManager(array $configured = [], array $stored = [], ?int $renewalDays = null): MockInterface
{
    /** @var MockInterface&StorageInterface $storage */
    $storage = Mockery::mock(StorageInterface::class);
    $storage->shouldReceive('getCertificate')->andReturnUsing(
        static function (string $primary, KeyType $keyType) use ($stored): ?StoredCertificate {
            $value = $stored[$primary] ?? null;

            if ($value instanceof \Throwable) {
                throw $value;
            }

            return $value;
        },
    );

    /** @var MockInterface&CoyoteCertManager $manager */
    $manager = Mockery::mock(CoyoteCertManager::class);
    $manager->shouldReceive('renewalDays')->andReturn($renewalDays);
    $manager->shouldReceive('resolveKeyType')->andReturn(KeyType::EC_P256);
    $manager->shouldReceive('storage')->andReturn($storage);
    $manager->shouldReceive('configuredIdentities')->andReturn($configured);

    test()->instance(CoyoteCertManager::class, $manager);

    return $manager;
}

/** @return MockInterface&CoyoteCert */
function renewBuilder(bool $needsRenewal = true): MockInterface
{
    /** @var MockInterface&CoyoteCert $cert */
    $cert = Mockery::mock(CoyoteCert::class);
    $cert->shouldReceive('needsRenewal')->andReturn($needsRenewal);

    return $cert;
}

it('issues certificates that do not exist yet for every configured identity', function (): void {
    $builder = renewBuilder();
    $builder->shouldReceive('issue')->twice()->andReturn(fakeCertificate());

    $manager = renewManager([['example.com'], ['api.example.com']]);
    $manager->shouldReceive('for')->with(['example.com'])->once()->andReturn($builder);
    $manager->shouldReceive('for')->with(['api.example.com'])->once()->andReturn($builder);

    $this->artisan('cert:renew')
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('Issued: example.com')
        ->expectsOutputToContain('Issued: api.example.com');
});

it('renews a due certificate and dispatches CertificateExpiring first', function (): void {
    Event::fake([CertificateExpiring::class]);

    $existing = fakeCertificate(expiresAt: '+20 days', issuedAt: '-70 days');
    $builder  = renewBuilder();
    $builder->shouldReceive('issue')->once()->andReturn(fakeCertificate());

    renewManager([['example.com']], ['example.com' => $existing])
        ->shouldReceive('for')->with(['example.com'])->andReturn($builder);

    $this->artisan('cert:renew')
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('Renewed: example.com');

    Event::assertDispatched(
        CertificateExpiring::class,
        fn(CertificateExpiring $e) => $e->identity === 'example.com' && $e->daysUntilExpiry === 20 && $e->certificate === $existing,
    );
});

it('skips a certificate that CoyoteCert says is not due', function (): void {
    Event::fake([CertificateExpiring::class]);

    $builder = renewBuilder(needsRenewal: false);
    $builder->shouldNotReceive('issue');

    renewManager([['example.com']], ['example.com' => fakeCertificate(expiresAt: '+60 days')])
        ->shouldReceive('for')->andReturn($builder);

    $this->artisan('cert:renew')
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('Skipped: example.com (60 days remaining)');

    Event::assertNotDispatched(CertificateExpiring::class);
});

it('passes the configured renewal threshold to needsRenewal()', function (): void {
    /** @var MockInterface&CoyoteCert $builder */
    $builder = Mockery::mock(CoyoteCert::class);
    $builder->shouldReceive('needsRenewal')->once()->with(14)->andReturn(false);

    renewManager([['example.com']], renewalDays: 14)->shouldReceive('for')->andReturn($builder);

    $this->artisan('cert:renew')->assertExitCode(Command::SUCCESS)->expectsOutputToContain('Skipped: example.com');
});

it('re-issues without asking needsRenewal() when --force is given', function (): void {
    Event::fake([CertificateExpiring::class]);

    /** @var MockInterface&CoyoteCert $builder */
    $builder = Mockery::mock(CoyoteCert::class);
    $builder->shouldNotReceive('needsRenewal');
    $builder->shouldReceive('issue')->once()->andReturn(fakeCertificate());

    renewManager([['example.com']], ['example.com' => fakeCertificate()])
        ->shouldReceive('for')->andReturn($builder);

    $this->artisan('cert:renew', ['--force' => true])
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('Renewed: example.com');

    Event::assertNotDispatched(CertificateExpiring::class);
});

it('uses the configured SAN entry for --identity', function (): void {
    $builder = renewBuilder();
    $builder->shouldReceive('issue')->once()->andReturn(fakeCertificate());

    renewManager(
        [['other.example.com'], ['example.com', 'www.example.com']],
        ['example.com' => fakeCertificate(['example.com'])],
    )->shouldReceive('for')->once()->with(['example.com', 'www.example.com'])->andReturn($builder);

    $this->artisan('cert:renew', ['--identity' => 'Example.com'])
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('Renewed: example.com');
});

it('uses the stored names for an --identity that is not configured', function (): void {
    $builder = renewBuilder();
    $builder->shouldReceive('issue')->once()->andReturn(fakeCertificate());

    renewManager([], ['example.com' => fakeCertificate(['example.com', 'www.example.com'])])
        ->shouldReceive('for')->once()->with(['example.com', 'www.example.com'])->andReturn($builder);

    $this->artisan('cert:renew', ['--identity' => 'example.com'])->assertExitCode(Command::SUCCESS);
});

it('uses just the --identity when nothing is configured or stored for it', function (): void {
    $builder = renewBuilder();
    $builder->shouldReceive('issue')->once()->andReturn(fakeCertificate());

    renewManager()->shouldReceive('for')->once()->with(['example.com'])->andReturn($builder);

    $this->artisan('cert:renew', ['--identity' => 'example.com'])
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('Issued: example.com');
});

it('ignores stored names whose primary does not match the --identity', function (): void {
    $builder = renewBuilder();
    $builder->shouldReceive('issue')->once()->andReturn(fakeCertificate());

    renewManager([], ['example.com' => fakeCertificate(['www.example.com', 'example.com'])])
        ->shouldReceive('for')->once()->with(['example.com'])->andReturn($builder);

    $this->artisan('cert:renew', ['--identity' => 'example.com'])->assertExitCode(Command::SUCCESS);
});

it('fails when --identity is empty', function (): void {
    renewManager();

    $this->artisan('cert:renew', ['--identity' => ' '])
        ->assertExitCode(Command::FAILURE)
        ->expectsOutputToContain('The --identity option needs a value.');
});

it('treats an unreadable stored certificate as missing', function (): void {
    $builder = renewBuilder();
    $builder->shouldReceive('issue')->once()->andReturn(fakeCertificate());

    renewManager([['example.com']], ['example.com' => new StorageException('bad json')])
        ->shouldReceive('for')->andReturn($builder);

    $this->artisan('cert:renew')
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('Could not read the stored certificate for [example.com]: bad json')
        ->expectsOutputToContain('Issued: example.com');
});

it('continues after a failure, dispatches CertificateFailed and returns failure', function (): void {
    Event::fake([CertificateFailed::class]);

    $failing = renewBuilder();
    $failing->shouldReceive('issue')->once()->andThrow(new \RuntimeException('ACME error'));

    $working = renewBuilder();
    $working->shouldReceive('issue')->once()->andReturn(fakeCertificate());

    $manager = renewManager([['example.com'], ['api.example.com']]);
    $manager->shouldReceive('for')->with(['example.com'])->andReturn($failing);
    $manager->shouldReceive('for')->with(['api.example.com'])->andReturn($working);

    $this->artisan('cert:renew')
        ->assertExitCode(Command::FAILURE)
        ->expectsOutputToContain('Failed [example.com]: ACME error')
        ->expectsOutputToContain('Issued: api.example.com');

    Event::assertDispatched(
        CertificateFailed::class,
        fn(CertificateFailed $e) => $e->identity === 'example.com' && $e->exception->getMessage() === 'ACME error',
    );
});

it('fails cleanly on invalid configuration', function (): void {
    /** @var MockInterface&CoyoteCertManager $manager */
    $manager = Mockery::mock(CoyoteCertManager::class);
    $manager->shouldReceive('renewalDays')->andThrow(new InvalidArgumentException('Invalid renewal window [x].'));

    $this->instance(CoyoteCertManager::class, $manager);

    $this->artisan('cert:renew')
        ->assertExitCode(Command::FAILURE)
        ->expectsOutputToContain('Invalid renewal window [x].');
});

it('warns and returns success when no identities are configured', function (): void {
    renewManager();

    $this->artisan('cert:renew')
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('No identities configured');
});
