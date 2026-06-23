<?php

namespace Neo4j\LaravelBoost\Tests\Integration\Fixtures\StaticAnalysis\Services;

use Illuminate\Support\Facades\App;

final class DynamicLocatorProcessor
{
    public function run(string $abstract): void
    {
        app($abstract);
        resolve($abstract);
        App::make($abstract);
    }
}
