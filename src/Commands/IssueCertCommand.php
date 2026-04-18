<?php

declare(strict_types=1);

namespace CoyoteCert\Laravel\Commands;

use CoyoteCert\Laravel\CoyoteCertManager;
use Illuminate\Console\Command;

final class IssueCertCommand extends Command
{
    protected $signature = 'cert:issue {domain}';

    protected $description = 'Issue a new TLS certificate for a domain';

    public function handle(CoyoteCertManager $manager): int
    {
        $domain = (string) $this->input->getArgument('domain');

        $cert = $manager->for($domain)->issue();

        $this->info('Certificate issued successfully.');
        $this->line('Expires: ' . $cert->expiresAt->format('Y-m-d H:i:s'));
        $this->line('Days remaining: ' . $cert->daysUntilExpiry());

        return Command::SUCCESS;
    }
}
