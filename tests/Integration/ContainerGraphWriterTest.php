<?php

namespace Neo4j\LaravelBoost\Tests\Integration;

use Neo4j\LaravelBoost\ContainerGraphWriter;
use Neo4j\LaravelBoost\Tests\TestCase;
use ReflectionMethod;

class ContainerGraphWriterTest extends TestCase
{
    public function test_cypher_templates_include_core_keys(): void
    {
        $writer = new ContainerGraphWriter;
        $keys = array_keys($writer->cypherTemplates());
        sort($keys);

        $this->assertSame(['bindings', 'classes', 'dependencies', 'unresolved'], $keys);
    }

    public function test_binding_cypher_uses_concrete_kind_for_non_class_targets(): void
    {
        $writer = new ContainerGraphWriter;
        $bindingsTemplate = $writer->cypherTemplates()['bindings'];

        $this->assertStringContainsString('row.concreteKind', $bindingsTemplate);
        $this->assertStringContainsString('AbstractType:Abstract', $bindingsTemplate);
    }

    public function test_parse_dsn_extracts_uri_and_credentials(): void
    {
        $method = new ReflectionMethod(ContainerGraphWriter::class, 'parseDsnToConnection');
        $method->setAccessible(true);
        /** @var array{uri: string, user: string, password: string}|null $parsed */
        $parsed = $method->invoke(null, 'neo4j://neo4j:my-pass@neo4j-core1:7687');

        $this->assertNotNull($parsed);
        $this->assertSame('neo4j://neo4j-core1:7687', $parsed['uri']);
        $this->assertSame('neo4j', $parsed['user']);
        $this->assertSame('my-pass', $parsed['password']);
    }

    public function test_parse_dsn_returns_null_for_invalid_string(): void
    {
        $method = new ReflectionMethod(ContainerGraphWriter::class, 'parseDsnToConnection');
        $method->setAccessible(true);

        $this->assertNull($method->invoke(null, 'not-a-valid-url'));
    }
}
