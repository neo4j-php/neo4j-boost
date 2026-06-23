<?php

namespace Neo4j\LaravelBoost\Tests\Unit\StaticAnalysis;

use Neo4j\LaravelBoost\ResolutionCatalog\GlobalHelperConfidence;
use Neo4j\LaravelBoost\StaticAnalysis\GlobalHelperEdgeFinder;
use Neo4j\LaravelBoost\Tests\TestCase;

class GlobalHelperEdgeFinderTest extends TestCase
{
    private GlobalHelperEdgeFinder $finder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->finder = $this->app->make(GlobalHelperEdgeFinder::class);
    }

    public function test_scan_source_finds_high_confidence_helpers(): void
    {
        $edges = $this->finder->scanSource(<<<'PHP'
<?php

namespace App\Services;

final class Worker
{
    public function run(): void
    {
        cache()->get('key');
        auth()->user();
        session()->get('id');
    }
}
PHP);

        $helpers = array_map(static fn ($edge) => $edge->helper, $edges);

        $this->assertContains('cache', $helpers);
        $this->assertContains('auth', $helpers);
        $this->assertContains('session', $helpers);
        $this->assertCount(3, $edges);
        $this->assertTrue($edges[0]->confidence === GlobalHelperConfidence::High);
    }

    public function test_scan_source_finds_low_confidence_config_and_env_with_literal_keys(): void
    {
        $edges = $this->finder->scanSource(<<<'PHP'
<?php

namespace App\Services;

final class Worker
{
    public function run(): mixed
    {
        return config('database.default') ?? env('APP_ENV');
    }
}
PHP);

        $this->assertCount(2, $edges);

        $config = collect($edges)->firstWhere('helper', 'config');
        $env = collect($edges)->firstWhere('helper', 'env');

        $this->assertNotNull($config);
        $this->assertSame(GlobalHelperConfidence::Low, $config->confidence);
        $this->assertSame('config.database.default', $config->dependency);

        $this->assertNotNull($env);
        $this->assertSame(GlobalHelperConfidence::Low, $env->confidence);
        $this->assertSame('env.APP_ENV', $env->dependency);
    }

    public function test_scan_paths_finds_fixture_worker_helpers(): void
    {
        $fixtureDir = dirname(__DIR__, 2).'/Integration/Fixtures/StaticAnalysis/Services';

        $edges = $this->finder->scanPaths([$fixtureDir.'/GlobalHelperWorker.php']);

        $helpers = array_values(array_unique(array_map(static fn ($edge) => $edge->helper, $edges)));

        $this->assertGreaterThanOrEqual(3, count($helpers));
        $this->assertContains('cache', $helpers);
        $this->assertContains('auth', $helpers);
        $this->assertContains('logger', $helpers);
    }
}
