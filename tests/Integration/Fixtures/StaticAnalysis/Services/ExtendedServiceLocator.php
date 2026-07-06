<?php

namespace Neo4j\LaravelBoost\Tests\Integration\Fixtures\StaticAnalysis\Services;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\App;

abstract class ServiceLocatorHost
{
    protected Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }
}

final class ExtendedServiceLocator extends ServiceLocatorHost
{
    public function withAppVariable(Application $app): void
    {
        $app->make(PaymentGateway::class);
    }

    public function withThisApp(): void
    {
        $this->app->make(PaymentGateway::class);
    }

    public function withMakeWith(): void
    {
        App::makeWith(PaymentGateway::class, []);
    }
}
