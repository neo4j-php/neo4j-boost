<?php

namespace Neo4j\LaravelBoost\StaticAnalysis\PhpStan;

use Neo4j\LaravelBoost\ResolutionCatalog\ContainerBindingAbstractResolver;
use Neo4j\LaravelBoost\ResolutionCatalog\ContainerBindingLifetime;
use Neo4j\LaravelBoost\ResolutionCatalog\CustomFacadeAccessorResolver;
use Neo4j\LaravelBoost\ResolutionCatalog\FacadeAccessorParser;
use Neo4j\LaravelBoost\ResolutionCatalog\LaravelFirstPartyFacadeCatalog;
use Neo4j\LaravelBoost\ResolutionCatalog\RealTimeFacadeResolver;
use Neo4j\LaravelBoost\ResolutionCatalog\ResolutionCatalog;
use Neo4j\LaravelBoost\StaticAnalysis\FacadeNodeResolver;

/**
 * Builds the FacadeNodeResolver for PHPStan from the Larastan-booted Laravel
 * container. Falls back to a hand-wired catalog when the package service
 * provider is not registered in the analysed application (SOFT-56).
 */
final class FacadeNodeResolverFactory
{
    public static function create(): FacadeNodeResolver
    {
        return new FacadeNodeResolver(self::catalog());
    }

    private static function catalog(): ResolutionCatalog
    {
        $app = app();

        if ($app->bound(ResolutionCatalog::class)) {
            return $app->make(ResolutionCatalog::class);
        }

        $accessorParser = new FacadeAccessorParser;
        $abstractResolver = new ContainerBindingAbstractResolver($app);
        $lifetime = new ContainerBindingLifetime($app);

        return new ResolutionCatalog(
            new LaravelFirstPartyFacadeCatalog($accessorParser, $abstractResolver, $lifetime),
            new CustomFacadeAccessorResolver($accessorParser, $abstractResolver, $lifetime),
            new RealTimeFacadeResolver($lifetime),
        );
    }
}
