<?php

namespace Neo4j\LaravelBoost\Tests\Unit\StaticAnalysis;

use Neo4j\LaravelBoost\StaticAnalysis\InstantiationEdgeFinder;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\StaticAnalysis\Services\InvoiceLineDto;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\StaticAnalysis\Services\PaymentGateway;
use Neo4j\LaravelBoost\Tests\TestCase;

class InstantiationEdgeFinderTest extends TestCase
{
    private InstantiationEdgeFinder $finder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->finder = $this->app->make(InstantiationEdgeFinder::class);
    }

    public function test_scan_source_finds_service_class_instantiation(): void
    {
        $edges = $this->finder->scanSource(<<<'PHP'
<?php

namespace App\Services;

use App\Contracts\PaymentGateway;

final class CheckoutService
{
    public function run(): PaymentGateway
    {
        return new PaymentGateway();
    }
}
PHP);

        $this->assertCount(1, $edges);
        $this->assertSame('App\\Services\\CheckoutService', $edges[0]->class);
        $this->assertSame('App\\Contracts\\PaymentGateway', $edges[0]->dependency);
    }

    public function test_scan_source_finds_dto_instantiation(): void
    {
        $edges = $this->finder->scanSource(<<<'PHP'
<?php

namespace App\Services;

use App\Data\InvoiceLineDto;

final class InvoiceBuilder
{
    public function build(): InvoiceLineDto
    {
        return new InvoiceLineDto('Line item', 100);
    }
}
PHP);

        $this->assertCount(1, $edges);
        $this->assertSame('App\\Data\\InvoiceLineDto', $edges[0]->dependency);
    }

    public function test_scan_source_skips_builtin_anonymous_and_dynamic_classes(): void
    {
        $edges = $this->finder->scanSource(<<<'PHP'
<?php

namespace App\Services;

use DateTime;

final class Worker
{
    public function run(string $className): mixed
    {
        new DateTime();
        new class {};
        return new $className();
    }
}
PHP);

        $this->assertCount(0, $edges);
    }

    public function test_scan_paths_finds_fixture_service_and_dto_cases(): void
    {
        $fixture = dirname(__DIR__, 2).'/Integration/Fixtures/StaticAnalysis/Services/DirectInstantiator.php';

        $edges = $this->finder->scanPaths([$fixture]);

        $dependencies = array_map(static fn ($edge) => $edge->dependency, $edges);

        $this->assertContains(PaymentGateway::class, $dependencies);
        $this->assertContains(InvoiceLineDto::class, $dependencies);
        $this->assertCount(2, $edges);
    }
}
