<?php

namespace Neo4j\LaravelBoost\Tests\Integration;

use Neo4j\LaravelBoost\ContainerGraphWriter;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\StaticAnalysis\Services\GlobalHelperWorker;
use Neo4j\LaravelBoost\Tests\Integration\Support\RecordingContainerGraphWriter;
use Neo4j\LaravelBoost\Tests\TestCase;

class ContainerGraphGlobalHelperStaticAnalysisTest extends TestCase
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

    public function test_container_graph_exports_global_helper_edges_from_static_scan(): void
    {
        $this->artisan('container:graph')
            ->expectsOutputToContain('Static global_helper edges: 5')
            ->expectsOutputToContain('Container graph written to Neo4j successfully.')
            ->assertExitCode(0);

        $cacheEdge = $this->findGlobalHelperEdge('cache');
        $this->assertNotNull($cacheEdge);
        $this->assertSame('global_helper', $cacheEdge['access']);
        $this->assertSame('global_helper', $cacheEdge['depends_on_type'] ?? null);
        $this->assertSame('cache', $cacheEdge['helper'] ?? null);
        $this->assertSame('high', $cacheEdge['confidence'] ?? null);
        $this->assertSame(GlobalHelperWorker::class, $cacheEdge['instance']);
        $this->assertStringContainsString('GlobalHelperWorker.php', $cacheEdge['file']);

        $configEdge = $this->findGlobalHelperEdge('config');
        $this->assertNotNull($configEdge);
        $this->assertSame('low', $configEdge['confidence'] ?? null);
        $this->assertSame('config.app.name', $configEdge['identifier']);
    }

    public function test_static_scan_paths_can_be_disabled(): void
    {
        config(['neo4j-boost.container_graph.static_scan_paths' => []]);

        $this->artisan('container:graph')
            ->expectsOutputToContain('Static global_helper edges: 0')
            ->assertExitCode(0);

        $this->assertNull($this->findGlobalHelperEdge('cache'));
    }

    /**
     * @return null|array{instance: string, dependency_key: string, access: string, identifier: string, identifier_kind: string, lifetime: string, via: string, file: string, line: int, depends_on_type?: string, helper?: string, confidence?: string}
     */
    private function findGlobalHelperEdge(string $helper): ?array
    {
        foreach ($this->graph->dependencyChainRows as $row) {
            if (($row['access'] ?? null) === 'global_helper' && ($row['helper'] ?? $row['via'] ?? null) === $helper) {
                return $row;
            }
        }

        return null;
    }
}
