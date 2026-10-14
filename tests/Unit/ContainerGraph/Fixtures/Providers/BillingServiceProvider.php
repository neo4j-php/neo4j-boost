<?php

namespace Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures\Providers;

use Illuminate\Support\ServiceProvider;

class BillingServiceProvider extends ServiceProvider
{
    /** @var array<string, string> */
    public $bindings = [
        'billing.gateway' => ArrayLedger::class,
    ];

    /** @var array<int|string, string> */
    public $singletons = [
        Ledger::class => ArrayLedger::class,
        'billing.clock',
    ];

    #[\Override]
    public function register(): void
    {
        $this->app->singleton(Ledger::class, ArrayLedger::class);
        $this->app->scoped('billing.session', fn (): ArrayLedger => new ArrayLedger);
        app()->instance('billing.config', []);
        $this->app->bindIf('billing.unbound', ArrayLedger::class);

        $key = 'billing.dynamic';
        $this->app->bind($key, ArrayLedger::class);

        $this->registerReports();
    }

    private function registerReports(): void
    {
        $this->app->singleton('billing.reports', fn (): ArrayLedger => new ArrayLedger);
    }
}
