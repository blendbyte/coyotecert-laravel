<?php

namespace CoyoteCert\Laravel\Events;

use CoyoteCert\Storage\StoredCertificate;

final class CertificateRenewed
{
    public function __construct(
        public readonly StoredCertificate $certificate,
        public readonly string $domain,
    ) {}
}
