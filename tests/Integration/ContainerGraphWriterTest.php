<?php

namespace Neo4j\LaravelBoost\Tests\Integration;

use Neo4j\LaravelBoost\ContainerGraphWriter;
use Neo4j\LaravelBoost\Support\ContainerGraphConnection;
use Neo4j\LaravelBoost\Tests\Integration\Support\Stubs\UnusedContainerGraphConnection;
use Neo4j\LaravelBoost\Tests\TestCase;

class ContainerGraphWriterTest extends TestCase
{
    public function test_cypher_templates_include_core_keys(): void
    {
        $writer = new ContainerGraphWriter(new UnusedContainerGraphConnection);
        $keys = array_keys($writer->cypherTemplates());
        sort($keys);

        $this->assertSame(['bindings', 'contextual_binds', 'instance_depends_on', 'instances', 'resolves_to'], $keys);
    }

    public function test_binding_cypher_uses_concrete_kind_for_non_class_targets(): void
    {
        $writer = new ContainerGraphWriter(new UnusedContainerGraphConnection);
        $bindingsTemplate = $writer->cypherTemplates()['bindings'];

        $this->assertStringContainsString('row.concreteKind', $bindingsTemplate);
        $this->assertStringContainsString('AbstractType:Abstract', $bindingsTemplate);
        $this->assertStringContainsString('r.type = row.type', $bindingsTemplate);
    }

    public function test_instance_depends_on_cypher_sets_metadata_on_edges(): void
    {
        $writer = new ContainerGraphWriter(new UnusedContainerGraphConnection);
        $dependsOnTemplate = $writer->cypherTemplates()['instance_depends_on'];

        $this->assertStringContainsString('d.via = row.via', $dependsOnTemplate);
        $this->assertStringContainsString('d.file = row.file', $dependsOnTemplate);
        $this->assertStringContainsString('d.line = row.line', $dependsOnTemplate);
        $this->assertStringContainsString('d.type = row.injection_type', $dependsOnTemplate);
        $this->assertStringContainsString('d.method = row.method', $dependsOnTemplate);
        $this->assertStringContainsString('d.parameter = row.parameter', $dependsOnTemplate);
        $this->assertStringContainsString(':Instance', $dependsOnTemplate);
        $this->assertStringContainsString(':Dependency', $dependsOnTemplate);
    }

    public function test_resolves_to_cypher_sets_lifetime_and_identifier_kind(): void
    {
        $writer = new ContainerGraphWriter(new UnusedContainerGraphConnection);
        $resolvesToTemplate = $writer->cypherTemplates()['resolves_to'];

        $this->assertStringContainsString('r.lifetime = row.lifetime', $resolvesToTemplate);
        $this->assertStringContainsString('dep.access = row.access', $resolvesToTemplate);
        $this->assertStringContainsString(':Identifier', $resolvesToTemplate);
        $this->assertStringContainsString('RESOLVES_TO', $resolvesToTemplate);
    }

    public function test_contextual_binds_cypher_sets_needs_and_give_metadata(): void
    {
        $writer = new ContainerGraphWriter(new UnusedContainerGraphConnection);
        $contextualTemplate = $writer->cypherTemplates()['contextual_binds'];

        $this->assertStringContainsString('CONTEXTUAL_BINDS', $contextualTemplate);
        $this->assertStringContainsString('r.needs = row.needs', $contextualTemplate);
        $this->assertStringContainsString('r.needs_kind = row.needs_kind', $contextualTemplate);
        $this->assertStringContainsString(':Instance', $contextualTemplate);
        $this->assertStringContainsString(':Identifier', $contextualTemplate);
    }

    public function test_parse_dsn_extracts_uri_and_credentials(): void
    {
        /** @var array{uri: string, user: string, password: string}|null $parsed */
        $parsed = ContainerGraphConnection::parseDsnToConnection('neo4j://neo4j:my-pass@neo4j-core1:7687');

        $this->assertNotNull($parsed);
        $this->assertSame('neo4j://neo4j-core1:7687', $parsed['uri']);
        $this->assertSame('neo4j', $parsed['user']);
        $this->assertSame('my-pass', $parsed['password']);
    }

    public function test_parse_dsn_returns_null_for_invalid_string(): void
    {
        $this->assertNull(ContainerGraphConnection::parseDsnToConnection('not-a-valid-url'));
    }
}
