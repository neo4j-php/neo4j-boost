<?php

namespace Neo4j\LaravelBoost\Tests\Integration;

use Illuminate\Contracts\Filesystem\Filesystem;
use Neo4j\LaravelBoost\ContainerGraphWriter;
use Neo4j\LaravelBoost\Support\Graph\RuntimeGraphModel;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Controllers\PhotoController;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Middleware\VerifyJsonApi;
use Neo4j\LaravelBoost\Tests\Integration\Support\RecordingContainerGraphWriter;
use Neo4j\LaravelBoost\Tests\Integration\Support\Stubs\UnusedContainerGraphConnection;
use Neo4j\LaravelBoost\Tests\TestCase;

/**
 * Acceptance coverage for the runtime dependency graph model:
 * Route -> Identifier -> Instance -> Dependency -> Identifier
 * Route -> Middleware -> Identifier.
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

    public function test_writer_templates_and_traversal_cypher_support_recursive_walk(): void
    {
        $templates = (new ContainerGraphWriter(
            new UnusedContainerGraphConnection
        ))->cypherTemplates();

        $this->assertArrayHasKey('routes', $templates);
        $this->assertArrayHasKey('route_middleware', $templates);
        $this->assertArrayHasKey('identified_as', $templates);
        $this->assertArrayHasKey('identifier_resolves_to', $templates);
        $this->assertStringContainsString('HANDLED_BY', $templates['routes']);
        $this->assertStringContainsString('USES_MIDDLEWARE', $templates['route_middleware']);
        $this->assertStringContainsString('IDENTIFIED_AS', $templates['identified_as']);
        $this->assertStringContainsString('RESOLVES_TO', $templates['identifier_resolves_to']);

        $traversal = RuntimeGraphModel::routeTraversalCypher();
        $this->assertStringContainsString('DEPENDS_ON|IDENTIFIED_AS|RESOLVES_TO*', $traversal);
        $this->assertStringContainsString('USES_MIDDLEWARE', $traversal);
    }

    public function test_dry_run_lists_route_handlers_without_write(): void
    {
        $this->app['router']->get('/photos', [PhotoController::class, 'index']);

        $this->artisan('container:graph', ['--dry-run' => true])
            ->expectsOutputToContain('Route handlers:')
            ->expectsOutputToContain('Route middleware links:')
            ->expectsOutputToContain('Dry run complete')
            ->assertExitCode(0);

        $this->assertSame([], $this->graph->routeRows);
        $this->assertSame([], $this->graph->routeMiddlewareRows);
    }
}
