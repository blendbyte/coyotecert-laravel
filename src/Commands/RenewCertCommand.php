<?php

declare(strict_types=1);

namespace CoyoteCert\Laravel\Commands;

use CoyoteCert\Laravel\CoyoteCertManager;
use CoyoteCert\Laravel\Events\CertificateExpiring;
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

        /** @var list<string> $identities */
        $identities = $identityOption !== null
            ? [(string) $identityOption]
            : array_values(array_map('strval', (array) config('coyotecert.identities', [])));

        if ($identities === []) {
            $this->warn('No identities configured. Add identities to coyotecert.identities or pass --identity.');

            return Command::SUCCESS;
        }

        $anyFailed = false;

        foreach ($identities as $identity) {
            try {
                if (!$force) {
                    $existing = $manager->storage()->getCertificate($identity, $keyType);

                    if ($existing !== null) {
                        if ($existing->expiresWithin($renewalDays)) {
                            event(new CertificateExpiring($existing, $identity, $existing->daysUntilExpiry()));
                        } else {
                            $this->line("Skipped: {$identity} ({$existing->daysUntilExpiry()} days remaining)");
                            continue;
                        }
                    }
                }

                if ($force) {
                    $manager->for($identity)->issue();
                } else {
                    $manager->for($identity)->issueOrRenew($renewalDays);
                }

                $this->info("Renewed: {$identity}");
            } catch (Throwable $e) {
                $this->error("Failed [{$identity}]: " . $e->getMessage());
                $anyFailed = true;
            }
        }

        return $anyFailed ? Command::FAILURE : Command::SUCCESS;
    }
}
