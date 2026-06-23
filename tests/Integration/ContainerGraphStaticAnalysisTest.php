<?php

namespace Neo4j\LaravelBoost\Tests\Integration;

use Neo4j\LaravelBoost\ContainerGraphWriter;
use Neo4j\LaravelBoost\StaticAnalysis\ServiceLocationCallDetector;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\StaticAnalysis\Services\DynamicLocatorProcessor;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\StaticAnalysis\Services\ExtendedServiceLocator;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\StaticAnalysis\Services\OrderProcessor;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\StaticAnalysis\Services\PaymentGateway;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\StaticAnalysis\Providers\ProviderStyleRegistrar;
use Neo4j\LaravelBoost\Tests\Integration\Support\RecordingContainerGraphWriter;
use Neo4j\LaravelBoost\Tests\TestCase;

class ContainerGraphStaticAnalysisTest extends TestCase
{
    private RecordingContainerGraphWriter $graph;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'neo4j-boost.container_graph.static_scan_paths' => [
                dirname(__DIR__).'/Integration/Fixtures/StaticAnalysis/Services',
            ],
        ]);

        $this->graph = new RecordingContainerGraphWriter;
        $this->app->instance(ContainerGraphWriter::class, $this->graph);
    }

    public function test_container_graph_exports_service_location_edges_from_static_scan(): void
    {
        $this->artisan('container:graph')
            ->expectsOutputToContain('Static service_location edges: 9')
            ->expectsOutputToContain('Container graph written to Neo4j successfully.')
            ->assertExitCode(0);

        $edge = $this->graph->findDependencyChainRow(OrderProcessor::class, PaymentGateway::class);
        $this->assertNotNull($edge);
        $this->assertSame('service_location', $edge['access']);
        $this->assertContains($edge['via'], ['app', 'resolve', 'App::make']);
        $this->assertStringContainsString('OrderProcessor.php', $edge['file']);
        $this->assertGreaterThan(0, $edge['line']);

        $extended = $this->graph->findDependencyChainRow(ExtendedServiceLocator::class, PaymentGateway::class);
        $this->assertNotNull($extended);
        $this->assertContains($extended['via'], ['$app->make', '$this->app->make', 'App::makeWith']);
    }

    public function test_container_graph_exports_dynamic_service_location_as_unresolved(): void
    {
        $this->artisan('container:graph')
            ->assertExitCode(0);

        $dynamicEdges = array_values(array_filter(
            $this->graph->dependencyChainRows,
            static fn (array $row): bool => $row['instance'] === DynamicLocatorProcessor::class
                && $row['identifier'] === ServiceLocationCallDetector::UNRESOLVED_IDENTIFIER,
        ));

        $this->assertCount(3, $dynamicEdges);

        foreach ($dynamicEdges as $edge) {
            $this->assertSame('service_location', $edge['access']);
            $this->assertSame('Unresolved', $edge['identifier_kind']);
            $this->assertSame(ServiceLocationCallDetector::UNRESOLVED_REASON, $edge['reason'] ?? null);
            $this->assertContains($edge['via'], ['app', 'resolve', 'App::make']);
            $this->assertStringContainsString('DynamicLocatorProcessor.php', $edge['file']);
            $this->assertGreaterThan(0, $edge['line']);
        }
    }

    public function test_provider_scan_paths_are_merged_with_static_scan_paths(): void
    {
        config([
            'neo4j-boost.container_graph.static_scan_paths' => [],
            'neo4j-boost.container_graph.static_scan_provider_paths' => [
                dirname(__DIR__).'/Integration/Fixtures/StaticAnalysis/Providers',
            ],
        ]);

        $this->artisan('container:graph')
            ->expectsOutputToContain('Static service_location edges: 1')
            ->assertExitCode(0);

        $edge = $this->graph->findDependencyChainRow(ProviderStyleRegistrar::class, PaymentGateway::class);
        $this->assertNotNull($edge);
        $this->assertSame('$app->make', $edge['via']);
    }

    public function test_static_scan_paths_can_be_disabled(): void
    {
        config([
            'neo4j-boost.container_graph.static_scan_paths' => [],
            'neo4j-boost.container_graph.static_scan_provider_paths' => [],
        ]);

        $this->artisan('container:graph')
            ->expectsOutputToContain('Static service_location edges: 0')
            ->assertExitCode(0);

        $this->assertNull($this->graph->findDependencyChainRow(OrderProcessor::class, PaymentGateway::class));
    }
}
