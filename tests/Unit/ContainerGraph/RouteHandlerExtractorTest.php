<?php

namespace Neo4j\LaravelBoost\Tests\Unit\ContainerGraph;

use Illuminate\Routing\Router;
use Neo4j\LaravelBoost\ContainerGraph\RouteHandlerExtractor;
use Neo4j\LaravelBoost\Tests\TestCase;
use Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures\Http\Controllers\AdminPanelController;

class RouteHandlerExtractorTest extends TestCase
{
    public function test_extracts_controller_route_as_handled_by_identifier(): void
    {
        /** @var Router $router */
        $router = $this->app->make('router');
        $router->get('/orders/{id}', [FakeOrdersController::class, 'show'])->name('orders.show');

        $rows = (new RouteHandlerExtractor)->extract($router);
        $match = null;
        foreach ($rows as $row) {
            if ($row['uri'] === '/orders/{id}') {
                $match = $row;
                break;
            }
        }

        $this->assertNotNull($match);
        $this->assertSame(FakeOrdersController::class, $match['identifier']);
        $this->assertSame('Class', $match['identifier_kind']);
        $this->assertSame('GET', $match['methods']);
        $this->assertSame('orders.show', $match['name']);
        $this->assertSame('GET /orders/{id}', $match['key']);
        $this->assertStringContainsString(FakeOrdersController::class, $match['action']);
    }

    public function test_unnamed_routes_keep_empty_name_and_store_path_on_key(): void
    {
        /** @var Router $router */
        $router = $this->app->make('router');
        $router->get('/unnamed-photos', [FakeOrdersController::class, 'show']);

        $rows = (new RouteHandlerExtractor)->extract($router);
        $match = null;
        foreach ($rows as $row) {
            if ($row['uri'] === '/unnamed-photos') {
                $match = $row;
                break;
            }
        }

        $this->assertNotNull($match);
        $this->assertSame('GET /unnamed-photos', $match['key']);
        $this->assertSame('', $match['name']);
    }

    public function test_skips_closure_routes_without_controller_identifier(): void
    {
        /** @var Router $router */
        $router = $this->app->make('router');
        $before = count((new RouteHandlerExtractor)->extract($router));
        $router->get('/health', static fn () => 'ok');

        $rows = (new RouteHandlerExtractor)->extract($router);
        $health = array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['uri'] === '/health',
        ));

        $this->assertSame([], $health);
        $this->assertSame($before, count($rows));
    }

    public function test_extracts_invokable_controller(): void
    {
        /** @var Router $router */
        $router = $this->app->make('router');
        $router->post('/checkout', FakeCheckoutAction::class);

        $rows = (new RouteHandlerExtractor)->extract($router);
        $match = null;
        foreach ($rows as $row) {
            if ($row['uri'] === '/checkout') {
                $match = $row;
                break;
            }
        }

        $this->assertNotNull($match);
        $this->assertSame(FakeCheckoutAction::class, $match['identifier']);
        $this->assertSame('POST', $match['methods']);
        $this->assertSame('', $match['name']);
        $this->assertSame('POST /checkout', $match['key']);
    }

    public function test_extracts_controller_routes_from_custom_route_file_group(): void
    {
        /** @var Router $router */
        $router = $this->app->make('router');
        $router->middleware('web')
            ->prefix('admin')
            ->group(__DIR__.'/Fixtures/routes/admin.php');

        $rows = (new RouteHandlerExtractor)->extract($router);
        $byUri = [];
        foreach ($rows as $row) {
            $byUri[$row['uri']] = $row;
        }

        $this->assertArrayHasKey('/admin/users-overview', $byUri);
        $this->assertSame(AdminPanelController::class, $byUri['/admin/users-overview']['identifier']);
        $this->assertSame('admin.users.overview', $byUri['/admin/users-overview']['name']);
        $this->assertSame('GET /admin/users-overview', $byUri['/admin/users-overview']['key']);

        $this->assertArrayHasKey('/admin/health-summary', $byUri);
        $this->assertSame(AdminPanelController::class, $byUri['/admin/health-summary']['identifier']);
        $this->assertSame('', $byUri['/admin/health-summary']['name']);
        $this->assertSame('GET /admin/health-summary', $byUri['/admin/health-summary']['key']);

        $this->assertArrayNotHasKey('/admin/ping', $byUri);
    }
}

final class FakeOrdersController
{
    public function show(int $id): string
    {
        return (string) $id;
    }
}

final class FakeCheckoutAction
{
    public function __invoke(): string
    {
        return 'checkout';
    }
}
