<?php

namespace Neo4j\LaravelBoost\Tests\Unit\StaticAnalysis;

use Neo4j\LaravelBoost\StaticAnalysis\FacadeNodeResolver;
use Neo4j\LaravelBoost\StaticAnalysis\PhpStan\FacadeStaticCallRule;
use Neo4j\LaravelBoost\StaticAnalysis\StaticAnalysisCollector;
use Neo4j\LaravelBoost\Tests\Unit\StaticAnalysis\Support\TestbenchResolutionCatalogFactory;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<FacadeStaticCallRule>
 */
class FacadeCollectorRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new FacadeStaticCallRule(
            new FacadeNodeResolver(TestbenchResolutionCatalogFactory::create()),
        );
    }

    protected function tearDown(): void
    {
        StaticAnalysisCollector::reset();
        TestbenchResolutionCatalogFactory::reset();

        parent::tearDown();
    }

    public function test_collector_records_laravel_and_custom_facade_calls(): void
    {
        StaticAnalysisCollector::reset();

        $this->analyse(
            [dirname(__DIR__, 2).'/Integration/Fixtures/StaticAnalysis/Services/InvoiceNotifier.php'],
            [],
        );

        $edges = StaticAnalysisCollector::facadeEdges();
        $this->assertNotEmpty($edges);

        $vias = array_map(
            static fn ($edge): string => $edge->facadeClass.'::'.$edge->method,
            $edges,
        );
        $this->assertContains('Illuminate\\Support\\Facades\\Cache::put', $vias);
        $this->assertContains(
            'Neo4j\\LaravelBoost\\Tests\\Integration\\Fixtures\\ResolutionCatalog\\CustomClassAccessorFacade::handle',
            $vias,
        );
    }

    public function test_collector_ignores_dynamic_facade_calls(): void
    {
        StaticAnalysisCollector::reset();

        $this->analyse(
            [dirname(__DIR__, 2).'/Integration/Fixtures/StaticAnalysis/Services/InvoiceNotifier.php'],
            [],
        );

        $dynamic = array_filter(
            StaticAnalysisCollector::facadeEdges(),
            static fn ($edge): bool => str_contains($edge->method, '$'),
        );

        $this->assertSame([], array_values($dynamic));
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [
            dirname(__DIR__, 3).'/phpstan-static-analysis-test.neon.dist',
        ];
    }
}
