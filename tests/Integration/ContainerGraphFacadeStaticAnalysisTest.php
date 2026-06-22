<?php

namespace Neo4j\LaravelBoost\Tests\Integration;

use Neo4j\LaravelBoost\ContainerGraphWriter;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ResolutionCatalog\CustomAccessorService;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ResolutionCatalog\CustomClassAccessorFacade;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\StaticAnalysis\Services\InvoiceNotifier;
use Neo4j\LaravelBoost\Tests\Integration\Support\RecordingContainerGraphWriter;
use Neo4j\LaravelBoost\Tests\TestCase;

class ContainerGraphFacadeStaticAnalysisTest extends TestCase
{
    private RecordingContainerGraphWriter $graph;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->singleton(CustomAccessorService::class);

        config([
            'neo4j-boost.container_graph.static_scan_paths' => [
                dirname(__DIR__).'/Integration/Fixtures/StaticAnalysis',
            ],
        ]);

        $this->graph = new RecordingContainerGraphWriter;
        $this->app->instance(ContainerGraphWriter::class, $this->graph);
    }

    public function test_container_graph_exports_facade_edges_from_static_scan(): void
    {
        $this->artisan('container:graph')
            ->expectsOutputToContain('Static facade edges: 2')
            ->expectsOutputToContain('Container graph written to Neo4j successfully.')
            ->assertExitCode(0);

        $cacheEdge = $this->findFacadeEdge('Illuminate\\Support\\Facades\\Cache::put');
        $this->assertNotNull($cacheEdge);
        $this->assertSame('facade', $cacheEdge['access']);
        $this->assertStringContainsString('InvoiceNotifier.php', $cacheEdge['file']);
        $this->assertGreaterThan(0, $cacheEdge['line']);

        $customEdge = $this->findFacadeEdge(CustomClassAccessorFacade::class.'::handle');
        $this->assertNotNull($customEdge);
        $this->assertSame('facade', $customEdge['access']);
        $this->assertSame(CustomAccessorService::class, $customEdge['identifier']);
    }

    /**
     * @return null|array{instance: string, dependency_key: string, access: string, identifier: string, identifier_kind: string, lifetime: string, via: string, file: string, line: int}
     */
    private function findFacadeEdge(string $via): ?array
    {
        foreach ($this->graph->dependencyChainRows as $row) {
            if (($row['access'] ?? null) === 'facade' && ($row['via'] ?? null) === $via) {
                return $row;
            }
        }

        return null;
    }

    public function test_static_scan_paths_can_be_disabled(): void
    {
        config(['neo4j-boost.container_graph.static_scan_paths' => []]);

        $this->artisan('container:graph')
            ->expectsOutputToContain('Static facade edges: 0')
            ->assertExitCode(0);

        $this->assertNull($this->graph->findDependencyChainRow(
            InvoiceNotifier::class,
            'Illuminate\\Cache\\CacheManager',
        ));
    }
}
