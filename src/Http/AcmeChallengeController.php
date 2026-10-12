<?php

declare(strict_types=1);

namespace CoyoteCert\Laravel\Http;

use CoyoteCert\Laravel\Challenge\CacheHttp01Handler;
use Illuminate\Http\Response;

/** Serves HTTP-01 key authorizations deployed by CacheHttp01Handler. */
final class AcmeChallengeController
{
    public function __invoke(CacheHttp01Handler $handler, string $token): Response
    {
        $content = $handler->keyAuthorization($token);

        if ($content === null) {
            abort(404);
        }

        return new Response($content, 200, ['Content-Type' => 'text/plain']);
    }
}
