<?php

namespace Neo4j\LaravelBoost\Tests\Unit\Support\Graph;

use Neo4j\LaravelBoost\Support\Graph\GraphCompleteness;
use PHPUnit\Framework\TestCase;

class GraphCompletenessTest extends TestCase
{
    public function test_partial_status_includes_limitations(): void
    {
        $completeness = GraphCompleteness::partial();

        $this->assertSame('partial', $completeness['status']);
        $this->assertNotEmpty($completeness['limitations']);
        $this->assertContains(
            'Dynamic service location, facade, and helper calls without literal arguments are skipped.',
            $completeness['limitations'],
        );
    }
}
