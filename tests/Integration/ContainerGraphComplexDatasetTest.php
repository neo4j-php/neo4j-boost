<?php

namespace Neo4j\LaravelBoost\Tests\Integration;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Neo4j\LaravelBoost\ContainerGraphWriter;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Commands\SyncReportsCommand;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\ComplexContainerRegistry;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Contracts\EventPusherInterface;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Controllers\PhotoController;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Controllers\PostController;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Controllers\VideoController;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Events\OrderShipped;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Http\Requests\StorePostRequest;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Jobs\ProcessInvoiceJob;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Listeners\OrderShippedListener;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Middleware\VerifyJsonApi;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services\Filter;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services\Firewall;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services\Logger;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services\NullFilter;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services\PodcastParser;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services\ProfanityFilter;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services\RedisEventPusher;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services\TokenVerifier;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services\Transistor;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Support\ReportAggregator;
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

        $aliasBinding = $this->graph->findBinding('app.currency');
        $this->assertNotNull($aliasBinding);
        $this->assertSame('USD', $aliasBinding['concrete']);
        $this->assertSame('Alias', $aliasBinding['concreteKind']);
        $this->assertTrue($this->graph->hasBindsToEdge('app.currency', 'USD'));

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

        $transistorDependency = $this->graph->findDependencyChainRow(Transistor::class, PodcastParser::class);
        $this->assertNotNull($transistorDependency);
        $this->assertSame('di', $transistorDependency['access']);
    }

    public function test_complex_dataset_writes_class_nodes_and_summary_counts(): void
    {
        $this->runContainerGraph();

        $this->assertTrue($this->graph->hasInstanceNode(Transistor::class));
        $this->assertTrue($this->graph->hasInstanceNode(Firewall::class));
        $this->assertGreaterThanOrEqual(10, count($this->graph->bindingRows));
        $this->assertGreaterThanOrEqual(3, count($this->graph->dependencyChainRows));
    }

    public function test_complex_dataset_exports_facade_catalog_chains(): void
    {
        $this->runContainerGraph();

        $cacheChain = $this->graph->findFacadeCatalogChain(Cache::class);
        $this->assertNotNull($cacheChain);
        $this->assertSame('cache', $cacheChain['identifier']);
        $this->assertSame('singleton', $cacheChain['lifetime']);
        $this->assertSame('facade|cache', $cacheChain['dependency_key']);
    }

    public function test_complex_dataset_exports_contextual_bindings_for_storage_disks(): void
    {
        $this->runContainerGraph();

        $this->assertTrue($this->graph->hasContextualBindsEdge(
            PhotoController::class,
            Filesystem::class,
            'storage.disk:local',
        ));
        $this->assertTrue($this->graph->hasContextualBindsEdge(
            VideoController::class,
            Filesystem::class,
            'storage.disk:public',
        ));

        $photoBinding = $this->graph->findContextualBindingRow(
            PhotoController::class,
            Filesystem::class,
            'storage.disk:local',
        );
        $this->assertNotNull($photoBinding);
        $this->assertSame('Interface', $photoBinding['needs_kind']);
        $this->assertSame('Alias', $photoBinding['give_kind']);
        $this->assertSame('', $photoBinding['reason']);

        $this->assertTrue($this->graph->hasInstanceNode(PhotoController::class));
        $this->assertTrue($this->graph->hasInstanceNode(VideoController::class));
    }

    public function test_complex_dataset_exports_contextual_array_give_bindings(): void
    {
        $this->runContainerGraph();

        $this->assertTrue($this->graph->hasContextualBindsEdge(
            Firewall::class,
            Filter::class,
            NullFilter::class,
        ));
        $this->assertTrue($this->graph->hasContextualBindsEdge(
            Firewall::class,
            Filter::class,
            ProfanityFilter::class,
        ));
    }

    public function test_complex_dataset_writes_method_injection_edges(): void
    {
        $this->runContainerGraph();

        $formRequest = $this->graph->findMethodInjectionChain(
            PostController::class,
            StorePostRequest::class,
            'store',
            'request',
        );
        $this->assertNotNull($formRequest);
        $this->assertSame('method_injection', $formRequest['injection_type']);
        $this->assertSame('di', $formRequest['access']);

        $jobLogger = $this->graph->findMethodInjectionChain(
            ProcessInvoiceJob::class,
            Logger::class,
            'handle',
            'logger',
        );
        $this->assertNotNull($jobLogger);

        $commandAggregator = $this->graph->findMethodInjectionChain(
            SyncReportsCommand::class,
            ReportAggregator::class,
            'handle',
            'aggregator',
        );
        $this->assertNotNull($commandAggregator);

        $listenerLogger = $this->graph->findMethodInjectionChain(
            OrderShippedListener::class,
            Logger::class,
            'handle',
            'logger',
        );
        $this->assertNotNull($listenerLogger);
        $this->assertNull($this->graph->findMethodInjectionChain(
            OrderShippedListener::class,
            OrderShipped::class,
            'handle',
            'event',
        ));

        $middlewareVerifier = $this->graph->findMethodInjectionChain(
            VerifyJsonApi::class,
            TokenVerifier::class,
            'handle',
            'verifier',
        );
        $this->assertNotNull($middlewareVerifier);
        $this->assertNull($this->graph->findMethodInjectionChain(
            VerifyJsonApi::class,
            Request::class,
            'handle',
            'request',
        ));
    }

    public function test_container_graph_dry_run_does_not_write_graph(): void
    {
        $this->artisan('container:graph', ['--dry-run' => true])
            ->expectsOutputToContain('Container graph summary:')
            ->expectsOutputToContain('Bindings:')
            ->expectsOutputToContain('Dry run complete')
            ->assertExitCode(0);

        $this->assertSame([], $this->graph->bindingRows);
        $this->assertSame([], $this->graph->dependencyChainRows);
        $this->assertSame([], $this->graph->instanceRows);
        $this->assertSame([], $this->graph->contextualBindingRows);
    }

    private function runContainerGraph(): void
    {
        $this->artisan('container:graph')
            ->expectsOutputToContain('Container graph written to Neo4j successfully.')
            ->assertExitCode(0);
    }
}
