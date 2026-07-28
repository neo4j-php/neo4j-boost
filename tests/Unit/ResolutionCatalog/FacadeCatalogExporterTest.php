<?php

namespace Neo4j\LaravelBoost\Tests\Unit\ResolutionCatalog;

use Illuminate\Cache\CacheManager;
use Illuminate\Support\Facades\Cache;
use Neo4j\LaravelBoost\ResolutionCatalog\FacadeCatalogExporter;
use Neo4j\LaravelBoost\ResolutionCatalog\ResolutionCatalogSource;
use Neo4j\LaravelBoost\Support\Graph\BindsToType;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ResolutionCatalog\CustomAccessorService;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ResolutionCatalog\CustomClassAccessorFacade;
use Neo4j\LaravelBoost\Tests\TestCase;

class FacadeCatalogExporterTest extends TestCase
{
    public function test_exports_first_party_facade_rows(): void
    {
        $rows = $this->app->make(FacadeCatalogExporter::class)->rowsForAppClasses([]);

        $cache = collect($rows)->firstWhere('facade_class', Cache::class);

        $this->assertNotNull($cache);
        $this->assertSame(CacheManager::class, $cache['abstract']);
        $this->assertSame('cache', $cache['binding_key']);
        $this->assertSame(ResolutionCatalogSource::LaravelFacade->value, $cache['source']);
        $this->assertSame(BindsToType::Singleton->value, $cache['binds_to_type']);
    }

    public function test_exports_custom_app_facade_rows(): void
    {
        $this->app->singleton(CustomAccessorService::class, fn (): CustomAccessorService => new CustomAccessorService);

        $rows = $this->app->make(FacadeCatalogExporter::class)->rowsForAppClasses([
            CustomClassAccessorFacade::class,
        ]);

        $custom = collect($rows)->firstWhere('facade_class', CustomClassAccessorFacade::class);

        $this->assertNotNull($custom);
        $this->assertSame(CustomAccessorService::class, $custom['abstract']);
        $this->assertSame(ResolutionCatalogSource::AutoDiscoveredFacade->value, $custom['source']);
    }
}
