<?php

declare(strict_types=1);

namespace CoyoteCert\Laravel\Commands;

use CoyoteCert\Laravel\CoyoteCertManager;
use CoyoteCert\Laravel\Events\CertificateExpiring;
use CoyoteCert\Laravel\Events\CertificateFailed;
use Illuminate\Console\Command;
use Throwable;

final class RenewCertCommand extends Command
{
    protected $signature = 'cert:renew {--identity=} {--force}';

    protected $description = 'Renew TLS certificates for configured identities';

    public function handle(CoyoteCertManager $manager): int
    {
        $identityOption = $this->input->getOption('identity');
        $force          = (bool) $this->input->getOption('force');
        $renewalDays    = (int) config('coyotecert.renewal_days', 30);
        $keyType        = $manager->resolveKeyType();

        /** @var list<list<string>> $entries */
        $entries = $identityOption !== null
            ? [[(string) $identityOption]]
            : array_values(array_map(
                static fn(mixed $e): array => is_array($e)
                    ? array_values(array_map('strval', $e))
                    : [(string) $e],
                (array) config('coyotecert.identities', []),
            ));

        if ($entries === []) {
            $this->warn('No identities configured. Add identities to coyotecert.identities or pass --identity.');

            return Command::SUCCESS;
        }

        $anyFailed = false;

        foreach ($entries as $entry) {
            $primary = $entry[0];

            try {
                // Always fetch existing cert — needed to preserve SANs on renewal
                $existing = $manager->storage()->getCertificate($primary, $keyType);

                if (!$force && $existing !== null) {
                    if ($existing->expiresWithin($renewalDays)) {
                        event(new CertificateExpiring($existing, $primary, $existing->daysUntilExpiry()));
                    } else {
                        $this->line("Skipped: {$primary} ({$existing->daysUntilExpiry()} days remaining)");
                        continue;
                    }
                }

                // Prefer stored domains to preserve SANs on renewal; fall back to config entry
                $domains     = $existing !== null ? $existing->domains : $entry;
                $identifiers = count($domains) === 1 ? $domains[0] : $domains;

                if ($force) {
                    $manager->for($identifiers)->issue();
                } else {
                    $manager->for($identifiers)->issueOrRenew($renewalDays);
                }

                $this->info("Renewed: {$primary}");
            } catch (Throwable $e) {
                $this->error("Failed [{$primary}]: " . $e->getMessage());
                event(new CertificateFailed($primary, $e));
                $anyFailed = true;
            }
        }

        return $anyFailed ? Command::FAILURE : Command::SUCCESS;
    }
}
