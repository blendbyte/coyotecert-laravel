<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;

it('returns key authorization with text/plain content-type when the token exists in cache', function (): void {
    Cache::put('acme-challenge:test-token', 'test-token.fingerprint', 3600);

    $response = $this->get('/.well-known/acme-challenge/test-token');

    $response->assertStatus(200);
    expect($response->headers->get('Content-Type'))->toContain('text/plain');
    $response->assertSee('test-token.fingerprint', false);
});

it('returns 404 when the token is not present in cache', function (): void {
    Cache::forget('acme-challenge:missing-token');

    $this->get('/.well-known/acme-challenge/missing-token')
        ->assertStatus(404);
});
