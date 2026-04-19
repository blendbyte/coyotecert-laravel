<?php

declare(strict_types=1);

namespace CoyoteCert\Laravel;

use CoyoteCert\Laravel\Challenge\CacheHttp01Handler;
use CoyoteCert\Laravel\Commands\IssueCertCommand;
use CoyoteCert\Laravel\Commands\ListCertCommand;
use CoyoteCert\Laravel\Commands\RenewCertCommand;
use CoyoteCert\Laravel\Commands\RevokeCertCommand;
use CoyoteCert\Laravel\Commands\StatusCertCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
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
                $app->make(CacheRepository::class),
                $app->make(Dispatcher::class),
                $app->make(DatabaseManager::class),
                $app->make(LoggerInterface::class),
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
                    $schedule->command('cert:renew')->daily();
                });
            }
        }

        $this->registerChallengeRoute();
    }

    private function registerChallengeRoute(): void
    {
        /** @var Router $router */
        $router = $this->app->make(Router::class);

        $router->get('/.well-known/acme-challenge/{token}', function (string $token): \Illuminate\Http\Response {
            /** @var CacheHttp01Handler $handler */
            $handler = $this->app->make(CacheHttp01Handler::class);
            $content = $this->app->make(CacheRepository::class)->get("{$handler->prefix}:{$token}");

            if ($content === null) {
                abort(404);
            }

            return response((string) $content, 200, ['Content-Type' => 'text/plain']);
        });
    }
}
