<?php

declare(strict_types=1);

namespace Tests\Feature;

use CoyoteCert\Laravel\Challenge\CacheHttp01Handler;
use CoyoteCert\Laravel\CoyoteCertManager;
use CoyoteCert\Laravel\CoyoteCertServiceProvider;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Router;

it('binds CoyoteCertManager as a singleton in the container', function (): void {
    $first  = $this->app->make(CoyoteCertManager::class);
    $second = $this->app->make(CoyoteCertManager::class);

    expect($first)->toBeInstanceOf(CoyoteCertManager::class);
    expect($first)->toBe($second);
});

it('binds CacheHttp01Handler as a singleton in the container', function (): void {
    $first  = $this->app->make(CacheHttp01Handler::class);
    $second = $this->app->make(CacheHttp01Handler::class);

    expect($first)->toBeInstanceOf(CacheHttp01Handler::class);
    expect($first)->toBe($second);
});

it('merges the coyotecert config into the application config', function (): void {
    expect(config('coyotecert'))->toBeArray()
        ->toHaveKeys(['provider', 'renewal_days', 'profile', 'preferred_chain', 'providers.sslcom', 'providers.custom.eab_kid'])
        ->not->toHaveKey('providers.buypass');
    expect(config('coyotecert.challenge'))->toBe('http-01');
    expect(config('coyotecert.storage'))->toBe('database');
    expect(config('coyotecert.key_type'))->toBe('EC_P256');
    expect(config('coyotecert.renewal_days'))->toBeNull();
});

it('schedules cert:renew daily on one server without overlapping', function (): void {
    $events = array_values(array_filter(
        $this->app->make(Schedule::class)->events(),
        fn(ScheduledEvent $event): bool => str_contains((string) $event->command, 'cert:renew'),
    ));

    expect($events)->toHaveCount(1)
        ->and($events[0]->expression)->toBe('0 0 * * *')
        ->and($events[0]->withoutOverlapping)->toBeTrue()
        ->and($events[0]->onOneServer)->toBeTrue();
});

it('does not schedule cert:renew when scheduling is disabled', function (): void {
    config(['coyotecert.schedule' => false]);

    $schedule = new Schedule();
    $this->app->instance(Schedule::class, $schedule);

    (new CoyoteCertServiceProvider($this->app))->boot();
    $this->app->make(Schedule::class);

    expect($schedule->events())->toBeEmpty();
});

it('registers the challenge route only when routes are not cached', function (bool $cached, bool $registered): void {
    $router = new Router($this->app->make('events'), $this->app);
    $this->app->instance(Router::class, $router);
    $this->app->instance('routes.cached', $cached);

    (new CoyoteCertServiceProvider($this->app))->boot();
    $router->getRoutes()->refreshNameLookups();

    expect($router->getRoutes()->getByName('coyotecert.acme-challenge') !== null)->toBe($registered);
})->with([
    'not cached' => [false, true],
    'cached'     => [true, false],
]);
