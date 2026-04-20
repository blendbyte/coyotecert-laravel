<?php

declare(strict_types=1);

namespace Tests\Unit;

use CoyoteCert\CoyoteCert;
use CoyoteCert\Enums\KeyType;
use CoyoteCert\Laravel\CoyoteCertManager;
use CoyoteCert\Laravel\Events\CertificateIssued;
use CoyoteCert\Laravel\Events\CertificateRenewed;
use CoyoteCert\Storage\DatabaseStorage;
use CoyoteCert\Storage\FilesystemStorage;
use CoyoteCert\Storage\StoredCertificate;
use DateTimeImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Mockery;
use Mockery\MockInterface;
use PDO;
use Psr\Log\LoggerInterface;
use ReflectionProperty;

/**
 * @return array{0: CoyoteCertManager, 1: MockInterface&CacheRepository, 2: MockInterface&Dispatcher, 3: MockInterface&DatabaseManager}
 */
function buildManager(array $configMap = []): array
{
    /** @var MockInterface&ConfigRepository $config */
    $config = Mockery::mock(ConfigRepository::class);
    /** @var MockInterface&CacheRepository $cache */
    $cache = Mockery::mock(CacheRepository::class);
    /** @var MockInterface&Dispatcher $events */
    $events = Mockery::mock(Dispatcher::class);
    /** @var MockInterface&DatabaseManager $db */
    $db = Mockery::mock(DatabaseManager::class);
    /** @var MockInterface&LoggerInterface $logger */
    $logger = Mockery::mock(LoggerInterface::class);

    $config->shouldReceive('get')->andReturnUsing(
        static function (string $key, mixed $default = null) use ($configMap): mixed {
            return array_key_exists($key, $configMap) ? $configMap[$key] : $default;
        },
    );

    return [new CoyoteCertManager($config, $cache, $events, $db, $logger), $cache, $events, $db];
}

it('storage() returns FilesystemStorage when configured', function (): void {
    [$manager] = buildManager(['coyotecert.storage' => 'filesystem', 'coyotecert.filesystem.path' => '/tmp/certs']);

    expect($manager->storage())->toBeInstanceOf(FilesystemStorage::class);
});

it('storage() returns DatabaseStorage when configured', function (): void {
    $pdo = Mockery::mock(PDO::class);
    /** @var MockInterface&Connection $connection */
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getPdo')->andReturn($pdo);

    [$manager, , , $db] = buildManager([
        'coyotecert.storage'             => 'database',
        'coyotecert.database.connection' => null,
        'database.default'               => 'sqlite',
        'coyotecert.database.table'      => 'coyote_cert_storage',
    ]);

    $db->shouldReceive('connection')->with('sqlite')->andReturn($connection);

    expect($manager->storage())->toBeInstanceOf(DatabaseStorage::class);
});

it('storage() throws for an unknown driver', function (): void {
    [$manager] = buildManager(['coyotecert.storage' => 'unknown-driver']);

    expect(fn() => $manager->storage())->toThrow(InvalidArgumentException::class);
});

it('for() returns a CoyoteCert instance', function (string $provider, array $extra): void {
    [$manager] = buildManager([
        'coyotecert.email'           => 'test@example.com',
        'coyotecert.key_type'        => 'EC_P256',
        'coyotecert.provider'        => $provider,
        'coyotecert.storage'         => 'filesystem',
        'coyotecert.filesystem.path' => '/tmp/certs',
        'coyotecert.challenge'       => 'http-01',
        ...$extra,
    ]);

    expect($manager->for('example.com'))->toBeInstanceOf(CoyoteCert::class);
})->with([
    'letsencrypt'         => ['letsencrypt', []],
    'letsencrypt-staging' => ['letsencrypt-staging', []],
    'buypass'             => ['buypass', []],
    'buypass-staging'     => ['buypass-staging', []],
    'zerossl'             => ['zerossl', ['coyotecert.providers.zerossl.api_key' => 'test-key']],
    'google'              => ['google', ['coyotecert.providers.google.eab_kid' => 'kid', 'coyotecert.providers.google.eab_hmac' => 'hmac']],
    'custom'              => ['custom', ['coyotecert.providers.custom.directory_url' => 'https://acme.example.com/directory']],
]);

it('for() throws when no provider is configured', function (): void {
    [$manager] = buildManager([
        'coyotecert.email'           => 'test@example.com',
        'coyotecert.key_type'        => 'EC_P256',
        'coyotecert.provider'        => '',
        'coyotecert.storage'         => 'filesystem',
        'coyotecert.filesystem.path' => '/tmp/certs',
        'coyotecert.challenge'       => 'http-01',
    ]);

    expect(fn() => $manager->for('example.com'))
        ->toThrow(InvalidArgumentException::class, 'No ACME provider configured');
});

it('for() throws for an unknown provider', function (): void {
    [$manager] = buildManager([
        'coyotecert.email'           => 'test@example.com',
        'coyotecert.key_type'        => 'EC_P256',
        'coyotecert.provider'        => 'unknown-provider',
        'coyotecert.storage'         => 'filesystem',
        'coyotecert.filesystem.path' => '/tmp/certs',
        'coyotecert.challenge'       => 'http-01',
    ]);

    expect(fn() => $manager->for('example.com'))->toThrow(InvalidArgumentException::class);
});

it('for() throws for the dns-01 challenge (requires a custom handler)', function (): void {
    [$manager] = buildManager([
        'coyotecert.email'           => 'test@example.com',
        'coyotecert.key_type'        => 'EC_P256',
        'coyotecert.provider'        => 'letsencrypt',
        'coyotecert.storage'         => 'filesystem',
        'coyotecert.filesystem.path' => '/tmp/certs',
        'coyotecert.challenge'       => 'dns-01',
    ]);

    expect(fn() => $manager->for('example.com'))->toThrow(InvalidArgumentException::class);
});

it('dispatches CertificateIssued when the onIssued callback fires', function (): void {
    [$manager, , $events] = buildManager([
        'coyotecert.email'           => 'test@example.com',
        'coyotecert.key_type'        => 'EC_P256',
        'coyotecert.provider'        => 'letsencrypt',
        'coyotecert.storage'         => 'filesystem',
        'coyotecert.filesystem.path' => '/tmp/certs',
        'coyotecert.challenge'       => 'http-01',
    ]);

    $coyoteCert = $manager->for('example.com');

    $prop = new ReflectionProperty(CoyoteCert::class, 'onIssuedCallbacks');
    $prop->setAccessible(true);
    /** @var callable[] $callbacks */
    $callbacks = $prop->getValue($coyoteCert);

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

    $events->shouldReceive('dispatch')->once()->with(Mockery::type(CertificateIssued::class));

    foreach ($callbacks as $cb) {
        $cb($cert);
    }
});

it('for() throws when passed an empty array', function (): void {
    [$manager] = buildManager([
        'coyotecert.email'           => 'test@example.com',
        'coyotecert.key_type'        => 'EC_P256',
        'coyotecert.provider'        => 'letsencrypt',
        'coyotecert.storage'         => 'filesystem',
        'coyotecert.filesystem.path' => '/tmp/certs',
        'coyotecert.challenge'       => 'http-01',
    ]);

    expect(fn() => $manager->for([]))->toThrow(InvalidArgumentException::class, 'At least one identity is required');
});

it('resolveKeyType() throws for an invalid key type value', function (): void {
    [$manager] = buildManager(['coyotecert.key_type' => 'RSA_INVALID']);

    expect(fn() => $manager->resolveKeyType())->toThrow(InvalidArgumentException::class, 'Invalid key type');
});

it('for() throws when a required provider credential is missing', function (): void {
    [$manager] = buildManager([
        'coyotecert.email'                     => 'test@example.com',
        'coyotecert.key_type'                  => 'EC_P256',
        'coyotecert.provider'                  => 'zerossl',
        'coyotecert.providers.zerossl.api_key' => '',
        'coyotecert.storage'                   => 'filesystem',
        'coyotecert.filesystem.path'           => '/tmp/certs',
        'coyotecert.challenge'                 => 'http-01',
    ]);

    expect(fn() => $manager->for('example.com'))
        ->toThrow(InvalidArgumentException::class, 'Missing required configuration');
});

it('for() throws for an unknown challenge type', function (): void {
    [$manager] = buildManager([
        'coyotecert.email'           => 'test@example.com',
        'coyotecert.key_type'        => 'EC_P256',
        'coyotecert.provider'        => 'letsencrypt',
        'coyotecert.storage'         => 'filesystem',
        'coyotecert.filesystem.path' => '/tmp/certs',
        'coyotecert.challenge'       => 'tls-alpn-01',
    ]);

    expect(fn() => $manager->for('example.com'))->toThrow(InvalidArgumentException::class);
});

it('dispatches CertificateIssued with the primary domain when for() receives an array of identities', function (): void {
    [$manager, , $events] = buildManager([
        'coyotecert.email'           => 'test@example.com',
        'coyotecert.key_type'        => 'EC_P256',
        'coyotecert.provider'        => 'letsencrypt',
        'coyotecert.storage'         => 'filesystem',
        'coyotecert.filesystem.path' => '/tmp/certs',
        'coyotecert.challenge'       => 'http-01',
    ]);

    $coyoteCert = $manager->for(['example.com', 'www.example.com']);

    $prop = new ReflectionProperty(CoyoteCert::class, 'onIssuedCallbacks');
    $prop->setAccessible(true);
    /** @var callable[] $callbacks */
    $callbacks = $prop->getValue($coyoteCert);

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

    $events->shouldReceive('dispatch')->once()->with(
        Mockery::on(fn(CertificateIssued $e): bool => $e->identity === 'example.com'),
    );

    foreach ($callbacks as $cb) {
        $cb($cert);
    }
});

it('dispatches CertificateRenewed with the primary domain when for() receives an array of identities', function (): void {
    [$manager, , $events] = buildManager([
        'coyotecert.email'           => 'test@example.com',
        'coyotecert.key_type'        => 'EC_P256',
        'coyotecert.provider'        => 'letsencrypt',
        'coyotecert.storage'         => 'filesystem',
        'coyotecert.filesystem.path' => '/tmp/certs',
        'coyotecert.challenge'       => 'http-01',
    ]);

    $coyoteCert = $manager->for(['example.com', 'www.example.com']);

    $prop = new ReflectionProperty(CoyoteCert::class, 'onRenewedCallbacks');
    $prop->setAccessible(true);
    /** @var callable[] $callbacks */
    $callbacks = $prop->getValue($coyoteCert);

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

    $events->shouldReceive('dispatch')->once()->with(
        Mockery::on(fn(CertificateRenewed $e): bool => $e->identity === 'example.com'),
    );

    foreach ($callbacks as $cb) {
        $cb($cert);
    }
});

it('dispatches CertificateRenewed when the onRenewed callback fires', function (): void {
    [$manager, , $events] = buildManager([
        'coyotecert.email'           => 'test@example.com',
        'coyotecert.key_type'        => 'EC_P256',
        'coyotecert.provider'        => 'letsencrypt',
        'coyotecert.storage'         => 'filesystem',
        'coyotecert.filesystem.path' => '/tmp/certs',
        'coyotecert.challenge'       => 'http-01',
    ]);

    $coyoteCert = $manager->for('example.com');

    $prop = new ReflectionProperty(CoyoteCert::class, 'onRenewedCallbacks');
    $prop->setAccessible(true);
    /** @var callable[] $callbacks */
    $callbacks = $prop->getValue($coyoteCert);

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

    $events->shouldReceive('dispatch')->once()->with(Mockery::type(CertificateRenewed::class));

    foreach ($callbacks as $cb) {
        $cb($cert);
    }
});
