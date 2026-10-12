<?php

declare(strict_types=1);

namespace CoyoteCert\Laravel;

use CoyoteCert\CoyoteCert;
use CoyoteCert\Enums\KeyType;
use CoyoteCert\Interfaces\ChallengeHandlerInterface;
use CoyoteCert\Laravel\Challenge\CacheHttp01Handler;
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
use CoyoteCert\Storage\StorageInterface;
use CoyoteCert\Storage\StoredCertificate;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

final class CoyoteCertManager
{
    private const PROVIDERS = 'letsencrypt, letsencrypt-staging, zerossl, google, sslcom, custom';

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly Dispatcher $events,
        private readonly ConnectionResolverInterface $db,
        private readonly LoggerInterface $logger,
        private readonly Container $container,
    ) {}

    /** @param array<string>|string $identities */
    public function for(string|array $identities): CoyoteCert
    {
        $identities = self::normalizeIdentities($identities);

        if ($identities === []) {
            throw new InvalidArgumentException('At least one identity is required.');
        }

        // CoyoteCert stores certificates under the lower-cased first identifier
        $primary = $identities[0];

        $cert = CoyoteCert::with($this->resolveProvider())
            ->email((string) $this->config->get('coyotecert.email', ''))
            ->storage($this->storage())
            ->logger($this->logger)
            ->identifiers($identities)
            ->challenge($this->resolveChallenge())
            ->keyType($this->resolveKeyType())
            ->onIssued(function (StoredCertificate $certificate) use ($primary): void {
                $this->events->dispatch(new CertificateIssued($certificate, $primary));
            })
            ->onRenewed(function (StoredCertificate $certificate) use ($primary): void {
                $this->events->dispatch(new CertificateRenewed($certificate, $primary));
            });

        $profile = (string) $this->config->get('coyotecert.profile', '');

        if ($profile !== '') {
            $cert->profile($profile);
        }

        $preferredChain = (string) $this->config->get('coyotecert.preferred_chain', '');

        if ($preferredChain !== '') {
            $cert->preferredChain($preferredChain);
        }

        return $cert;
    }

    public function storage(): StorageInterface
    {
        $type = (string) $this->config->get('coyotecert.storage', 'database');

        return match ($type) {
            'filesystem' => new FilesystemStorage(
                (string) ($this->config->get('coyotecert.filesystem.path') ?? storage_path('coyotecert')),
            ),
            'database' => new LaravelDatabaseStorage(
                $this->db,
                self::stringOrNull($this->config->get('coyotecert.database.connection')),
                (string) $this->config->get('coyotecert.database.table', 'coyote_cert_storage'),
            ),
            default => throw new InvalidArgumentException(
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

    /**
     * The fixed renewal threshold in days, or null to let CoyoteCert decide: the CA's ARI
     * window when it offers one, otherwise once a third of the certificate's lifetime remains.
     */
    public function renewalDays(): ?int
    {
        $value = $this->config->get('coyotecert.renewal_days');

        if ($value === null || $value === '') {
            return null;
        }

        $days = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        if ($days === false) {
            throw new InvalidArgumentException(
                'Invalid renewal window [' . (is_scalar($value) ? (string) $value : get_debug_type($value)) . ']. Set COYOTECERT_RENEWAL_DAYS to a whole number of days, or leave it empty.',
            );
        }

        return $days;
    }

    /**
     * Configured identities as lists of lower-cased names; the first name is the storage key.
     *
     * @return list<non-empty-list<string>>
     */
    public function configuredIdentities(): array
    {
        $entries = [];

        foreach ((array) $this->config->get('coyotecert.identities', []) as $entry) {
            $identities = self::normalizeIdentities(is_array($entry) ? $entry : (string) $entry);

            if ($identities !== []) {
                $entries[] = $identities;
            }
        }

        return $entries;
    }

    /**
     * @param array<mixed>|string $identities
     *
     * @return list<string>
     */
    public static function normalizeIdentities(string|array $identities): array
    {
        $list = array_map(
            static fn(mixed $identity): string => strtolower(trim(is_scalar($identity) ? (string) $identity : '')),
            is_array($identities) ? array_values($identities) : [$identities],
        );

        return array_values(array_unique(array_filter($list, static fn(string $identity): bool => $identity !== '')));
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
                'No ACME provider configured. Set COYOTECERT_PROVIDER in your .env (' . self::PROVIDERS . ').',
            );
        }

        return match ($provider) {
            'letsencrypt'         => new LetsEncrypt(),
            'letsencrypt-staging' => new LetsEncryptStaging(),
            'zerossl'             => new ZeroSSL(
                $this->requireConfig('coyotecert.providers.zerossl.api_key', 'COYOTECERT_ZEROSSL_API_KEY'),
            ),
            'google' => new GoogleTrustServices(
                $this->requireConfig('coyotecert.providers.google.eab_kid', 'COYOTECERT_GOOGLE_EAB_KID'),
                $this->requireConfig('coyotecert.providers.google.eab_hmac', 'COYOTECERT_GOOGLE_EAB_HMAC'),
            ),
            'sslcom' => new SslCom(
                self::stringOrNull($this->config->get('coyotecert.providers.sslcom.eab_kid')),
                self::stringOrNull($this->config->get('coyotecert.providers.sslcom.eab_hmac')),
                (bool) $this->config->get('coyotecert.providers.sslcom.ecc', false),
            ),
            'custom' => new CustomProvider(
                $this->requireConfig('coyotecert.providers.custom.directory_url', 'COYOTECERT_CUSTOM_DIRECTORY_URL'),
                eabKid: self::stringOrNull($this->config->get('coyotecert.providers.custom.eab_kid')),
                eabHmac: self::stringOrNull($this->config->get('coyotecert.providers.custom.eab_hmac')),
            ),
            'buypass', 'buypass-staging' => throw new InvalidArgumentException(
                'Buypass ended its ACME service in April 2026 and is no longer supported. Set COYOTECERT_PROVIDER to one of: ' . self::PROVIDERS . '.',
            ),
            default => throw new InvalidArgumentException(
                "Unknown provider [{$provider}]. Supported: " . self::PROVIDERS . '.',
            ),
        };
    }

    private function resolveChallenge(): ChallengeHandlerInterface
    {
        $challenge = (string) $this->config->get('coyotecert.challenge', 'http-01');

        return match ($challenge) {
            'http-01' => $this->container->make(CacheHttp01Handler::class),
            'dns-01'  => $this->container->bound(ChallengeHandlerInterface::class)
                ? $this->container->make(ChallengeHandlerInterface::class)
                : throw new InvalidArgumentException(
                    'The dns-01 challenge needs a handler. Bind a ChallengeHandlerInterface implementation in your service container.',
                ),
            default => throw new InvalidArgumentException(
                "Unknown challenge type [{$challenge}]. Supported: http-01, dns-01.",
            ),
        };
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
