<?php

declare(strict_types=1);

namespace CoyoteCert\Laravel\Events;

final class CertificateFailed
{
    public function __construct(
        public readonly string $identity,
        public readonly \Throwable $exception,
    ) {}
}
