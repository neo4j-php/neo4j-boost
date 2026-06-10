<?php

namespace Neo4j\LaravelBoost\Tests\Unit;

use Neo4j\LaravelBoost\Support\Graph\GraphCompleteness;
use Neo4j\LaravelBoost\Tests\TestCase;

class GraphCompletenessTest extends TestCase
{
    public function test_build_reports_partial_coverage_when_only_declared_dependencies_exist(): void
    {
        $block = GraphCompleteness::build(3, 0);

        $this->assertSame(3, $block['declared_count']);
        $this->assertSame(0, $block['hidden_count']);
        $this->assertSame(3, $block['total_count']);
        $this->assertSame('partial', $block['coverage']);
        $this->assertContains('constructor_injection', $block['detectors_active']);
        $this->assertContains('facade', $block['detectors_pending']);
    }

    public function test_build_reports_mixed_coverage_when_both_buckets_have_entries(): void
    {
        $block = GraphCompleteness::build(2, 1);

        $this->assertSame('mixed', $block['coverage']);
        $this->assertSame(3, $block['total_count']);
    }

    public function test_unknown_coverage_for_missing_graph_data(): void
    {
        $block = GraphCompleteness::unknown();

        $this->assertSame('unknown', $block['coverage']);
        $this->assertSame(0, $block['total_count']);
    }
}
