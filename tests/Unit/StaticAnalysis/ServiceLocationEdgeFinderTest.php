<?php

namespace Neo4j\LaravelBoost\Tests\Unit\StaticAnalysis;

use Neo4j\LaravelBoost\StaticAnalysis\ServiceLocationCallDetector;
use Neo4j\LaravelBoost\StaticAnalysis\ServiceLocationEdgeFinder;
use Neo4j\LaravelBoost\Tests\TestCase;

class ServiceLocationEdgeFinderTest extends TestCase
{
    public function test_finds_literal_app_resolve_and_app_make_calls(): void
    {
        $fixtureDir = dirname(__DIR__, 2).'/Integration/Fixtures/StaticAnalysis/Services';
        $edges = $this->app->make(ServiceLocationEdgeFinder::class)->scanPaths([$fixtureDir]);

        $orderProcessorEdges = array_values(array_filter(
            $edges,
            static fn ($edge): bool => str_ends_with($edge->class, '\\OrderProcessor'),
        ));

        $this->assertCount(3, $orderProcessorEdges);

        $vias = array_map(static fn ($edge): string => $edge->via, $orderProcessorEdges);
        sort($vias);

        $this->assertSame(['App::make', 'app', 'resolve'], $vias);

        foreach ($orderProcessorEdges as $edge) {
            $this->assertSame(
                'Neo4j\\LaravelBoost\\Tests\\Integration\\Fixtures\\StaticAnalysis\\Services\\OrderProcessor',
                $edge->class,
            );
            $this->assertSame(
                'Neo4j\\LaravelBoost\\Tests\\Integration\\Fixtures\\StaticAnalysis\\Services\\PaymentGateway',
                $edge->dependency,
            );
            $this->assertTrue($edge->resolved);
            $this->assertSame('service_location', $edge->toDependencyRow()['type']);
            $this->assertSame('static', $edge->toDependencyRow()['source']);
        }
    }

    public function test_finds_extended_service_locator_patterns(): void
    {
        $fixture = dirname(__DIR__, 2).'/Integration/Fixtures/StaticAnalysis/Services/ExtendedServiceLocator.php';
        $edges = $this->app->make(ServiceLocationEdgeFinder::class)->scanPaths([$fixture]);

        $this->assertCount(3, $edges);

        $vias = array_map(static fn ($edge): string => $edge->via, $edges);
        sort($vias);

        $this->assertSame([
            '$app->make',
            '$this->app->make',
            'App::makeWith',
        ], $vias);
    }

    public function test_finds_application_static_make_call(): void
    {
        $source = <<<'PHP'
<?php

namespace Demo;

use Illuminate\Foundation\Application;

class Worker
{
    public function run(): void
    {
        Application::make(Foo::class);
    }
}

final class Foo {}
PHP;

        $edges = $this->app->make(ServiceLocationEdgeFinder::class)->scanSource($source);

        $this->assertCount(1, $edges);
        $this->assertSame('Application::make', $edges[0]->via);
        $this->assertSame('Demo\\Foo', $edges[0]->dependency);
    }

    public function test_records_dynamic_variable_service_locator_calls_as_unresolved(): void
    {
        $source = <<<'PHP'
<?php
namespace Demo;

use Illuminate\Support\Facades\App;

class Worker
{
    public function run(string $abstract): void
    {
        app($abstract);
        resolve($abstract);
        App::make($abstract);
    }
}
PHP;

        $edges = $this->app->make(ServiceLocationEdgeFinder::class)->scanSource($source);

        $this->assertCount(3, $edges);

        foreach ($edges as $edge) {
            $this->assertFalse($edge->resolved);
            $this->assertSame(ServiceLocationCallDetector::UNRESOLVED_IDENTIFIER, $edge->dependency);
            $this->assertSame(ServiceLocationCallDetector::UNRESOLVED_REASON, $edge->reason);

            $row = $edge->toDependencyRow();
            $this->assertSame('Unresolved', $row['dependencyKind']);
            $this->assertSame(ServiceLocationCallDetector::UNRESOLVED_REASON, $row['reason']);
        }

        $vias = array_map(static fn ($edge): string => $edge->via, $edges);
        sort($vias);
        $this->assertSame(['App::make', 'app', 'resolve'], $vias);
    }
}
