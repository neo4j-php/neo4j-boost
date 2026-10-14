<?php

namespace Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures\Providers;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;

final class ReportsServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public function register(): void
    {
        $this->app->singleton('reports.exporter', fn (): ArrayLedger => new ArrayLedger);
    }

    /**
     * @return list<string>
     */
    public function provides(): array
    {
        return ['reports.exporter', 'reports.cache'];
    }
}
