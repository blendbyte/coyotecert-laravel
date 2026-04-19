<?php

declare(strict_types=1);

namespace Tests\Feature;

use CoyoteCert\Laravel\CoyoteCertManager;

it('binds CoyoteCertManager as a singleton in the container', function (): void {
    $first  = $this->app->make(CoyoteCertManager::class);
    $second = $this->app->make(CoyoteCertManager::class);

    expect($first)->toBeInstanceOf(CoyoteCertManager::class);
    expect($first)->toBe($second);
});

it('merges the coyotecert config into the application config', function (): void {
    expect(config('coyotecert'))->toBeArray();
    expect(config('coyotecert'))->toHaveKey('provider');
    expect(config('coyotecert.challenge'))->not->toBeNull();
    expect(config('coyotecert.storage'))->not->toBeNull();
    expect(config('coyotecert.key_type'))->not->toBeNull();
    expect(config('coyotecert.renewal_days'))->toBeInt();
});
