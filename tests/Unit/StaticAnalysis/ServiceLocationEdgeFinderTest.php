<?php

namespace Neo4j\LaravelBoost\Tests\Unit\StaticAnalysis;

use Neo4j\LaravelBoost\StaticAnalysis\ServiceLocationEdgeFinder;
use Neo4j\LaravelBoost\Tests\TestCase;

class ServiceLocationEdgeFinderTest extends TestCase
{
    public function test_finds_literal_app_resolve_and_app_make_calls(): void
    {
        $fixtureDir = dirname(__DIR__, 2).'/Integration/Fixtures/StaticAnalysis';
        $edges = $this->app->make(ServiceLocationEdgeFinder::class)->scanPaths([$fixtureDir]);

        $this->assertCount(3, $edges);

        $vias = array_map(static fn ($edge): string => $edge->via, $edges);
        sort($vias);

        $this->assertSame(['App::make', 'app', 'resolve'], $vias);

        foreach ($edges as $edge) {
            $this->assertSame(
                'Neo4j\\LaravelBoost\\Tests\\Integration\\Fixtures\\StaticAnalysis\\Services\\OrderProcessor',
                $edge->class,
            );
            $this->assertSame(
                'Neo4j\\LaravelBoost\\Tests\\Integration\\Fixtures\\StaticAnalysis\\Services\\PaymentGateway',
                $edge->dependency,
            );
            $this->assertSame('service_location', $edge->toDependencyRow()['type']);
            $this->assertSame('static', $edge->toDependencyRow()['source']);
        }
    }

    public function test_skips_dynamic_variable_service_locator_calls(): void
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

        $this->assertSame([], $edges);
    }
}
