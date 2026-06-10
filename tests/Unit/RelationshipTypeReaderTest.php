<?php

namespace Neo4j\LaravelBoost\Tests\Unit;

use Neo4j\LaravelBoost\Support\Graph\RelationshipTypeReader;
use Neo4j\LaravelBoost\Tests\TestCase;

class RelationshipTypeReaderTest extends TestCase
{
    public function test_infers_constructor_injection_when_depends_on_type_missing(): void
    {
        $resolved = RelationshipTypeReader::dependsOn(null);

        $this->assertSame('constructor_injection', $resolved['type']);
        $this->assertSame('static_analysis', $resolved['source']);
        $this->assertSame('inferred', $resolved['confidence']);
        $this->assertSame('declared', $resolved['visibility']);
    }

    public function test_returns_high_confidence_for_stored_depends_on_type(): void
    {
        $resolved = RelationshipTypeReader::dependsOn('facade', 'user');

        $this->assertSame('facade', $resolved['type']);
        $this->assertSame('user', $resolved['source']);
        $this->assertSame('high', $resolved['confidence']);
        $this->assertSame('hidden', $resolved['visibility']);
    }

    public function test_infers_normal_binding_from_legacy_shared_false(): void
    {
        $resolved = RelationshipTypeReader::bindsTo(null, false);

        $this->assertSame('normal', $resolved['type']);
        $this->assertFalse($resolved['shared']);
        $this->assertSame('static_analysis', $resolved['source']);
        $this->assertSame('inferred', $resolved['confidence']);
    }
}
