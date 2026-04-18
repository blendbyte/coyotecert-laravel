<?php

namespace CoyoteCert\Laravel;

use CoyoteCert\Laravel\Commands\IssueCertCommand;
use CoyoteCert\Laravel\Commands\RenewCertCommand;
use CoyoteCert\Laravel\Commands\RevokeCertCommand;
use CoyoteCert\Laravel\Commands\StatusCertCommand;
use Illuminate\Support\ServiceProvider;

class CoyoteCertServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                IssueCertCommand::class,
                RenewCertCommand::class,
                StatusCertCommand::class,
                RevokeCertCommand::class,
            ]);
        }
    }
}
