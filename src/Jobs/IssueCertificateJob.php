<?php

declare(strict_types=1);

namespace CoyoteCert\Laravel\Jobs;

use CoyoteCert\Laravel\CoyoteCertManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class IssueCertificateJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $domain,
        public readonly int $renewalDays = 30,
    ) {}

    public function handle(CoyoteCertManager $manager): void
    {
        $manager->for($this->domain)->issueOrRenew($this->renewalDays);
    }
}
