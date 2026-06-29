<?php

namespace Neo4j\LaravelBoost\Tests\Unit\StaticAnalysis;

use Neo4j\LaravelBoost\StaticAnalysis\PhpStan\ServiceLocationStaticCallRule;
use Neo4j\LaravelBoost\StaticAnalysis\ServiceLocationNodeResolver;
use Neo4j\LaravelBoost\StaticAnalysis\StaticAnalysisCollector;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<ServiceLocationStaticCallRule>
 */
class ServiceLocationStaticCallCollectorRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new ServiceLocationStaticCallRule(new ServiceLocationNodeResolver);
    }

    protected function tearDown(): void
    {
        StaticAnalysisCollector::reset();

        parent::tearDown();
    }

    public function test_collector_records_literal_app_make_call(): void
    {
        StaticAnalysisCollector::reset();

        $this->analyse(
            [dirname(__DIR__, 2).'/Integration/Fixtures/StaticAnalysis/Services/OrderProcessor.php'],
            [],
        );

        $edges = StaticAnalysisCollector::serviceLocationEdges();
        $this->assertContains('App::make', array_map(static fn ($edge): string => $edge->via, $edges));
    }

    public function test_collector_records_dynamic_app_make_as_unresolved(): void
    {
        StaticAnalysisCollector::reset();

        $this->analyse(
            [__DIR__.'/Fixtures/DynamicServiceLocator.php'],
            [],
        );

        $edges = StaticAnalysisCollector::serviceLocationEdges();
        $this->assertCount(1, $edges);
        $this->assertSame('App::make', $edges[0]->via);
        $this->assertFalse($edges[0]->resolved);
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [
            dirname(__DIR__, 3).'/phpstan-static-analysis-test.neon.dist',
        ];
    }
}
