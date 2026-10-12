<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use CoyoteCert\Enums\KeyType;
use CoyoteCert\Exceptions\StorageException;
use CoyoteCert\Laravel\Storage\LaravelDatabaseStorage;
use CoyoteCert\Storage\DatabaseStorage;
use CoyoteCert\Storage\StoredCertificate;
use DateTimeImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    config([
        'database.default'              => 'coyote',
        'database.connections.coyote'   => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'database.connections.prefixed' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'app_'],
    ]);

    (require __DIR__ . '/../../../database/migrations/2024_01_01_000000_create_coyote_cert_storage_table.php')->up();
});

function laravelStorage(?string $connection = null): LaravelDatabaseStorage
{
    return new LaravelDatabaseStorage(app(ConnectionResolverInterface::class), $connection, 'coyote_cert_storage');
}

function sampleCertificate(KeyType $keyType = KeyType::EC_P256, string $marker = 'cert'): StoredCertificate
{
    return new StoredCertificate(
        certificate: "---{$marker}---",
        privateKey: '---key---',
        fullchain: '---fullchain---',
        caBundle: '---ca---',
        issuedAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        expiresAt: new DateTimeImmutable('2026-04-01T00:00:00+00:00'),
        domains: ['example.com', 'www.example.com'],
        keyType: $keyType,
        providerSlug: 'letsencrypt',
    );
}

it('round-trips the account key and its type', function (): void {
    $storage = laravelStorage();

    expect($storage->hasAccountKey('letsencrypt'))->toBeFalse();

    $storage->saveAccountKey('letsencrypt', '---account-key---', KeyType::EC_P384);

    expect($storage->hasAccountKey('letsencrypt'))->toBeTrue()
        ->and($storage->getAccountKey('letsencrypt'))->toBe('---account-key---')
        ->and($storage->getAccountKeyType('letsencrypt'))->toBe(KeyType::EC_P384)
        ->and($storage->hasAccountKey('zerossl'))->toBeFalse();
});

it('throws StorageException for a missing account key or key type', function (string $method): void {
    expect(fn() => laravelStorage()->{$method}('letsencrypt'))->toThrow(StorageException::class);
})->with(['getAccountKey', 'getAccountKeyType']);

it('throws StorageException for an unknown stored account key type', function (): void {
    DB::table('coyote_cert_storage')->insert(['store_key' => 'account:letsencrypt:key_type', 'value' => 'DSA_1024']);

    expect(fn() => laravelStorage()->getAccountKeyType('letsencrypt'))
        ->toThrow(StorageException::class, 'Unknown account key type [DSA_1024]');
});

it('saves, overwrites, reads and deletes certificates per key type', function (): void {
    $storage = laravelStorage();

    expect($storage->hasCertificate('example.com', KeyType::EC_P256))->toBeFalse()
        ->and($storage->getCertificate('example.com', KeyType::EC_P256))->toBeNull();

    $storage->saveCertificate('example.com', sampleCertificate(marker: 'first'));
    $storage->saveCertificate('example.com', sampleCertificate(marker: 'second'));
    $storage->saveCertificate('example.com', sampleCertificate(KeyType::RSA_2048));

    $cert = $storage->getCertificate('example.com', KeyType::EC_P256);

    expect(DB::table('coyote_cert_storage')->count())->toBe(2)
        ->and($cert?->certificate)->toBe('---second---')
        ->and($cert?->domains)->toBe(['example.com', 'www.example.com'])
        ->and($cert?->providerSlug)->toBe('letsencrypt');

    $storage->deleteCertificate('example.com', KeyType::EC_P256);
    $storage->deleteCertificate('missing.example.com', KeyType::EC_P256);

    expect($storage->hasCertificate('example.com', KeyType::EC_P256))->toBeFalse()
        ->and($storage->hasCertificate('example.com', KeyType::RSA_2048))->toBeTrue();
});

it('throws StorageException for an unreadable stored certificate', function (): void {
    DB::table('coyote_cert_storage')->insert(['store_key' => 'cert:example.com:EC_P256', 'value' => '{"certificate":']);

    expect(fn() => laravelStorage()->getCertificate('example.com', KeyType::EC_P256))
        ->toThrow(StorageException::class, 'unreadable');
});

it('shares its rows with CoyoteCert\'s PDO DatabaseStorage', function (): void {
    $core    = new DatabaseStorage(DB::connection()->getPdo(), 'coyote_cert_storage');
    $laravel = laravelStorage();

    $core->saveAccountKey('letsencrypt', '---core-key---', KeyType::EC_P256);
    $laravel->saveCertificate('example.com', sampleCertificate());

    expect($laravel->getAccountKey('letsencrypt'))->toBe('---core-key---')
        ->and($core->getCertificate('example.com', KeyType::EC_P256)?->certificate)->toBe('---cert---');
});

it('migrates onto the configured connection and applies its table prefix', function (): void {
    config(['coyotecert.database.connection' => 'prefixed']);

    $this->artisan('migrate', ['--path' => realpath(__DIR__ . '/../../../database/migrations'), '--realpath' => true])
        ->assertSuccessful();

    laravelStorage('prefixed')->saveCertificate('example.com', sampleCertificate());

    expect(Schema::connection('prefixed')->hasTable('coyote_cert_storage'))->toBeTrue()
        ->and(DB::connection('prefixed')->getPdo()->query('SELECT COUNT(*) FROM app_coyote_cert_storage')->fetchColumn())->toBe(1);
});

it('wraps database errors in StorageException without leaking the stored value', function (): void {
    $storage = new LaravelDatabaseStorage(app(ConnectionResolverInterface::class), null, 'missing_table');

    try {
        $storage->saveAccountKey('letsencrypt', '---secret-account-key---', KeyType::EC_P256);
        $this->fail('Expected a StorageException.');
    } catch (StorageException $e) {
        expect($e->getMessage())->toContain('Database storage query failed')
            ->and($e->getMessage())->not->toContain('---secret-account-key---')
            ->and($e->getPrevious())->toBeInstanceOf(\PDOException::class);
    }
});
