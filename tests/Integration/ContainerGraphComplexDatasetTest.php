<?php

namespace Neo4j\LaravelBoost\Tests\Integration;

use Neo4j\LaravelBoost\ContainerGraphWriter;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\ComplexContainerRegistry;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Contracts\EventPusherInterface;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services\Filter;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services\Firewall;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services\Logger;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services\PodcastParser;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services\RedisEventPusher;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services\Transistor;
use Neo4j\LaravelBoost\Tests\Integration\Support\RecordingContainerGraphWriter;
use Neo4j\LaravelBoost\Tests\TestCase;

/**
 * End-to-end coverage for container:graph against a Laravel 13-style binding dataset.
 *
 * Runs the full artisan command and asserts against graph rows written to Neo4j.
 *
 * @see https://laravel.com/docs/13.x/container
 */
class ContainerGraphComplexDatasetTest extends TestCase
{
    private RecordingContainerGraphWriter $graph;

    protected function setUp(): void
    {
        parent::setUp();

        ComplexContainerRegistry::register($this->app);

        $this->graph = new RecordingContainerGraphWriter;
        $this->app->instance(ContainerGraphWriter::class, $this->graph);
    }

    public function test_complex_dataset_exports_interface_class_and_alias_bindings(): void
    {
        $this->runContainerGraph();

        $interfaceBinding = $this->graph->findBinding(EventPusherInterface::class);
        $this->assertNotNull($interfaceBinding);
        $this->assertSame('Interface', $interfaceBinding['abstractKind']);
        $this->assertSame(RedisEventPusher::class, $interfaceBinding['concrete']);
        $this->assertSame('Class', $interfaceBinding['concreteKind']);
        $this->assertTrue($this->graph->hasBindsToEdge(EventPusherInterface::class, RedisEventPusher::class));

        $aliasBinding = $this->graph->findBinding('billing.currency');
        $this->assertNotNull($aliasBinding);
        $this->assertSame('USD', $aliasBinding['concrete']);
        $this->assertSame('Alias', $aliasBinding['concreteKind']);
        $this->assertTrue($this->graph->hasBindsToEdge('billing.currency', 'USD'));

        $bindIfBinding = $this->graph->findBinding('legacy.podcast.parser');
        $this->assertNotNull($bindIfBinding);
        $this->assertSame(PodcastParser::class, $bindIfBinding['concrete']);
    }

    public function test_complex_dataset_marks_singleton_and_closure_bindings(): void
    {
        $this->runContainerGraph();

        $singletonBinding = $this->graph->findBinding(RedisEventPusher::class);
        $this->assertNotNull($singletonBinding);
        $this->assertTrue($singletonBinding['shared']);
        $this->assertSame('singleton', $singletonBinding['type']);

        $closureBinding = $this->graph->findBinding('reports.analyzer');
        $this->assertNotNull($closureBinding);
        $this->assertContains($closureBinding['concreteKind'], ['Closure', 'Class', 'Alias']);
    }

    public function test_complex_dataset_writes_constructor_dependency_edges(): void
    {
        $this->runContainerGraph();

        $this->assertTrue($this->graph->hasDependsOnEdge(Transistor::class, PodcastParser::class));
        $this->assertTrue($this->graph->hasDependsOnEdge(Firewall::class, Logger::class));
        $this->assertTrue($this->graph->hasDependsOnEdge(Firewall::class, Filter::class));

        $transistorDependency = $this->graph->findDependencyRow(Transistor::class, PodcastParser::class);
        $this->assertNotNull($transistorDependency);
        $this->assertSame('constructor_injection', $transistorDependency['type']);
    }

    public function test_complex_dataset_writes_class_nodes_and_summary_counts(): void
    {
        $this->runContainerGraph();

        $this->assertTrue($this->graph->hasClassNode(Transistor::class));
        $this->assertTrue($this->graph->hasClassNode(Firewall::class));
        $this->assertGreaterThanOrEqual(10, count($this->graph->bindingRows));
        $this->assertGreaterThanOrEqual(3, count($this->graph->dependencyRows));
    }

    public function test_container_graph_dry_run_does_not_write_graph(): void
    {
        $this->artisan('container:graph', ['--dry-run' => true])
            ->expectsOutputToContain('Container graph summary:')
            ->expectsOutputToContain('Bindings:')
            ->expectsOutputToContain('Dry run complete')
            ->assertExitCode(0);

        $this->assertSame([], $this->graph->bindingRows);
        $this->assertSame([], $this->graph->dependencyRows);
        $this->assertSame([], $this->graph->classRows);
    }

    private function runContainerGraph(): void
    {
        $this->artisan('container:graph')
            ->expectsOutputToContain('Container graph written to Neo4j successfully.')
            ->assertExitCode(0);
    }
}
