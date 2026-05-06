<?php

namespace Neo4j\LaravelBoost\Tests;

use Orchestra\Testbench\Concerns\WithWorkbench;

abstract class WorkbenchTestCase extends TestCase
{
    use WithWorkbench;

    /**
     * Required for HTTP / cookie tests once {@see WithWorkbench} registers web routes.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    }
}
