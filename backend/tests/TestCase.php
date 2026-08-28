<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * The Docker container sets DB_DATABASE=memecoin as a hard environment
     * variable, which shadows phpunit.xml's <env> overrides. Force the dedicated
     * test database on the freshly-booted app, before RefreshDatabase runs, so
     * the suite never touches the development database.
     */
    public function createApplication()
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        if ($app->environment('testing')) {
            $app['config']->set('database.default', 'pgsql');
            $app['config']->set('database.connections.pgsql.database', 'memecoin_test');
            $app['db']->purge('pgsql');
        }

        return $app;
    }
}
