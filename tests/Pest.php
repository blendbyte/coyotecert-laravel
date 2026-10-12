<?php

namespace {
    use CoyoteCert\Enums\KeyType;
    use CoyoteCert\Storage\StoredCertificate;
    use Tests\TestCase;

    \DG\BypassFinals::enable();

    pest()->extend(TestCase::class)->in('Unit', 'Feature');

    afterEach(fn() => \Mockery::close());

    /** @param list<string> $domains */
    function fakeCertificate(array $domains = ['example.com'], string $expiresAt = '+90 days', string $issuedAt = 'now'): StoredCertificate
    {
        return new StoredCertificate(
            certificate: '---cert---',
            privateKey: '---key---',
            fullchain: '---fullchain---',
            caBundle: '---ca---',
            issuedAt: new DateTimeImmutable($issuedAt),
            expiresAt: new DateTimeImmutable($expiresAt),
            domains: $domains,
            keyType: KeyType::EC_P256,
            providerSlug: 'letsencrypt',
        );
    }
}
