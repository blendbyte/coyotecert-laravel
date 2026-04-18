<?php

namespace Tests;

use CoyoteCert\Laravel\CoyoteCertServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [CoyoteCertServiceProvider::class];
    }
}
