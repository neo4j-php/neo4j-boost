<?php

namespace Neo4j\LaravelBoost\Tests\Unit\ResolutionCatalog;

use Neo4j\LaravelBoost\ResolutionCatalog\RealTimeFacadeResolver;
use Neo4j\LaravelBoost\ResolutionCatalog\ResolutionCatalogSource;
use Neo4j\LaravelBoost\Support\Graph\BindsToType;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ResolutionCatalog\CustomAccessorService;
use Neo4j\LaravelBoost\Tests\TestCase;

class RealTimeFacadeResolverTest extends TestCase
{
    public function test_resolves_real_time_facade_to_underlying_class(): void
    {
        $entry = $this->app->make(RealTimeFacadeResolver::class)
            ->resolve('Facades\\'.CustomAccessorService::class);

        $this->assertNotNull($entry);
        $this->assertSame(CustomAccessorService::class, $entry->abstract);
        $this->assertSame('Facades\\'.CustomAccessorService::class, $entry->facadeClass);
        $this->assertSame(ResolutionCatalogSource::AutoDiscoveredFacade, $entry->source);
    }

    public function test_strips_leading_backslash_before_prefix_check(): void
    {
        $entry = $this->app->make(RealTimeFacadeResolver::class)
            ->resolve('\\Facades\\'.CustomAccessorService::class);

        $this->assertNotNull($entry);
        $this->assertSame(CustomAccessorService::class, $entry->abstract);
    }

    public function test_uses_container_lifetime_for_bound_underlying_class(): void
    {
        $this->app->singleton(CustomAccessorService::class, fn (): CustomAccessorService => new CustomAccessorService);

        $entry = $this->app->make(RealTimeFacadeResolver::class)
            ->resolve('Facades\\'.CustomAccessorService::class);

        $this->assertNotNull($entry);
        $this->assertSame(BindsToType::Singleton, $entry->bindsToType);
    }

    public function test_returns_null_for_non_real_time_facade(): void
    {
        $resolver = $this->app->make(RealTimeFacadeResolver::class);

        $this->assertNull($resolver->resolve(CustomAccessorService::class));
        $this->assertFalse($resolver->isRealTimeFacade(CustomAccessorService::class));
        $this->assertTrue($resolver->isRealTimeFacade('Facades\\'.CustomAccessorService::class));
    }
}
