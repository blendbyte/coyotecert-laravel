<?php

declare(strict_types=1);

namespace CoyoteCert\Laravel;

use CoyoteCert\Laravel\Challenge\CacheHttp01Handler;
use CoyoteCert\Laravel\Commands\IssueCertCommand;
use CoyoteCert\Laravel\Commands\ListCertCommand;
use CoyoteCert\Laravel\Commands\RenewCertCommand;
use CoyoteCert\Laravel\Commands\RevokeCertCommand;
use CoyoteCert\Laravel\Commands\StatusCertCommand;
use CoyoteCert\Laravel\Http\AcmeChallengeController;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

final class CoyoteCertServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/coyotecert.php', 'coyotecert');

        $this->app->singleton(CacheHttp01Handler::class, function ($app): CacheHttp01Handler {
            return new CacheHttp01Handler($app->make(CacheRepository::class));
        });

        $this->app->singleton(CoyoteCertManager::class, function ($app): CoyoteCertManager {
            return new CoyoteCertManager(
                $app->make(ConfigRepository::class),
                $app->make(Dispatcher::class),
                $app->make(ConnectionResolverInterface::class),
                $app->make(LoggerInterface::class),
                $app,
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/coyotecert.php' => config_path('coyotecert.php'),
            ], 'coyotecert-config');

            $this->publishes([
                __DIR__ . '/../database/migrations/' => database_path('migrations'),
            ], 'coyotecert-migrations');

            $this->commands([
                IssueCertCommand::class,
                RenewCertCommand::class,
                StatusCertCommand::class,
                RevokeCertCommand::class,
                ListCertCommand::class,
            ]);

            if ((bool) $this->app->make(ConfigRepository::class)->get('coyotecert.schedule', true)) {
                $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                    // Renewing the same certificate from two servers at once wastes CA rate limits
                    $schedule->command('cert:renew')->daily()->withoutOverlapping()->onOneServer();
                });
            }
        }

        $this->registerChallengeRoute();
    }

    private function registerChallengeRoute(): void
    {
        // A cached route file already contains the route
        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }

        /** @var Router $router */
        $router = $this->app->make(Router::class);

        // A controller rather than a closure, so `php artisan route:cache` can serialize it
        $router->get('/.well-known/acme-challenge/{token}', AcmeChallengeController::class)
            ->where('token', '[A-Za-z0-9_-]+')
            ->name('coyotecert.acme-challenge');
    }
}
