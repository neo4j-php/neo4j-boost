<?php

namespace Neo4j\LaravelBoost\Tests\Unit\StaticAnalysis;

use Neo4j\LaravelBoost\StaticAnalysis\PhpStan\ServiceLocationFuncCallRule;
use Neo4j\LaravelBoost\StaticAnalysis\ServiceLocationNodeResolver;
use Neo4j\LaravelBoost\StaticAnalysis\StaticAnalysisCollector;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<ServiceLocationFuncCallRule>
 */
class ServiceLocationCollectorRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new ServiceLocationFuncCallRule(new ServiceLocationNodeResolver);
    }

    protected function tearDown(): void
    {
        StaticAnalysisCollector::reset();

        parent::tearDown();
    }

    public function test_collector_records_literal_app_call(): void
    {
        StaticAnalysisCollector::reset();

        $this->analyse(
            [dirname(__DIR__, 2).'/Integration/Fixtures/StaticAnalysis/Services/OrderProcessor.php'],
            [],
        );

        $edges = StaticAnalysisCollector::serviceLocationEdges();
        $this->assertNotEmpty($edges);
        $this->assertContains('app', array_map(static fn ($edge): string => $edge->via, $edges));
    }

    public function test_collector_records_dynamic_app_call_as_unresolved(): void
    {
        StaticAnalysisCollector::reset();

        $this->analyse(
            [__DIR__.'/Fixtures/DynamicServiceLocator.php'],
            [],
        );

        $edges = StaticAnalysisCollector::serviceLocationEdges();
        $this->assertCount(2, $edges);

        foreach ($edges as $edge) {
            $this->assertFalse($edge->resolved);
            $this->assertSame('__dynamic__', $edge->dependency);
        }

        $vias = array_map(static fn ($edge): string => $edge->via, $edges);
        sort($vias);
        $this->assertSame(['app', 'resolve'], $vias);
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [
            dirname(__DIR__, 3).'/phpstan-static-analysis-test.neon.dist',
        ];
    }
}
