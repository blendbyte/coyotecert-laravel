<?php

declare(strict_types=1);

namespace CoyoteCert\Laravel\Commands;

use CoyoteCert\Exceptions\StorageException;
use CoyoteCert\Laravel\CoyoteCertManager;
use Illuminate\Console\Command;
use Throwable;

final class ListCertCommand extends Command
{
    protected $signature = 'cert:list';

    protected $description = 'List all configured identities and their certificate status';

    public function handle(CoyoteCertManager $manager): int
    {
        try {
            $entries = $manager->configuredIdentities();
            $keyType = $manager->resolveKeyType();
            $storage = $manager->storage();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($entries === []) {
            $this->warn('No identities configured. Add identities to coyotecert.identities.');

            return Command::SUCCESS;
        }

        $rows = [];

        foreach ($entries as $entry) {
            $label = implode(', ', $entry);

            try {
                $cert = $storage->getCertificate($entry[0], $keyType);
            } catch (StorageException) {
                $rows[] = [$label, 'Unreadable', 'Unreadable', '-', '-'];

                continue;
            }

            $rows[] = $cert === null
                ? [$label, 'Not issued', 'Not issued', '-', '-']
                : [
                    implode(', ', $cert->domains),
                    $cert->issuedAt->format('Y-m-d H:i:s'),
                    $cert->expiresAt->format('Y-m-d H:i:s'),
                    (string) $cert->daysUntilExpiry(),
                    $cert->isExpired() ? 'Yes' : 'No',
                ];
        }

        $this->table(
            ['Identity', 'Issued At', 'Expires At', 'Days Remaining', 'Expired'],
            $rows,
        );

        return Command::SUCCESS;
    }
}
