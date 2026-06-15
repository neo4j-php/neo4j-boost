<?php

namespace Neo4j\LaravelBoost\Tests\Unit\StaticAnalysis\Fixtures;

use Illuminate\Support\Facades\App;

final class DynamicServiceLocator
{
    public function run(string $abstract): void
    {
        app($abstract);
        resolve($abstract);
        App::make($abstract);
    }
}
