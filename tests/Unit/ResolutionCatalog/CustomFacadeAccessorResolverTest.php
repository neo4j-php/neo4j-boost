<?php

namespace Neo4j\LaravelBoost\Tests\Unit\ResolutionCatalog;

use Neo4j\LaravelBoost\ResolutionCatalog\CustomFacadeAccessorResolver;
use Neo4j\LaravelBoost\ResolutionCatalog\ResolutionCatalogSource;
use Neo4j\LaravelBoost\Support\Graph\BindsToType;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ResolutionCatalog\CustomAccessorService;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ResolutionCatalog\CustomClassAccessorFacade;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ResolutionCatalog\InvalidObjectAccessorFacade;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ResolutionCatalog\StringAliasFacade;
use Neo4j\LaravelBoost\Tests\TestCase;

class CustomFacadeAccessorResolverTest extends TestCase
{
    public function test_resolves_custom_facade_class_accessor(): void
    {
        $this->app->singleton(CustomAccessorService::class, fn (): CustomAccessorService => new CustomAccessorService);

        $entry = $this->app->make(CustomFacadeAccessorResolver::class)->resolve(CustomClassAccessorFacade::class);

        $this->assertNotNull($entry);
        $this->assertSame(CustomAccessorService::class, $entry->abstract);
        $this->assertSame(ResolutionCatalogSource::AutoDiscoveredFacade, $entry->source);
        $this->assertSame(BindsToType::Singleton, $entry->bindsToType);
    }

    public function test_resolves_string_binding_accessor(): void
    {
        $entry = $this->app->make(CustomFacadeAccessorResolver::class)->resolve(StringAliasFacade::class);

        $this->assertNotNull($entry);
        $this->assertSame('app.legacy', $entry->abstract);
        $this->assertSame('app.legacy', $entry->bindingKey);
        $this->assertSame(BindsToType::Normal, $entry->bindsToType);
    }

    public function test_uses_container_lifetime_for_string_binding_accessor(): void
    {
        $this->app->singleton('app.legacy', fn (): CustomAccessorService => new CustomAccessorService);

        $entry = $this->app->make(CustomFacadeAccessorResolver::class)->resolve(StringAliasFacade::class);

        $this->assertNotNull($entry);
        $this->assertSame(BindsToType::Singleton, $entry->bindsToType);
    }

    public function test_returns_null_when_accessor_is_not_a_string(): void
    {
        $entry = $this->app->make(CustomFacadeAccessorResolver::class)->resolve(InvalidObjectAccessorFacade::class);

        $this->assertNull($entry);
    }
}
