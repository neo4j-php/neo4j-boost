<?php

namespace Neo4j\LaravelBoost\Tests\Integration\Fixtures\StaticAnalysis\Services;

use Illuminate\Support\Facades\App;

final class OrderProcessor
{
    public function processWithApp(): void
    {
        app(PaymentGateway::class);
    }

    public function processWithResolve(): void
    {
        resolve(PaymentGateway::class);
    }

    public function processWithFacadeMake(): void
    {
        App::make(PaymentGateway::class);
    }

    public function skipDynamicLocator(string $abstract): void
    {
        app($abstract);
        resolve($abstract);
        App::make($abstract);
    }
}
