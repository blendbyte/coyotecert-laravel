<?php

declare(strict_types=1);

namespace CoyoteCert\Laravel\Commands;

use CoyoteCert\Enums\KeyType;
use CoyoteCert\Laravel\CoyoteCertManager;
use Illuminate\Console\Command;

final class ListCertCommand extends Command
{
    protected $signature = 'cert:list';

    protected $description = 'List all configured domains and their certificate status';

    public function handle(CoyoteCertManager $manager): int
    {
        $keyType = KeyType::from((string) config('coyotecert.key_type', 'EC_P256'));

        /** @var list<string> $domains */
        $domains = array_values(array_map('strval', (array) config('coyotecert.domains', [])));

        if ($domains === []) {
            $this->warn('No domains configured. Add domains to coyotecert.domains.');

            return Command::SUCCESS;
        }

        $storage = $manager->storage();
        $rows    = [];

        foreach ($domains as $domain) {
            $cert = $storage->getCertificate($domain, $keyType);

            if ($cert === null) {
                $rows[] = [$domain, 'Not issued', 'Not issued', '-', '-'];
            } else {
                $rows[] = [
                    $domain,
                    $cert->issuedAt->format('Y-m-d H:i:s'),
                    $cert->expiresAt->format('Y-m-d H:i:s'),
                    (string) $cert->daysUntilExpiry(),
                    $cert->isExpired() ? 'Yes' : 'No',
                ];
            }
        }

        $this->table(
            ['Domain', 'Issued At', 'Expires At', 'Days Remaining', 'Expired'],
            $rows,
        );

        return Command::SUCCESS;
    }
}
