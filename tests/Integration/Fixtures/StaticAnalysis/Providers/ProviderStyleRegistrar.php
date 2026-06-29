<?php

namespace Neo4j\LaravelBoost\Tests\Integration\Fixtures\StaticAnalysis\Providers;

use Neo4j\LaravelBoost\Tests\Integration\Fixtures\StaticAnalysis\Services\PaymentGateway;

final class ProviderStyleRegistrar
{
    public function register($app): void
    {
        $app->make(PaymentGateway::class);
    }
}
