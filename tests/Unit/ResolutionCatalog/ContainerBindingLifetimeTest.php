<?php

namespace Neo4j\LaravelBoost\Tests\Unit\ResolutionCatalog;

use Neo4j\LaravelBoost\ResolutionCatalog\ContainerBindingLifetime;
use Neo4j\LaravelBoost\Support\Graph\BindsToType;
use Neo4j\LaravelBoost\Tests\TestCase;
use stdClass;

class ContainerBindingLifetimeTest extends TestCase
{
    public function test_unbound_accessor_defaults_to_normal(): void
    {
        $lifetime = $this->app->make(ContainerBindingLifetime::class);

        $this->assertSame(BindsToType::Normal, $lifetime->forAccessor('app.unbound'));
    }

    public function test_bound_transient_accessor_is_normal(): void
    {
        $this->app->bind('app.transient', fn (): stdClass => new stdClass);

        $lifetime = $this->app->make(ContainerBindingLifetime::class);

        $this->assertSame(BindsToType::Normal, $lifetime->forAccessor('app.transient'));
    }

    public function test_bound_singleton_accessor_is_singleton(): void
    {
        $this->app->singleton('app.shared', fn (): stdClass => new stdClass);

        $lifetime = $this->app->make(ContainerBindingLifetime::class);

        $this->assertSame(BindsToType::Singleton, $lifetime->forAccessor('app.shared'));
    }
}
