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
}
