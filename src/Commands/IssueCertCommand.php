<?php

declare(strict_types=1);

namespace CoyoteCert\Laravel\Commands;

use CoyoteCert\Laravel\CoyoteCertManager;
use Illuminate\Console\Command;
use Throwable;

final class IssueCertCommand extends Command
{
    protected $signature = 'cert:issue {domain}';

    protected $description = 'Issue a new TLS certificate for a domain';

    public function handle(CoyoteCertManager $manager): int
    {
        $domain = (string) $this->input->getArgument('domain');

        try {
            $cert = $manager->for($domain)->issue();
        } catch (Throwable $e) {
            $this->error("Failed to issue certificate for [{$domain}]: " . $e->getMessage());

            return Command::FAILURE;
        }

        $this->info('Certificate issued successfully.');
        $this->line('Expires: ' . $cert->expiresAt->format('Y-m-d H:i:s'));
        $this->line('Days remaining: ' . $cert->daysUntilExpiry());

        return Command::SUCCESS;
    }
}
