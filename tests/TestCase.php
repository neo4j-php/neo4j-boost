<?php

namespace Neo4j\LaravelBoost\Tests;

use Neo4j\LaravelBoost\Neo4jBoostServiceProvider;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use WithWorkbench;

    protected function getPackageProviders($app): array
    {
        return [
            Neo4jBoostServiceProvider::class,
        ];
    }

    /**
     * Required for HTTP / cookie tests once {@see WithWorkbench} registers web routes.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    }
}
