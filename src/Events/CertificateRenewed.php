<?php

declare(strict_types=1);

namespace CoyoteCert\Laravel\Events;

use CoyoteCert\Storage\StoredCertificate;

final class CertificateRenewed
{
    public function __construct(
        public readonly StoredCertificate $certificate,
        public readonly string $identity,
    ) {}
}
