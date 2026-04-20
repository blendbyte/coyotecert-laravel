<?php

declare(strict_types=1);

namespace CoyoteCert\Laravel\Commands;

use CoyoteCert\Laravel\CoyoteCertManager;
use CoyoteCert\Laravel\Events\CertificateFailed;
use Illuminate\Console\Command;
use Throwable;

final class IssueCertCommand extends Command
{
    protected $signature = 'cert:issue {identity*}';

    protected $description = 'Issue a new TLS certificate (pass multiple identities for a SAN cert)';

    public function handle(CoyoteCertManager $manager): int
    {
        /** @var non-empty-list<string> $identities */
        $identities  = array_values(array_map('strval', (array) $this->argument('identity')));
        $label       = implode(', ', $identities);
        $identifiers = count($identities) === 1 ? $identities[0] : $identities;

        try {
            $cert = $manager->for($identifiers)->issue();
        } catch (Throwable $e) {
            $this->error("Failed to issue certificate for [{$label}]: " . $e->getMessage());
            event(new CertificateFailed($identities[0], $e));

            return Command::FAILURE;
        }

        $this->info('Certificate issued successfully.');
        $this->line('Identities: ' . implode(', ', $cert->domains));
        $this->line('Expires: ' . $cert->expiresAt->format('Y-m-d H:i:s'));
        $this->line('Days remaining: ' . $cert->daysUntilExpiry());

        return Command::SUCCESS;
    }
}
