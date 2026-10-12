<?php

declare(strict_types=1);

namespace CoyoteCert\Laravel\Commands;

use CoyoteCert\Enums\RevocationReason;
use CoyoteCert\Laravel\CoyoteCertManager;
use Illuminate\Console\Command;
use Throwable;

final class RevokeCertCommand extends Command
{
    protected $signature = 'cert:revoke {identity : The primary name the certificate is stored under} {--reason=0 : RFC 5280 revocation reason code}';

    protected $description = 'Revoke and delete a stored TLS certificate';

    public function handle(CoyoteCertManager $manager): int
    {
        $identity  = strtolower(trim((string) $this->input->getArgument('identity')));
        $reasonArg = (string) $this->input->getOption('reason');
        $reason    = ctype_digit($reasonArg) ? RevocationReason::tryFrom((int) $reasonArg) : null;

        if ($reason === null) {
            $this->error("Invalid reason code [{$reasonArg}].");
            $this->line('Valid codes: ' . implode(', ', array_column(RevocationReason::cases(), 'value')) . '.');

            return Command::FAILURE;
        }

        try {
            $keyType = $manager->resolveKeyType();
            $storage = $manager->storage();
            $cert    = $storage->getCertificate($identity, $keyType);

            if ($cert === null) {
                $this->error("No certificate found for [{$identity}].");

                return Command::FAILURE;
            }

            $manager->for($identity)->revoke($cert, $reason);
        } catch (Throwable $e) {
            $this->error("Failed to revoke certificate for [{$identity}]: " . $e->getMessage());

            return Command::FAILURE;
        }

        try {
            $storage->deleteCertificate($identity, $keyType);
        } catch (Throwable $e) {
            $this->error("Certificate for [{$identity}] was revoked, but deleting it from storage failed: " . $e->getMessage());

            return Command::FAILURE;
        }

        $this->info("Certificate for [{$identity}] has been revoked and deleted.");

        return Command::SUCCESS;
    }
}
