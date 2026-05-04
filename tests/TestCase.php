<?php

namespace Neo4j\LaravelBoost\Tests;

use Neo4j\LaravelBoost\Neo4jBoostServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            Neo4jBoostServiceProvider::class,
        ];
    }
}
