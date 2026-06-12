<?php

namespace Neo4j\LaravelBoost\Tests\Unit\ResolutionCatalog;

use Illuminate\Cache\CacheManager;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Neo4j\LaravelBoost\ResolutionCatalog\LaravelFirstPartyFacadeCatalog;
use Neo4j\LaravelBoost\ResolutionCatalog\ResolutionCatalogSource;
use Neo4j\LaravelBoost\Support\Graph\BindsToType;
use Neo4j\LaravelBoost\Tests\TestCase;
use ReflectionClass;

class LaravelFirstPartyFacadeCatalogTest extends TestCase
{
    public function test_catalog_covers_every_framework_facade_class(): void
    {
        $catalog = new LaravelFirstPartyFacadeCatalog;
        $catalogClasses = $catalog->facadeClasses();

        $facadeDir = dirname((new ReflectionClass(Cache::class))->getFileName());
        $frameworkFacades = [];
        foreach (glob($facadeDir.'/*.php') as $file) {
            $short = basename($file, '.php');
            if ($short === 'Facade') {
                continue;
            }

            $frameworkFacades[] = 'Illuminate\\Support\\Facades\\'.$short;
        }

        sort($frameworkFacades);
        sort($catalogClasses);

        $this->assertSame($frameworkFacades, $catalogClasses);
    }

    public function test_cache_facade_maps_to_cache_binding_with_singleton_type(): void
    {
        $entry = (new LaravelFirstPartyFacadeCatalog)->indexedByFacadeClass()[Cache::class];

        $this->assertSame(CacheManager::class, $entry->abstract);
        $this->assertSame('cache', $entry->bindingKey);
        $this->assertSame(BindsToType::Singleton, $entry->bindsToType);
        $this->assertSame(ResolutionCatalogSource::LaravelFacade, $entry->source);
    }

    public function test_route_facade_accessor_matches_framework_definition(): void
    {
        $entry = (new LaravelFirstPartyFacadeCatalog)->indexedByFacadeClass()[Route::class];
        $accessor = (new ReflectionClass(Route::class))->getMethod('getFacadeAccessor')->invoke(null);

        $this->assertSame('router', $accessor);
        $this->assertSame('router', $entry->bindingKey);
        $this->assertSame(Router::class, $entry->abstract);
    }
}
