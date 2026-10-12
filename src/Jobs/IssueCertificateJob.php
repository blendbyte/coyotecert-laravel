<?php

declare(strict_types=1);

namespace CoyoteCert\Laravel\Jobs;

use CoyoteCert\Laravel\CoyoteCertManager;
use CoyoteCert\Laravel\Events\CertificateFailed;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class IssueCertificateJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param string|array<string> $identities
     * @param int|null $renewalDays Fixed renewal threshold in days; null uses coyotecert.renewal_days
     */
    public function __construct(
        public readonly string|array $identities,
        public readonly ?int $renewalDays = null,
    ) {}

    public function handle(CoyoteCertManager $manager): void
    {
        $manager->for($this->identities)->issueOrRenew($this->renewalDays ?? $manager->renewalDays());
    }

    /** Called by the queue once the job has used up its attempts. */
    public function failed(Throwable $exception): void
    {
        $identities = CoyoteCertManager::normalizeIdentities($this->identities);

        event(new CertificateFailed($identities[0] ?? '', $exception));
    }
}
