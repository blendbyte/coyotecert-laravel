<?php

declare(strict_types=1);

namespace CoyoteCert\Laravel\Commands;

use CoyoteCert\Enums\KeyType;
use CoyoteCert\Laravel\CoyoteCertManager;
use Illuminate\Console\Command;

final class StatusCertCommand extends Command
{
    protected $signature = 'cert:status {identity}';

    protected $description = 'Show the status of a stored TLS certificate';

    public function handle(CoyoteCertManager $manager): int
    {
        $identity = (string) $this->input->getArgument('identity');
        $keyType  = KeyType::from((string) config('coyotecert.key_type', 'EC_P256'));

        $cert = $manager->storage()->getCertificate($identity, $keyType);

        if ($cert === null) {
            $this->error("No certificate found for [{$identity}].");

            return Command::FAILURE;
        }

        $this->table(
            ['Field', 'Value'],
            [
                ['Identity',       $identity],
                ['Identifiers',    implode(', ', $cert->domains)],
                ['Key Type',       $cert->keyType->value],
                ['Issued At',      $cert->issuedAt->format('Y-m-d H:i:s')],
                ['Expires At',     $cert->expiresAt->format('Y-m-d H:i:s')],
                ['Days Remaining', (string) $cert->daysUntilExpiry()],
                ['Expired',        $cert->isExpired() ? 'Yes' : 'No'],
            ],
        );

        return Command::SUCCESS;
    }
}
