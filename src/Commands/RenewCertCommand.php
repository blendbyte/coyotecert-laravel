<?php

declare(strict_types=1);

namespace CoyoteCert\Laravel\Commands;

use CoyoteCert\Enums\KeyType;
use CoyoteCert\Exceptions\StorageException;
use CoyoteCert\Laravel\CoyoteCertManager;
use CoyoteCert\Laravel\Events\CertificateExpiring;
use CoyoteCert\Laravel\Events\CertificateFailed;
use CoyoteCert\Storage\StorageInterface;
use CoyoteCert\Storage\StoredCertificate;
use Illuminate\Console\Command;
use Throwable;

final class RenewCertCommand extends Command
{
    protected $signature = 'cert:renew {--identity= : Renew only this identity (the primary name of a configured entry)} {--force : Re-issue even when renewal is not due}';

    protected $description = 'Renew TLS certificates for configured identities';

    public function handle(CoyoteCertManager $manager): int
    {
        $identityOption = $this->option('identity');
        $force          = (bool) $this->option('force');

        try {
            $renewalDays = $manager->renewalDays();
            $keyType     = $manager->resolveKeyType();
            $storage     = $manager->storage();
            $entries     = is_string($identityOption)
                ? [$this->entryFor($manager, $storage, $keyType, $identityOption)]
                : $manager->configuredIdentities();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($entries === []) {
            $this->warn('No identities configured. Add identities to coyotecert.identities or pass --identity.');

            return Command::SUCCESS;
        }

        $anyFailed = false;

        foreach ($entries as $identities) {
            $primary = $identities[0];

            try {
                $cert     = $manager->for($identities);
                $existing = $this->stored($storage, $primary, $keyType);

                if (!$force) {
                    if (!$cert->needsRenewal($renewalDays)) {
                        $days = $existing !== null ? " ({$existing->daysUntilExpiry()} days remaining)" : '';
                        $this->line("Skipped: {$primary}{$days}");

                        continue;
                    }

                    if ($existing !== null) {
                        event(new CertificateExpiring($existing, $primary, $existing->daysUntilExpiry()));
                    }
                }

                $cert->issue();

                $this->info(($existing !== null ? 'Renewed: ' : 'Issued: ') . $primary);
            } catch (Throwable $e) {
                $this->error("Failed [{$primary}]: " . $e->getMessage());
                event(new CertificateFailed($primary, $e));
                $anyFailed = true;
            }
        }

        return $anyFailed ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * The identities to renew for --identity: the configured entry with that primary name, else
     * the names on the stored certificate (so SANs survive), else just the identity itself.
     *
     * @return non-empty-list<string>
     */
    private function entryFor(CoyoteCertManager $manager, StorageInterface $storage, KeyType $keyType, string $identity): array
    {
        $primary = CoyoteCertManager::normalizeIdentities($identity)[0]
            ?? throw new \InvalidArgumentException('The --identity option needs a value.');

        foreach ($manager->configuredIdentities() as $identities) {
            if ($identities[0] === $primary) {
                return $identities;
            }
        }

        $stored = CoyoteCertManager::normalizeIdentities($this->stored($storage, $primary, $keyType)->domains ?? []);

        return $stored !== [] && $stored[0] === $primary ? $stored : [$primary];
    }

    /** A record that can't be read counts as missing, as it does for CoyoteCert, which replaces it. */
    private function stored(StorageInterface $storage, string $primary, KeyType $keyType): ?StoredCertificate
    {
        try {
            return $storage->getCertificate($primary, $keyType);
        } catch (StorageException $e) {
            $this->warn("Could not read the stored certificate for [{$primary}]: " . $e->getMessage());

            return null;
        }
    }
}
