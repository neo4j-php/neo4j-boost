<?php

namespace Neo4j\LaravelBoost\Tests\Integration\ResolutionCatalog;

use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\View;
use Illuminate\View\Factory;
use Neo4j\LaravelBoost\ResolutionCatalog\ResolutionCatalog;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ResolutionCatalog\BillingReportsFacade;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ResolutionCatalog\BillingReportsService;
use Neo4j\LaravelBoost\Tests\TestCase;

class ResolutionCatalogIntegrationTest extends TestCase
{
    public function test_container_resolves_catalog_service(): void
    {
        $catalog = $this->app->make(ResolutionCatalog::class);

        $this->assertInstanceOf(ResolutionCatalog::class, $catalog);
        $this->assertNotEmpty($catalog->facadeEntries());
        $this->assertCount(9, $catalog->helperEntries());
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

        $entry = $catalog->resolveFacade(BillingReportsFacade::class);

        $this->assertNotNull($entry);
        $this->assertSame(BillingReportsService::class, $entry->abstract);
    }

    public function test_resolve_helper_returns_catalog_entry(): void
    {
        $catalog = $this->app->make(ResolutionCatalog::class);

        $entry = $catalog->resolveHelper('redirect');

        $this->assertNotNull($entry);
        $this->assertSame(Redirector::class, $entry->abstract);
        $this->assertSame('redirect', $entry->bindingKey);
    }
}
