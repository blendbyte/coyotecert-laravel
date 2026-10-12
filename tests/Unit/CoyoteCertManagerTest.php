<?php

declare(strict_types=1);

namespace Tests\Unit;

use CoyoteCert\CoyoteCert;
use CoyoteCert\Enums\AuthorizationChallengeEnum;
use CoyoteCert\Interfaces\ChallengeHandlerInterface;
use CoyoteCert\Laravel\Challenge\CacheHttp01Handler;
use CoyoteCert\Laravel\CoyoteCertManager;
use CoyoteCert\Laravel\Events\CertificateIssued;
use CoyoteCert\Laravel\Events\CertificateRenewed;
use CoyoteCert\Laravel\Storage\LaravelDatabaseStorage;
use CoyoteCert\Provider\AcmeProviderInterface;
use CoyoteCert\Provider\CustomProvider;
use CoyoteCert\Provider\GoogleTrustServices;
use CoyoteCert\Provider\LetsEncrypt;
use CoyoteCert\Provider\LetsEncryptStaging;
use CoyoteCert\Provider\SslCom;
use CoyoteCert\Provider\ZeroSSL;
use CoyoteCert\Storage\FilesystemStorage;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use ReflectionProperty;

const BASE_CONFIG = [
    'coyotecert.email'           => 'test@example.com',
    'coyotecert.key_type'        => 'EC_P256',
    'coyotecert.provider'        => 'letsencrypt',
    'coyotecert.storage'         => 'filesystem',
    'coyotecert.filesystem.path' => '/tmp/certs',
    'coyotecert.challenge'       => 'http-01',
];

/**
 * @param array<string, mixed> $configMap
 *
 * @return array{0: CoyoteCertManager, 1: MockInterface&Dispatcher}
 */
function buildManager(array $configMap = []): array
{
    /** @var MockInterface&ConfigRepository $config */
    $config = Mockery::mock(ConfigRepository::class);
    /** @var MockInterface&Dispatcher $events */
    $events = Mockery::mock(Dispatcher::class);
    /** @var MockInterface&ConnectionResolverInterface $db */
    $db = Mockery::mock(ConnectionResolverInterface::class);
    /** @var MockInterface&LoggerInterface $logger */
    $logger = Mockery::mock(LoggerInterface::class);

    $config->shouldReceive('get')->andReturnUsing(
        static function (string $key, mixed $default = null) use ($configMap): mixed {
            return array_key_exists($key, $configMap) ? $configMap[$key] : $default;
        },
    );

    return [new CoyoteCertManager($config, $events, $db, $logger, app()), $events];
}

function builderProperty(CoyoteCert $cert, string $property): mixed
{
    return (new ReflectionProperty(CoyoteCert::class, $property))->getValue($cert);
}

// ── storage() ────────────────────────────────────────────────────────────────

it('storage() returns FilesystemStorage when configured', function (): void {
    [$manager] = buildManager(['coyotecert.storage' => 'filesystem', 'coyotecert.filesystem.path' => '/tmp/certs']);

    expect($manager->storage())->toBeInstanceOf(FilesystemStorage::class);
});

it('storage() returns the Laravel database storage when configured', function (): void {
    [$manager] = buildManager([
        'coyotecert.storage'             => 'database',
        'coyotecert.database.connection' => 'certs',
        'coyotecert.database.table'      => 'certificates',
    ]);

    $storage = $manager->storage();

    expect($storage)->toBeInstanceOf(LaravelDatabaseStorage::class)
        ->and((new ReflectionProperty($storage, 'connection'))->getValue($storage))->toBe('certs')
        ->and((new ReflectionProperty($storage, 'table'))->getValue($storage))->toBe('certificates');
});

it('storage() uses the default connection when none is configured', function (): void {
    [$manager] = buildManager(['coyotecert.storage' => 'database', 'coyotecert.database.connection' => '']);

    $storage = $manager->storage();

    expect((new ReflectionProperty($storage, 'connection'))->getValue($storage))->toBeNull();
});

it('storage() throws for an unknown driver', function (): void {
    [$manager] = buildManager(['coyotecert.storage' => 'unknown-driver']);

    expect(fn() => $manager->storage())->toThrow(InvalidArgumentException::class, 'Unknown storage driver');
});

// ── Providers ────────────────────────────────────────────────────────────────

it('for() builds the configured provider', function (string $provider, array $extra, string $class): void {
    [$manager] = buildManager([...BASE_CONFIG, 'coyotecert.provider' => $provider, ...$extra]);

    $cert = $manager->for('example.com');

    expect($cert)->toBeInstanceOf(CoyoteCert::class)
        ->and(builderProperty($cert, 'provider'))->toBeInstanceOf($class);
})->with([
    'letsencrypt'         => ['letsencrypt', [], LetsEncrypt::class],
    'letsencrypt-staging' => ['letsencrypt-staging', [], LetsEncryptStaging::class],
    'zerossl'             => ['zerossl', ['coyotecert.providers.zerossl.api_key' => 'test-key'], ZeroSSL::class],
    'google'              => ['google', ['coyotecert.providers.google.eab_kid' => 'kid', 'coyotecert.providers.google.eab_hmac' => 'hmac'], GoogleTrustServices::class],
    'sslcom'              => ['sslcom', [], SslCom::class],
    'custom'              => ['custom', ['coyotecert.providers.custom.directory_url' => 'https://acme.example.com/directory'], CustomProvider::class],
]);

it('for() passes optional SSL.com settings to the provider', function (): void {
    [$manager] = buildManager([
        ...BASE_CONFIG,
        'coyotecert.provider'                  => 'sslcom',
        'coyotecert.providers.sslcom.eab_kid'  => 'kid',
        'coyotecert.providers.sslcom.eab_hmac' => 'hmac',
        'coyotecert.providers.sslcom.ecc'      => true,
    ]);

    /** @var AcmeProviderInterface $provider */
    $provider = builderProperty($manager->for('example.com'), 'provider');

    expect($provider->isEabRequired())->toBeTrue()
        ->and($provider->getEabCredentials('test@example.com')?->kid)->toBe('kid')
        ->and($provider->getDirectoryUrl())->toContain('ecc');
});

it('for() passes optional EAB credentials to a custom provider', function (): void {
    [$manager] = buildManager([
        ...BASE_CONFIG,
        'coyotecert.provider'                       => 'custom',
        'coyotecert.providers.custom.directory_url' => 'https://acme.example.com/directory',
        'coyotecert.providers.custom.eab_kid'       => 'kid',
        'coyotecert.providers.custom.eab_hmac'      => 'hmac',
    ]);

    /** @var AcmeProviderInterface $provider */
    $provider = builderProperty($manager->for('example.com'), 'provider');

    expect($provider->isEabRequired())->toBeTrue()
        ->and($provider->getEabCredentials('test@example.com')?->kid)->toBe('kid');
});

it('for() throws when no provider is configured', function (): void {
    [$manager] = buildManager([...BASE_CONFIG, 'coyotecert.provider' => '']);

    expect(fn() => $manager->for('example.com'))
        ->toThrow(InvalidArgumentException::class, 'No ACME provider configured');
});

it('for() throws for an unknown provider', function (): void {
    [$manager] = buildManager([...BASE_CONFIG, 'coyotecert.provider' => 'unknown-provider']);

    expect(fn() => $manager->for('example.com'))->toThrow(InvalidArgumentException::class, 'Unknown provider');
});

it('for() explains that Buypass is no longer supported', function (string $provider): void {
    [$manager] = buildManager([...BASE_CONFIG, 'coyotecert.provider' => $provider]);

    expect(fn() => $manager->for('example.com'))
        ->toThrow(InvalidArgumentException::class, 'Buypass ended its ACME service');
})->with(['buypass', 'buypass-staging']);

it('for() throws when a required provider credential is missing', function (): void {
    [$manager] = buildManager([
        ...BASE_CONFIG,
        'coyotecert.provider'                  => 'zerossl',
        'coyotecert.providers.zerossl.api_key' => '',
    ]);

    expect(fn() => $manager->for('example.com'))
        ->toThrow(InvalidArgumentException::class, 'Missing required configuration');
});

// ── Challenges ───────────────────────────────────────────────────────────────

it('for() uses the container CacheHttp01Handler for http-01', function (): void {
    [$manager] = buildManager(BASE_CONFIG);

    expect(builderProperty($manager->for('example.com'), 'challengeHandler'))
        ->toBe(app(CacheHttp01Handler::class));
});

it('for() uses the bound ChallengeHandlerInterface for dns-01', function (): void {
    $handler = new class implements ChallengeHandlerInterface {
        public function supports(AuthorizationChallengeEnum $type): bool
        {
            return $type === AuthorizationChallengeEnum::DNS;
        }

        public function deploy(string $domain, string $token, string $keyAuthorization): void {}

        public function cleanup(string $domain, string $token): void {}
    };

    app()->instance(ChallengeHandlerInterface::class, $handler);

    [$manager] = buildManager([...BASE_CONFIG, 'coyotecert.challenge' => 'dns-01']);

    expect(builderProperty($manager->for('*.example.com'), 'challengeHandler'))->toBe($handler);
});

it('for() throws for dns-01 when no handler is bound', function (): void {
    [$manager] = buildManager([...BASE_CONFIG, 'coyotecert.challenge' => 'dns-01']);

    expect(fn() => $manager->for('example.com'))
        ->toThrow(InvalidArgumentException::class, 'Bind a ChallengeHandlerInterface');
});

it('for() throws for an unknown challenge type', function (): void {
    [$manager] = buildManager([...BASE_CONFIG, 'coyotecert.challenge' => 'tls-alpn-01']);

    expect(fn() => $manager->for('example.com'))->toThrow(InvalidArgumentException::class, 'Unknown challenge type');
});

// ── Builder options ──────────────────────────────────────────────────────────

it('for() applies the configured profile and preferred chain', function (): void {
    [$manager] = buildManager([
        ...BASE_CONFIG,
        'coyotecert.profile'         => 'shortlived',
        'coyotecert.preferred_chain' => 'ISRG Root X1',
    ]);

    $cert = $manager->for('example.com');

    expect(builderProperty($cert, 'profile'))->toBe('shortlived')
        ->and(builderProperty($cert, 'preferredChain'))->toBe('ISRG Root X1');
});

it('for() leaves profile and preferred chain at their defaults when not configured', function (): void {
    [$manager] = buildManager([...BASE_CONFIG, 'coyotecert.profile' => null, 'coyotecert.preferred_chain' => '']);

    $cert = $manager->for('example.com');

    expect(builderProperty($cert, 'profile'))->toBe('')
        ->and(builderProperty($cert, 'preferredChain'))->toBe('');
});

it('for() lower-cases, trims and de-duplicates identities', function (): void {
    [$manager] = buildManager(BASE_CONFIG);

    expect(builderProperty($manager->for([' Example.COM', 'www.example.com', 'example.com']), 'domains'))
        ->toBe(['example.com', 'www.example.com']);
});

it('for() throws when passed no identities', function (array|string $identities): void {
    [$manager] = buildManager(BASE_CONFIG);

    expect(fn() => $manager->for($identities))->toThrow(InvalidArgumentException::class, 'At least one identity is required');
})->with([
    'empty array'  => [[]],
    'empty string' => [''],
    'blank names'  => [['', '  ']],
]);

it('resolveKeyType() throws for an invalid key type value', function (): void {
    [$manager] = buildManager(['coyotecert.key_type' => 'RSA_INVALID']);

    expect(fn() => $manager->resolveKeyType())->toThrow(InvalidArgumentException::class, 'Invalid key type');
});

// ── Events ───────────────────────────────────────────────────────────────────

it('dispatches CertificateIssued and CertificateRenewed with the lower-cased primary identity', function (string $property, string $event): void {
    [$manager, $events] = buildManager(BASE_CONFIG);

    $events->shouldReceive('dispatch')->once()->with(
        Mockery::on(fn(object $e): bool => $e instanceof $event && $e->identity === 'example.com'),
    );

    /** @var callable[] $callbacks */
    $callbacks = builderProperty($manager->for(['Example.com', 'www.example.com']), $property);

    foreach ($callbacks as $callback) {
        $callback(fakeCertificate(['example.com', 'www.example.com']));
    }
})->with([
    'issued'  => ['onIssuedCallbacks', CertificateIssued::class],
    'renewed' => ['onRenewedCallbacks', CertificateRenewed::class],
]);

// ── renewalDays() ────────────────────────────────────────────────────────────

it('renewalDays() reads the configured threshold', function (mixed $value, ?int $expected): void {
    [$manager] = buildManager(['coyotecert.renewal_days' => $value]);

    expect($manager->renewalDays())->toBe($expected);
})->with([
    'unset'        => [null, null],
    'empty string' => ['', null],
    'int'          => [30, 30],
    'string'       => ['14', 14],
    'zero'         => ['0', 0],
]);

it('renewalDays() rejects values that are not whole days', function (mixed $value): void {
    [$manager] = buildManager(['coyotecert.renewal_days' => $value]);

    expect(fn() => $manager->renewalDays())->toThrow(InvalidArgumentException::class, 'Invalid renewal window');
})->with(['-1', 'thirty', '1.5', [[30]]]);

// ── configuredIdentities() ───────────────────────────────────────────────────

it('configuredIdentities() normalises single names and SAN groups', function (): void {
    [$manager] = buildManager(['coyotecert.identities' => [
        'Example.com',
        ['API.example.com', 'www.api.example.com', 'api.example.com'],
        '',
        [],
    ]]);

    expect($manager->configuredIdentities())->toBe([
        ['example.com'],
        ['api.example.com', 'www.api.example.com'],
    ]);
});

it('configuredIdentities() returns an empty list when nothing is configured', function (): void {
    [$manager] = buildManager();

    expect($manager->configuredIdentities())->toBe([]);
});
