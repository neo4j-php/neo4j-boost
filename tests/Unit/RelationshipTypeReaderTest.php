<?php

namespace Neo4j\LaravelBoost\Tests\Unit;

use Neo4j\LaravelBoost\Support\Graph\RelationshipTypeReader;
use Neo4j\LaravelBoost\Tests\TestCase;

class RelationshipTypeReaderTest extends TestCase
{
    public function test_resolves_singleton_binding_type(): void
    {
        $resolved = RelationshipTypeReader::bindsTo('singleton');

        $this->assertSame('singleton', $resolved['type']);
        $this->assertTrue($resolved['shared']);
    }

    public function test_resolves_normal_binding_type(): void
    {
        $resolved = RelationshipTypeReader::bindsTo('normal');

        $this->assertSame('normal', $resolved['type']);
        $this->assertFalse($resolved['shared']);
    }

    public function test_returns_high_confidence_for_stored_depends_on_type(): void
    {
        $resolved = RelationshipTypeReader::dependsOn('facade', 'user');

        $this->assertSame('facade', $resolved['type']);
        $this->assertSame('user', $resolved['source']);
        $this->assertSame('high', $resolved['confidence']);
        $this->assertSame('hidden', $resolved['visibility']);
    }
}
