<?php

namespace Neo4j\LaravelBoost\Tests\Integration;

use Neo4j\LaravelBoost\ContainerGraphWriter;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\StaticAnalysis\Services\DirectInstantiator;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\StaticAnalysis\Services\InvoiceLineDto;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\StaticAnalysis\Services\PaymentGateway;
use Neo4j\LaravelBoost\Tests\Integration\Support\RecordingContainerGraphWriter;
use Neo4j\LaravelBoost\Tests\TestCase;

class ContainerGraphInstantiationStaticAnalysisTest extends TestCase
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

    public function test_container_graph_exports_instantiation_edges_from_static_scan(): void
    {
        $this->artisan('container:graph')
            ->expectsOutputToContain('Static instantiation edges: 2')
            ->expectsOutputToContain('Container graph written to Neo4j successfully.')
            ->assertExitCode(0);

        $serviceEdge = $this->graph->findDependencyChainRow(
            DirectInstantiator::class,
            PaymentGateway::class,
        );
        $this->assertNotNull($serviceEdge);
        $this->assertSame('di', $serviceEdge['access']);
        $this->assertSame('instantiation', $serviceEdge['injection_type'] ?? null);
        $this->assertSame('new '.PaymentGateway::class, $serviceEdge['via']);
        $this->assertStringContainsString('DirectInstantiator.php', $serviceEdge['file']);
        $this->assertGreaterThan(0, $serviceEdge['line']);

        $dtoEdge = $this->graph->findDependencyChainRow(
            DirectInstantiator::class,
            InvoiceLineDto::class,
        );
        $this->assertNotNull($dtoEdge);
        $this->assertSame('instantiation', $dtoEdge['injection_type'] ?? null);
    }

    public function test_static_scan_paths_can_be_disabled(): void
    {
        config(['neo4j-boost.container_graph.static_scan_paths' => []]);

        $this->artisan('container:graph')
            ->expectsOutputToContain('Static instantiation edges: 0')
            ->assertExitCode(0);

        $this->assertNull($this->graph->findDependencyChainRow(
            DirectInstantiator::class,
            PaymentGateway::class,
        ));
    }
}
