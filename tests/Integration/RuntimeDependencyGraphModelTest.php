<?php

namespace Neo4j\LaravelBoost\Tests\Integration;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Filesystem\Filesystem;
use Neo4j\LaravelBoost\ContainerGraphWriter;
use Neo4j\LaravelBoost\Support\Graph\RuntimeGraphModel;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Commands\SyncReportsCommand;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Controllers\PhotoController;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Events\OrderShipped;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Jobs\ProcessInvoiceJob;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Listeners\OrderShippedListener;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Middleware\VerifyJsonApi;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services\Logger;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Support\ReportAggregator;
use Neo4j\LaravelBoost\Tests\Integration\Support\RecordingContainerGraphWriter;
use Neo4j\LaravelBoost\Tests\Integration\Support\Stubs\UnusedContainerGraphConnection;
use Neo4j\LaravelBoost\Tests\TestCase;

/**
 * Acceptance coverage for the runtime dependency graph model:
 * Route -> Abstract -> Instance -> Dependency -> Abstract
 * Route -> Middleware -> Abstract
 * Event -> Abstract -> Instance
 * Job -> Abstract -> Instance
 * Job -> QueueConnection
 * ScheduledTask -> Abstract -> Instance.
 */
class RuntimeDependencyGraphModelTest extends TestCase
{
    private RecordingContainerGraphWriter $graph;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(Filesystem::class, fn () => $this->createMock(Filesystem::class));

        $this->graph = new RecordingContainerGraphWriter;
        $this->app->instance(ContainerGraphWriter::class, $this->graph);
    }

    public function test_exports_route_instance_dependency_and_identifier_chain(): void
    {
        $this->app['router']->get('/photos/{id}', [PhotoController::class, 'show'])
            ->name('photos.show');

        $this->artisan('container:graph')
            ->expectsOutputToContain('Route handlers:')
            ->expectsOutputToContain('Container graph written to Neo4j successfully.')
            ->assertExitCode(0);

        $this->assertTrue($this->graph->hasRouteHandledBy('GET /photos/{id}', PhotoController::class));
        $this->assertTrue($this->graph->hasInstanceNode(PhotoController::class));
        $this->assertTrue($this->graph->hasDependsOnEdge(PhotoController::class, Filesystem::class));

        $chain = $this->graph->findDependencyChainRow(PhotoController::class, Filesystem::class);
        $this->assertNotNull($chain);
        $this->assertSame('di', $chain['access']);
        $this->assertNotSame('', $chain['dependency_key']);
        $this->assertSame(Filesystem::class, $chain['identifier']);
    }

    public function test_exports_route_middleware_identified_as_chain(): void
    {
        $this->app['router']->aliasMiddleware('token', VerifyJsonApi::class);
        $this->app['router']->get('/photos/{id}', [PhotoController::class, 'show'])
            ->middleware('token:strict')
            ->name('photos.secure');

        $this->artisan('container:graph')
            ->expectsOutputToContain('Route middleware links:')
            ->expectsOutputToContain('Container graph written to Neo4j successfully.')
            ->assertExitCode(0);

        $this->assertTrue($this->graph->hasRouteHandledBy('GET /photos/{id}', PhotoController::class));
        $this->assertTrue($this->graph->hasRouteMiddleware('GET /photos/{id}', VerifyJsonApi::class, 'strict'));
        $this->assertTrue($this->graph->hasInstanceNode(VerifyJsonApi::class));
    }

    public function test_exports_event_listener_handled_by_chain(): void
    {
        $this->app->make('events')->listen(OrderShipped::class, OrderShippedListener::class);

        $this->artisan('container:graph')
            ->expectsOutputToContain('Event listeners:')
            ->expectsOutputToContain('Container graph written to Neo4j successfully.')
            ->assertExitCode(0);

        $this->assertTrue($this->graph->hasEventHandledBy(OrderShipped::class, OrderShippedListener::class));
        $this->assertTrue($this->graph->hasInstanceNode(OrderShippedListener::class));
        $this->assertTrue($this->graph->hasDependsOnEdge(OrderShippedListener::class, Logger::class));

        $chain = $this->graph->findMethodInjectionChain(
            OrderShippedListener::class,
            Logger::class,
            'handle',
            'logger',
        );
        $this->assertNotNull($chain);
        $this->assertNull($this->graph->findMethodInjectionChain(
            OrderShippedListener::class,
            OrderShipped::class,
            'handle',
            'event',
        ));
    }

    public function test_exports_job_handled_by_and_queue_connections(): void
    {
        $this->app->bind(ProcessInvoiceJob::class, ProcessInvoiceJob::class);
        config([
            'queue.default' => 'sync',
            'queue.connections' => [
                'sync' => ['driver' => 'sync'],
                'redis' => ['driver' => 'redis', 'queue' => 'default'],
            ],
        ]);

        $this->artisan('container:graph')
            ->expectsOutputToContain('Jobs:')
            ->expectsOutputToContain('Queue connections:')
            ->expectsOutputToContain('Container graph written to Neo4j successfully.')
            ->assertExitCode(0);

        $this->assertTrue($this->graph->hasJobHandledBy(ProcessInvoiceJob::class, ProcessInvoiceJob::class));
        $this->assertTrue($this->graph->hasInstanceNode(ProcessInvoiceJob::class));
        $this->assertTrue($this->graph->hasDependsOnEdge(ProcessInvoiceJob::class, Logger::class));
        $this->assertTrue($this->graph->hasQueueConnection('sync'));
        $this->assertTrue($this->graph->hasQueueConnection('redis'));
    }

    public function test_exports_scheduled_task_handled_by_command_chain(): void
    {
        $this->app->make(Kernel::class)
            ->registerCommand($this->app->make(SyncReportsCommand::class));

        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);
        $schedule->command('reports:sync')->daily();

        $this->artisan('container:graph')
            ->expectsOutputToContain('Scheduled tasks:')
            ->expectsOutputToContain('Container graph written to Neo4j successfully.')
            ->assertExitCode(0);

        $this->assertTrue($this->graph->hasScheduledTaskHandledBy(SyncReportsCommand::class, 'command'));
        $this->assertTrue($this->graph->hasInstanceNode(SyncReportsCommand::class));
        $this->assertTrue($this->graph->hasDependsOnEdge(
            SyncReportsCommand::class,
            ReportAggregator::class,
        ));
    }

    public function test_writer_templates_and_traversal_cypher_support_recursive_walk(): void
    {
        $templates = (new ContainerGraphWriter(
            new UnusedContainerGraphConnection
        ))->cypherTemplates();

        $this->assertArrayHasKey('routes', $templates);
        $this->assertArrayHasKey('route_middleware', $templates);
        $this->assertArrayHasKey('events', $templates);
        $this->assertArrayHasKey('jobs', $templates);
        $this->assertArrayHasKey('queue_connections', $templates);
        $this->assertArrayHasKey('scheduled_tasks', $templates);
        $this->assertArrayHasKey('identified_as', $templates);
        $this->assertArrayHasKey('abstract_resolves_to', $templates);
        $this->assertStringContainsString('HANDLED_BY', $templates['routes']);
        $this->assertStringContainsString('HANDLED_BY', $templates['events']);
        $this->assertStringContainsString(':Event', $templates['events']);
        $this->assertStringContainsString('HANDLED_BY', $templates['jobs']);
        $this->assertStringContainsString(':Job', $templates['jobs']);
        $this->assertStringContainsString('USES_MIDDLEWARE', $templates['route_middleware']);
        $this->assertStringContainsString(':ScheduledTask', $templates['scheduled_tasks']);
        $this->assertStringContainsString('IDENTIFIED_AS', $templates['identified_as']);
        $this->assertStringContainsString('RESOLVES_TO', $templates['abstract_resolves_to']);
        $this->assertStringContainsString(':Abstract', $templates['routes']);
        $this->assertStringNotContainsString(':Identifier', $templates['routes']);

        $traversal = RuntimeGraphModel::routeTraversalCypher();
        $this->assertStringContainsString('DEPENDS_ON|IDENTIFIED_AS|RESOLVES_TO*', $traversal);
        $this->assertStringContainsString('USES_MIDDLEWARE', $traversal);
        $this->assertStringContainsString(':Abstract', $traversal);

        $eventTraversal = RuntimeGraphModel::eventTraversalCypher();
        $this->assertStringContainsString(':Event', $eventTraversal);
        $this->assertStringContainsString('HANDLED_BY', $eventTraversal);

        $jobTraversal = RuntimeGraphModel::jobTraversalCypher();
        $this->assertStringContainsString(':Job', $jobTraversal);
        $this->assertStringContainsString('HANDLED_BY', $jobTraversal);
        $this->assertStringContainsString('USES_CONNECTION', $jobTraversal);

        $scheduleTraversal = RuntimeGraphModel::scheduledTaskTraversalCypher();
        $this->assertStringContainsString(':ScheduledTask', $scheduleTraversal);
        $this->assertStringContainsString('HANDLED_BY', $scheduleTraversal);
    }

    public function test_dry_run_lists_route_handlers_without_write(): void
    {
        $this->app['router']->get('/photos', [PhotoController::class, 'index']);

        $this->artisan('container:graph', ['--dry-run' => true])
            ->expectsOutputToContain('Route handlers:')
            ->expectsOutputToContain('Route middleware links:')
            ->expectsOutputToContain('Event listeners:')
            ->expectsOutputToContain('Jobs:')
            ->expectsOutputToContain('Queue connections:')
            ->expectsOutputToContain('Scheduled tasks:')
            ->expectsOutputToContain('Dry run complete')
            ->assertExitCode(0);

        $this->assertSame([], $this->graph->routeRows);
        $this->assertSame([], $this->graph->routeMiddlewareRows);
        $this->assertSame([], $this->graph->eventRows);
        $this->assertSame([], $this->graph->jobRows);
        $this->assertSame([], $this->graph->queueConnectionRows);
        $this->assertSame([], $this->graph->scheduledTaskRows);
    }
}
