<?php

namespace Neo4j\LaravelBoost\Tests\Integration\ResolutionCatalog;

use Illuminate\Support\Facades\View;
use Illuminate\View\Factory;
use Neo4j\LaravelBoost\ResolutionCatalog\ResolutionCatalog;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ResolutionCatalog\CustomAccessorService;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ResolutionCatalog\CustomClassAccessorFacade;
use Neo4j\LaravelBoost\Tests\TestCase;

class ResolutionCatalogIntegrationTest extends TestCase
{
    public function test_container_resolves_catalog_service(): void
    {
        $catalog = $this->app->make(ResolutionCatalog::class);

        $this->assertInstanceOf(ResolutionCatalog::class, $catalog);
        $this->assertNotEmpty($catalog->facadeEntries());
    }

    public function test_resolve_facade_prefers_first_party_catalog(): void
    {
        $catalog = $this->app->make(ResolutionCatalog::class);

        $entry = $catalog->resolveFacade(View::class);

        $this->assertNotNull($entry);
        $this->assertSame(Factory::class, $entry->abstract);
        $this->assertSame('view', $entry->bindingKey);
    }

    public function test_resolve_facade_falls_back_to_custom_accessor(): void
    {
        $catalog = $this->app->make(ResolutionCatalog::class);

        $entry = $catalog->resolveFacade(CustomClassAccessorFacade::class);

        $this->assertNotNull($entry);
        $this->assertSame(CustomAccessorService::class, $entry->abstract);
    }
}
