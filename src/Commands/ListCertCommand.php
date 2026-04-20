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

        /** @var list<list<string>> $entries */
        $entries = array_values(array_map(
            static fn(mixed $e): array => is_array($e)
                ? array_values(array_map('strval', $e))
                : [(string) $e],
            (array) config('coyotecert.identities', []),
        ));

        if ($entries === []) {
            $this->warn('No identities configured. Add identities to coyotecert.identities.');

            return Command::SUCCESS;
        }

        $storage = $manager->storage();
        $rows    = [];

        foreach ($entries as $entry) {
            $primary = $entry[0];
            $cert    = $storage->getCertificate($primary, $keyType);

            if ($cert === null) {
                $rows[] = [implode(', ', $entry), 'Not issued', 'Not issued', '-', '-'];
            } else {
                $rows[] = [
                    implode(', ', $cert->domains),
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
