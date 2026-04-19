<?php

declare(strict_types=1);

namespace CoyoteCert\Laravel\Commands;

use CoyoteCert\Enums\KeyType;
use CoyoteCert\Laravel\CoyoteCertManager;
use CoyoteCert\Laravel\Events\CertificateExpiring;
use Illuminate\Console\Command;
use Throwable;

final class RenewCertCommand extends Command
{
    protected $signature = 'cert:renew {--domain=} {--force}';

    protected $description = 'Renew TLS certificates for configured domains';

    public function handle(CoyoteCertManager $manager): int
    {
        $domainOption = $this->input->getOption('domain');
        $force        = (bool) $this->input->getOption('force');
        $renewalDays  = (int) config('coyotecert.renewal_days', 30);
        $keyType      = KeyType::from((string) config('coyotecert.key_type', 'EC_P256'));

        /** @var list<string> $domains */
        $domains = $domainOption !== null
            ? [(string) $domainOption]
            : array_values(array_map('strval', (array) config('coyotecert.domains', [])));

        if ($domains === []) {
            $this->warn('No domains configured. Add domains to coyotecert.domains or pass --domain.');

            return Command::SUCCESS;
        }

        $anyFailed = false;

        foreach ($domains as $domain) {
            try {
                if (!$force) {
                    $existing = $manager->storage()->getCertificate($domain, $keyType);

                    if ($existing !== null) {
                        if ($existing->expiresWithin($renewalDays)) {
                            event(new CertificateExpiring($existing, $domain, $existing->daysUntilExpiry()));
                        } else {
                            $this->line("Skipped: {$domain} ({$existing->daysUntilExpiry()} days remaining)");
                            continue;
                        }
                    }
                }

                if ($force) {
                    $manager->for($domain)->issue();
                } else {
                    $manager->for($domain)->issueOrRenew($renewalDays);
                }

                $this->info("Renewed: {$domain}");
            } catch (Throwable $e) {
                $this->error("Failed [{$domain}]: " . $e->getMessage());
                $anyFailed = true;
            }
        }

        return $anyFailed ? Command::FAILURE : Command::SUCCESS;
    }
}
