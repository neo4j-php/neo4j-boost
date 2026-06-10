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
        $this->assertSame('inferred', $resolved['confidence']);
    }

    public function test_infers_normal_binding_from_legacy_shared_false(): void
    {
        $resolved = RelationshipTypeReader::bindsTo(null, false);

        $this->assertSame('normal', $resolved['type']);
        $this->assertFalse($resolved['shared']);
        $this->assertSame('inferred', $resolved['confidence']);
    }
}
