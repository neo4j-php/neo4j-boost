<?php

namespace Neo4j\LaravelBoost\Tests\Unit\ResolutionCatalog;

use Illuminate\Events\Dispatcher;
use Neo4j\LaravelBoost\ResolutionCatalog\GlobalHelperCatalog;
use Neo4j\LaravelBoost\ResolutionCatalog\ResolutionCatalogSource;
use Neo4j\LaravelBoost\Support\Graph\BindsToType;
use Neo4j\LaravelBoost\Tests\TestCase;
use Psr\Log\LoggerInterface;

class GlobalHelperCatalogTest extends TestCase
{
    public function test_top_helpers_are_all_catalogued(): void
    {
        $indexed = (new GlobalHelperCatalog)->indexedByHelperName();

        foreach (GlobalHelperCatalog::TOP_HELPERS as $helper) {
            $this->assertArrayHasKey($helper, $indexed, "Missing helper catalog entry: {$helper}");
        }
    }

    public function test_event_and_dispatch_share_dispatcher_contract(): void
    {
        $indexed = (new GlobalHelperCatalog)->indexedByHelperName();

        $this->assertSame(
            $indexed['event']->abstract,
            $indexed['dispatch']->abstract,
        );
        $this->assertSame(Dispatcher::class, $indexed['event']->abstract);
        $this->assertSame('events', $indexed['event']->bindingKey);
        $this->assertSame(BindsToType::Singleton, $indexed['event']->bindsToType);
        $this->assertSame(ResolutionCatalogSource::GlobalHelper, $indexed['logger']->source);
    }

    public function test_logger_maps_to_psr_logger_interface(): void
    {
        $entry = (new GlobalHelperCatalog)->indexedByHelperName()['logger'];

        $this->assertSame(LoggerInterface::class, $entry->abstract);
        $this->assertSame('log', $entry->bindingKey);
    }
}
