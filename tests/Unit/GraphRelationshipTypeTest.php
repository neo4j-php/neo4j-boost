<?php

namespace Neo4j\LaravelBoost\Tests\Unit;

use Neo4j\LaravelBoost\Support\Graph\BindsToType;
use Neo4j\LaravelBoost\Support\Graph\DependsOnType;
use Neo4j\LaravelBoost\Tests\TestCase;

class GraphRelationshipTypeTest extends TestCase
{
    public function test_depends_on_type_rejects_unknown_values(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DependsOnType::assertAllowed('uses_facade');
    }

    public function test_binds_to_type_maps_shared_flag_to_singleton(): void
    {
        $this->assertSame(BindsToType::Singleton, BindsToType::fromShared(true));
        $this->assertSame(BindsToType::Normal, BindsToType::fromShared(false));
    }
}
