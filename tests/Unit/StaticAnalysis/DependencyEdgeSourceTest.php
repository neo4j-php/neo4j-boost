<?php

namespace Neo4j\LaravelBoost\Tests\Unit\StaticAnalysis;

use InvalidArgumentException;
use Neo4j\LaravelBoost\StaticAnalysis\DependencyEdgeSource;
use PHPUnit\Framework\TestCase;

class DependencyEdgeSourceTest extends TestCase
{
    public function test_assert_allowed_returns_enum_for_known_value(): void
    {
        $this->assertSame(
            DependencyEdgeSource::Static,
            DependencyEdgeSource::assertAllowed('static'),
        );
    }

    public function test_assert_allowed_throws_for_unknown_value(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DependencyEdgeSource::assertAllowed('not-a-source');
    }
}
