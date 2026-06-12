<?php

namespace Neo4j\LaravelBoost\Tests\Unit\ResolutionCatalog;

use Illuminate\Support\Facades\Cache;
use Neo4j\LaravelBoost\ResolutionCatalog\CustomFacadeAccessorResolver;
use Neo4j\LaravelBoost\ResolutionCatalog\ResolutionCatalogSource;
use Neo4j\LaravelBoost\Support\Graph\BindsToType;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ResolutionCatalog\BillingReportsFacade;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ResolutionCatalog\BillingReportsService;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ResolutionCatalog\LegacyAliasFacade;
use Neo4j\LaravelBoost\Tests\TestCase;

class CustomFacadeAccessorResolverTest extends TestCase
{
    public function test_resolves_custom_facade_class_accessor(): void
    {
        $entry = (new CustomFacadeAccessorResolver)->resolve(BillingReportsFacade::class);

        $this->assertNotNull($entry);
        $this->assertSame(BillingReportsService::class, $entry->abstract);
        $this->assertSame(ResolutionCatalogSource::CustomFacade, $entry->source);
        $this->assertSame(BindsToType::Singleton, $entry->bindsToType);
    }

    public function test_resolves_string_binding_accessor(): void
    {
        $entry = (new CustomFacadeAccessorResolver)->resolve(LegacyAliasFacade::class);

        $this->assertNotNull($entry);
        $this->assertSame('billing.legacy', $entry->abstract);
        $this->assertSame('billing.legacy', $entry->bindingKey);
        $this->assertSame(BindsToType::Normal, $entry->bindsToType);
    }

    public function test_skips_first_party_laravel_facades(): void
    {
        $entry = (new CustomFacadeAccessorResolver)->resolve(Cache::class);

        $this->assertNull($entry);
    }
}
