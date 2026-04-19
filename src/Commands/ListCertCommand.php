<?php

declare(strict_types=1);

namespace CoyoteCert\Laravel\Commands;

use CoyoteCert\Laravel\CoyoteCertManager;
use Illuminate\Console\Command;

final class ListCertCommand extends Command
{
    protected $signature = 'cert:list';

    protected $description = 'List all configured identities and their certificate status';

    public function handle(CoyoteCertManager $manager): int
    {
        $keyType = $manager->resolveKeyType();

        /** @var list<string> $identities */
        $identities = array_values(array_map('strval', (array) config('coyotecert.identities', [])));

        if ($identities === []) {
            $this->warn('No identities configured. Add identities to coyotecert.identities.');

            return Command::SUCCESS;
        }

        $storage = $manager->storage();
        $rows    = [];

        foreach ($identities as $identity) {
            $cert = $storage->getCertificate($identity, $keyType);

            if ($cert === null) {
                $rows[] = [$identity, 'Not issued', 'Not issued', '-', '-'];
            } else {
                $rows[] = [
                    $identity,
                    $cert->issuedAt->format('Y-m-d H:i:s'),
                    $cert->expiresAt->format('Y-m-d H:i:s'),
                    (string) $cert->daysUntilExpiry(),
                    $cert->isExpired() ? 'Yes' : 'No',
                ];
            }
        }

        $this->table(
            ['Identity', 'Issued At', 'Expires At', 'Days Remaining', 'Expired'],
            $rows,
        );

        return Command::SUCCESS;
    }
}
