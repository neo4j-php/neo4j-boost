<?php

namespace Neo4j\LaravelBoost\Tests\Unit\StaticAnalysis;

use Neo4j\LaravelBoost\StaticAnalysis\FacadeEdgeFinder;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ResolutionCatalog\CustomAccessorService;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ResolutionCatalog\CustomClassAccessorFacade;
use Neo4j\LaravelBoost\Tests\TestCase;

class FacadeEdgeFinderTest extends TestCase
{
    private FacadeEdgeFinder $finder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->finder = $this->app->make(FacadeEdgeFinder::class);
    }

    public function test_scan_source_finds_laravel_facade_call(): void
    {
        $edges = $this->finder->scanSource(<<<'PHP'
<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

final class InvoiceNotifier
{
    public function notify(): void
    {
        Cache::put('key', 'value');
    }
}
PHP);

        $this->assertCount(1, $edges);
        $this->assertSame('App\\Services\\InvoiceNotifier', $edges[0]->class);
        $this->assertSame('Illuminate\\Support\\Facades\\Cache', $edges[0]->facadeClass);
        $this->assertSame('put', $edges[0]->method);
        $this->assertSame('Illuminate\\Support\\Facades\\Cache::put', $edges[0]->facadeClass.'::'.$edges[0]->method);
    }

    public function test_scan_source_finds_custom_facade_call(): void
    {
        $this->app->singleton(CustomAccessorService::class);

        $edges = $this->finder->scanSource(<<<'PHP'
<?php

namespace App\Services;

use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ResolutionCatalog\CustomClassAccessorFacade;

final class InvoiceNotifier
{
    public function notify(): void
    {
        CustomClassAccessorFacade::handle();
    }
}
PHP);

        $this->assertCount(1, $edges);
        $this->assertSame(CustomClassAccessorFacade::class, $edges[0]->facadeClass);
        $this->assertSame('handle', $edges[0]->method);
        $this->assertSame('auto_discovered_facade', $edges[0]->catalogSource);
        $this->assertSame('auto_discovered_facade', $edges[0]->toDependencyRow()['catalog_source']);
    }

    public function test_scan_source_finds_real_time_facade_call(): void
    {
        $edges = $this->finder->scanSource(<<<'PHP'
<?php

namespace App\Services;

final class InvoiceNotifier
{
    public function notify(): void
    {
        \Facades\App\Services\PaymentGateway::charge();
    }
}
PHP);

        $this->assertCount(1, $edges);
        $this->assertSame('App\\Services\\InvoiceNotifier', $edges[0]->class);
        $this->assertSame('Facades\\App\\Services\\PaymentGateway', $edges[0]->facadeClass);
        $this->assertSame('App\\Services\\PaymentGateway', $edges[0]->dependency);
        $this->assertSame('charge', $edges[0]->method);
        $this->assertSame('auto_discovered_facade', $edges[0]->catalogSource);
    }

    public function test_laravel_facade_edge_carries_laravel_catalog_source(): void
    {
        $edges = $this->finder->scanSource(<<<'PHP'
<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

final class InvoiceNotifier
{
    public function notify(): void
    {
        Cache::put('key', 'value');
    }
}
PHP);

        $this->assertCount(1, $edges);
        $this->assertSame('laravel_facade', $edges[0]->catalogSource);
        $this->assertSame('laravel_facade', $edges[0]->toDependencyRow()['catalog_source']);
    }
}
