<?php

declare(strict_types=1);

namespace CoyoteCert\Laravel;

use CoyoteCert\CoyoteCert;
use CoyoteCert\Enums\KeyType;
use CoyoteCert\Interfaces\ChallengeHandlerInterface;
use CoyoteCert\Laravel\Challenge\CacheHttp01Handler;
use CoyoteCert\Laravel\Events\CertificateIssued;
use CoyoteCert\Laravel\Events\CertificateRenewed;
use CoyoteCert\Provider\AcmeProviderInterface;
use CoyoteCert\Provider\BuypassGo;
use CoyoteCert\Provider\BuypassGoStaging;
use CoyoteCert\Provider\CustomProvider;
use CoyoteCert\Provider\GoogleTrustServices;
use CoyoteCert\Provider\LetsEncrypt;
use CoyoteCert\Provider\LetsEncryptStaging;
use CoyoteCert\Provider\ZeroSSL;
use CoyoteCert\Laravel\Storage\ReconnectingPdo;
use CoyoteCert\Storage\DatabaseStorage;
use CoyoteCert\Storage\FilesystemStorage;
use CoyoteCert\Storage\StorageInterface;
use CoyoteCert\Storage\StoredCertificate;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

final class CoyoteCertManager
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly CacheRepository $cache,
        private readonly Dispatcher $events,
        private readonly DatabaseManager $db,
        private readonly LoggerInterface $logger,
    ) {}

    /** @param string|array<int, string> $identities */
    public function for(string|array $identities): CoyoteCert
    {
        $email   = (string) $this->config->get('coyotecert.email', '');
        $keyType = $this->resolveKeyType();

        return CoyoteCert::with($this->resolveProvider())
            ->email($email)
            ->storage($this->storage())
            ->logger($this->logger)
            ->identifiers($identities)
            ->challenge($this->resolveChallenge())
            ->keyType($keyType)
            ->onIssued(function (StoredCertificate $certificate) use ($identities): void {
                $identity = is_array($identities) ? $identities[0] : $identities;
                $this->events->dispatch(new CertificateIssued($certificate, $identity));
            })
            ->onRenewed(function (StoredCertificate $certificate) use ($identities): void {
                $identity = is_array($identities) ? $identities[0] : $identities;
                $this->events->dispatch(new CertificateRenewed($certificate, $identity));
            });
    }

    public function storage(): StorageInterface
    {
        $type = (string) $this->config->get('coyotecert.storage', 'database');

        return match ($type) {
            'filesystem' => new FilesystemStorage(
                (string) ($this->config->get('coyotecert.filesystem.path') ?? storage_path('coyotecert')),
            ),
            'database' => $this->databaseStorage(),
            default    => throw new InvalidArgumentException(
                "Unknown storage driver [{$type}]. Supported: filesystem, database.",
            ),
        };
    }

    public function resolveKeyType(): KeyType
    {
        $value   = (string) $this->config->get('coyotecert.key_type', 'EC_P256');
        $keyType = KeyType::tryFrom($value);

        if ($keyType === null) {
            $valid = implode(', ', array_column(KeyType::cases(), 'value'));
            throw new InvalidArgumentException(
                "Invalid key type [{$value}]. Set COYOTECERT_KEY_TYPE to one of: {$valid}.",
            );
        }

        return $keyType;
    }

    private function requireConfig(string $key, string $envVar): string
    {
        $value = (string) $this->config->get($key, '');

        if ($value === '') {
            throw new InvalidArgumentException(
                "Missing required configuration [{$key}]. Set {$envVar} in your .env.",
            );
        }

        return $value;
    }

    private function resolveProvider(): AcmeProviderInterface
    {
        $provider = (string) $this->config->get('coyotecert.provider', '');

        if ($provider === '') {
            throw new InvalidArgumentException(
                'No ACME provider configured. Set COYOTECERT_PROVIDER in your .env (letsencrypt, letsencrypt-staging, buypass, buypass-staging, zerossl, google, custom).',
            );
        }

        return match ($provider) {
            'letsencrypt'         => new LetsEncrypt(),
            'letsencrypt-staging' => new LetsEncryptStaging(),
            'buypass'             => new BuypassGo(),
            'buypass-staging'     => new BuypassGoStaging(),
            'zerossl' => new ZeroSSL(
                $this->requireConfig('coyotecert.providers.zerossl.api_key', 'COYOTECERT_ZEROSSL_API_KEY'),
            ),
            'google' => new GoogleTrustServices(
                $this->requireConfig('coyotecert.providers.google.eab_kid', 'COYOTECERT_GOOGLE_EAB_KID'),
                $this->requireConfig('coyotecert.providers.google.eab_hmac', 'COYOTECERT_GOOGLE_EAB_HMAC'),
            ),
            'custom' => new CustomProvider(
                $this->requireConfig('coyotecert.providers.custom.directory_url', 'COYOTECERT_CUSTOM_DIRECTORY_URL'),
            ),
            default => throw new InvalidArgumentException(
                "Unknown provider [{$provider}]. Supported: letsencrypt, letsencrypt-staging, buypass, buypass-staging, zerossl, google, custom.",
            ),
        };
    }

    private function resolveChallenge(): ChallengeHandlerInterface
    {
        $challenge = (string) $this->config->get('coyotecert.challenge', 'http-01');

        return match ($challenge) {
            'http-01' => new CacheHttp01Handler($this->cache),
            'dns-01'  => throw new InvalidArgumentException(
                'DNS-01 challenge requires a custom handler. Bind a ChallengeHandlerInterface implementation in your service container.',
            ),
            default => throw new InvalidArgumentException(
                "Unknown challenge type [{$challenge}]. For dns-01, bind a custom ChallengeHandlerInterface.",
            ),
        };
    }

    private function databaseStorage(): DatabaseStorage
    {
        $conn  = (string) ($this->config->get('coyotecert.database.connection') ?? $this->config->get('database.default', 'mysql'));
        $table = (string) $this->config->get('coyotecert.database.table', 'coyote_cert_storage');
        $db    = $this->db;

        return new DatabaseStorage(
            new ReconnectingPdo(fn (): \PDO => $db->connection($conn)->getPdo()),
            $table,
        );
    }
}
