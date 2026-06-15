<?php

namespace Neo4j\LaravelBoost\Tests\Integration;

use Neo4j\LaravelBoost\ContainerGraphWriter;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\StaticAnalysis\Services\OrderProcessor;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\StaticAnalysis\Services\PaymentGateway;
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
                dirname(__DIR__).'/Integration/Fixtures/StaticAnalysis',
            ],
        ]);

        $this->graph = new RecordingContainerGraphWriter;
        $this->app->instance(ContainerGraphWriter::class, $this->graph);
    }

    public function test_container_graph_exports_service_location_edges_from_static_scan(): void
    {
        $this->artisan('container:graph')
            ->expectsOutputToContain('Static service_location edges: 3')
            ->expectsOutputToContain('Container graph written to Neo4j successfully.')
            ->assertExitCode(0);

        $edge = $this->graph->findDependencyRow(OrderProcessor::class, PaymentGateway::class);
        $this->assertNotNull($edge);
        $this->assertSame('service_location', $edge['type']);
        $this->assertSame('static', $edge['source']);
        $this->assertContains($edge['via'], ['app', 'resolve', 'App::make']);
        $this->assertStringContainsString('OrderProcessor.php', $edge['file']);
        $this->assertGreaterThan(0, $edge['line']);
    }

    public function test_static_scan_paths_can_be_disabled(): void
    {
        config(['neo4j-boost.container_graph.static_scan_paths' => []]);

        $this->artisan('container:graph')
            ->expectsOutputToContain('Static service_location edges: 0')
            ->assertExitCode(0);

        $this->assertNull($this->graph->findDependencyRow(OrderProcessor::class, PaymentGateway::class));
    }
}
