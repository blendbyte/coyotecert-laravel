<?php

declare(strict_types=1);

namespace CoyoteCert\Laravel\Commands;

use CoyoteCert\Enums\KeyType;
use CoyoteCert\Enums\RevocationReason;
use CoyoteCert\Laravel\CoyoteCertManager;
use Illuminate\Console\Command;

final class RevokeCertCommand extends Command
{
    protected $signature = 'cert:revoke {identity} {--reason=0}';

    protected $description = 'Revoke and delete a stored TLS certificate';

    public function handle(CoyoteCertManager $manager): int
    {
        $identity     = (string) $this->input->getArgument('identity');
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
        $cert    = $storage->getCertificate($identity, $keyType);

        if ($cert === null) {
            $this->error("No certificate found for [{$identity}].");

            return Command::FAILURE;
        }

        $manager->for($identity)->revoke($cert, $reason);
        $storage->deleteCertificate($identity, $keyType);

        $this->info("Certificate for [{$identity}] has been revoked and deleted.");

        return Command::SUCCESS;
    }
}
