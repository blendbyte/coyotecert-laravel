<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

it('returns key authorization with text/plain content-type when the token exists in cache', function (): void {
    Cache::put('acme-challenge:test-token_1', 'test-token_1.fingerprint', 3600);

    $response = $this->get('/.well-known/acme-challenge/test-token_1');

    $response->assertStatus(200);
    expect($response->headers->get('Content-Type'))->toContain('text/plain');
    expect($response->getContent())->toBe('test-token_1.fingerprint');
});

it('returns 404 when the token is not present in cache', function (): void {
    Cache::forget('acme-challenge:missing-token');

    $this->get('/.well-known/acme-challenge/missing-token')
        ->assertStatus(404);
});

it('returns 404 for tokens outside the base64url alphabet', function (): void {
    Cache::put('acme-challenge:bad.token', 'should-not-be-served', 3600);

    $this->get('/.well-known/acme-challenge/bad.token')
        ->assertStatus(404);
});

it('registers a named route that can be cached', function (): void {
    $route = Route::getRoutes()->getByName('coyotecert.acme-challenge');

    expect($route)->not->toBeNull();

    $route->prepareForSerialization();

    expect(unserialize(serialize($route))->uri())->toBe('.well-known/acme-challenge/{token}');
});
