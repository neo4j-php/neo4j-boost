<?php

namespace Neo4j\LaravelBoost\Tests\Unit\ContainerGraph;

use Illuminate\Routing\Router;
use Neo4j\LaravelBoost\ContainerGraph\RouteMiddlewareExtractor;
use Neo4j\LaravelBoost\Tests\TestCase;
use Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures\Http\Controllers\AdminPanelController;
use Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures\Http\Middleware\EnsureTokenIsValid;

class RouteMiddlewareExtractorTest extends TestCase
{
    public function test_extracts_class_middleware_for_controller_routes(): void
    {
        /** @var Router $router */
        $router = $this->app->make('router');
        $router->get('/secure', [AdminPanelController::class, 'index'])
            ->middleware(EnsureTokenIsValid::class)
            ->name('secure.index');

        $rows = (new RouteMiddlewareExtractor)->extract($router);
        $match = null;
        foreach ($rows as $row) {
            if ($row['route_key'] === 'GET /secure' && $row['middleware_key'] === EnsureTokenIsValid::class) {
                $match = $row;
                break;
            }
        }

        $this->assertNotNull($match);
        $this->assertSame(EnsureTokenIsValid::class, $match['identifier']);
        $this->assertSame('Class', $match['identifier_kind']);
        $this->assertSame('', $match['parameters']);
        $this->assertIsInt($match['order']);
    }

    public function test_expands_middleware_aliases_and_keeps_parameters_on_edge(): void
    {
        /** @var Router $router */
        $router = $this->app->make('router');
        $router->aliasMiddleware('token', EnsureTokenIsValid::class);
        $router->get('/aliased', [AdminPanelController::class, 'health'])
            ->middleware('token:strict');

        $rows = (new RouteMiddlewareExtractor)->extract($router);
        $match = null;
        foreach ($rows as $row) {
            if ($row['route_key'] === 'GET /aliased' && $row['middleware_key'] === EnsureTokenIsValid::class) {
                $match = $row;
                break;
            }
        }

        $this->assertNotNull($match);
        $this->assertSame(EnsureTokenIsValid::class, $match['identifier']);
        $this->assertSame('strict', $match['parameters']);
    }

    public function test_skips_middleware_for_closure_routes_without_handlers(): void
    {
        /** @var Router $router */
        $router = $this->app->make('router');
        $before = count((new RouteMiddlewareExtractor)->extract($router));
        $router->get('/closure-only', static fn () => 'ok')->middleware(EnsureTokenIsValid::class);

        $rows = (new RouteMiddlewareExtractor)->extract($router);
        $hits = array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['route_key'] === 'GET /closure-only',
        ));

        $this->assertSame([], $hits);
        $this->assertSame($before, count($rows));
    }

    public function test_preserves_middleware_pipeline_order(): void
    {
        /** @var Router $router */
        $router = $this->app->make('router');
        $router->aliasMiddleware('token', EnsureTokenIsValid::class);
        $router->get('/ordered', [AdminPanelController::class, 'index'])
            ->middleware(['token:one', EnsureTokenIsValid::class.':two']);

        $rows = array_values(array_filter(
            (new RouteMiddlewareExtractor)->extract($router),
            static fn (array $row): bool => $row['route_key'] === 'GET /ordered'
                && $row['middleware_key'] === EnsureTokenIsValid::class,
        ));

        $this->assertGreaterThanOrEqual(2, count($rows));
        $this->assertSame('one', $rows[0]['parameters']);
        $this->assertSame('two', $rows[1]['parameters']);
        $this->assertLessThan($rows[1]['order'], $rows[0]['order']);
    }
}
