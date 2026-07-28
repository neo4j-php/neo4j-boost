<?php

namespace Neo4j\LaravelBoost\Tests\Unit\ResolutionCatalog;

use Illuminate\Cache\CacheManager;
use Neo4j\LaravelBoost\ResolutionCatalog\ContainerBindingAbstractResolver;
use Neo4j\LaravelBoost\Tests\TestCase;

class ContainerBindingAbstractResolverTest extends TestCase
{
    public function test_unbound_binding_key_returns_key(): void
    {
        $resolver = $this->app->make(ContainerBindingAbstractResolver::class);

        $this->assertSame('app.unbound', $resolver->resolveForBindingKey('app.unbound'));
    }

    public function test_bound_string_concrete_returns_class_name(): void
    {
        $this->app->bind('cache.example', CacheManager::class);

        $resolver = $this->app->make(ContainerBindingAbstractResolver::class);

        $this->assertSame(CacheManager::class, $resolver->resolveForBindingKey('cache.example'));
    }

    public function test_bound_closure_concrete_uses_return_type(): void
    {
        $this->app->bind('app.closure', fn (): CacheManager => $this->app->make(CacheManager::class));

        $resolver = $this->app->make(ContainerBindingAbstractResolver::class);

        $this->assertSame(CacheManager::class, $resolver->resolveForBindingKey('app.closure'));
    }

    public function test_bound_closure_without_return_type_resolves_via_container_make(): void
    {
        $this->app->bind('cache.example', fn (): CacheManager => $this->app->make(CacheManager::class));

        $resolver = $this->app->make(ContainerBindingAbstractResolver::class);

        $this->assertSame(CacheManager::class, $resolver->resolveForBindingKey('cache.example'));
    }
}
