<?php

declare(strict_types=1);

namespace CoyoteCert\Laravel\Commands;

use CoyoteCert\Enums\KeyType;
use CoyoteCert\Enums\RevocationReason;
use CoyoteCert\Laravel\CoyoteCertManager;
use Illuminate\Console\Command;

final class RevokeCertCommand extends Command
{
    protected $signature = 'cert:revoke {domain} {--reason=0}';

    protected $description = 'Revoke and delete a stored TLS certificate';

    public function handle(CoyoteCertManager $manager): int
    {
        $domain       = (string) $this->input->getArgument('domain');
        $reasonInt    = (int) $this->input->getOption('reason');
        $validReasons = array_column(RevocationReason::cases(), 'value');

        if (!in_array($reasonInt, $validReasons, true)) {
            $this->error("Invalid reason code [{$reasonInt}].");
            $this->line('Valid codes: ' . implode(', ', $validReasons) . '.');

            return Command::FAILURE;
        }

        $reason  = RevocationReason::from($reasonInt);
        $keyType = KeyType::from((string) config('coyotecert.key_type', 'EC_P256'));

        $storage = $manager->storage();
        $cert    = $storage->getCertificate($domain, $keyType);

        if ($cert === null) {
            $this->error("No certificate found for [{$domain}].");

            return Command::FAILURE;
        }

        $manager->for($domain)->revoke($cert, $reason);
        $storage->deleteCertificate($domain, $keyType);

        $this->info("Certificate for [{$domain}] has been revoked and deleted.");

        return Command::SUCCESS;
    }
}
