<?php

namespace Neo4j\LaravelBoost\Tests\Integration;

use Neo4j\LaravelBoost\ContainerGraphWriter;
use Neo4j\LaravelBoost\Support\ContainerGraphConnection;
use Neo4j\LaravelBoost\Tests\Integration\Support\Stubs\TrackingContainerGraphConnection;
use Neo4j\LaravelBoost\Tests\Integration\Support\Stubs\UnusedContainerGraphConnection;
use Neo4j\LaravelBoost\Tests\TestCase;

class ContainerGraphWriterTest extends TestCase
{
    public function test_cypher_templates_include_core_keys(): void
    {
        $writer = new ContainerGraphWriter(new UnusedContainerGraphConnection);
        $keys = array_keys($writer->cypherTemplates());
        sort($keys);

        $this->assertSame(['abstract_resolves_to', 'bindings', 'contextual_binds', 'events', 'identified_as', 'instance_depends_on', 'instances', 'jobs', 'queue_connections', 'route_middleware', 'routes', 'scheduled_tasks'], $keys);
    }

    public function test_binding_cypher_uses_concrete_kind_for_non_class_targets(): void
    {
        $writer = new ContainerGraphWriter(new UnusedContainerGraphConnection);
        $bindingsTemplate = $writer->cypherTemplates()['bindings'];

        $this->assertStringContainsString('row.concreteKind', $bindingsTemplate);
        $this->assertStringContainsString('MERGE (a:Abstract {name: row.abstract})', $bindingsTemplate);
        $this->assertStringContainsString('MERGE (c:Abstract {name: row.concrete})', $bindingsTemplate);
        $this->assertStringContainsString('SET a:AbstractType', $bindingsTemplate);
        $this->assertStringContainsString('r.type = row.type', $bindingsTemplate);
        $this->assertStringNotContainsString('MERGE (:Interface:Abstract', $bindingsTemplate);
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
        $this->assertStringContainsString('d.source = row.source', $dependsOnTemplate);
        $this->assertStringContainsString('d.confidence = row.confidence', $dependsOnTemplate);
        $this->assertStringContainsString('d.provenance = row.provenance', $dependsOnTemplate);
        $this->assertStringContainsString('d.remarks = coalesce(row.remarks', $dependsOnTemplate);
        $this->assertStringContainsString(':Instance', $dependsOnTemplate);
        $this->assertStringContainsString(':Dependency', $dependsOnTemplate);
    }

    public function test_bindings_cypher_sets_edge_metadata(): void
    {
        $writer = new ContainerGraphWriter(new UnusedContainerGraphConnection);
        $bindingsTemplate = $writer->cypherTemplates()['bindings'];

        $this->assertStringContainsString('r.source = row.source', $bindingsTemplate);
        $this->assertStringContainsString('r.confidence = row.confidence', $bindingsTemplate);
        $this->assertStringContainsString('r.provenance = row.provenance', $bindingsTemplate);
        $this->assertStringContainsString('r.remarks = coalesce(row.remarks', $bindingsTemplate);
    }

    public function test_identified_as_cypher_links_dependency_to_abstract(): void
    {
        $writer = new ContainerGraphWriter(new UnusedContainerGraphConnection);
        $template = $writer->cypherTemplates()['identified_as'];

        $this->assertStringContainsString('IDENTIFIED_AS', $template);
        $this->assertStringContainsString('dep.access = row.access', $template);
        $this->assertStringContainsString('MERGE (id:Abstract {name: row.identifier})', $template);
        $this->assertStringContainsString(':Dependency', $template);
        $this->assertStringNotContainsString(':Identifier', $template);
        $this->assertStringNotContainsString('MERGE (:Interface:Abstract', $template);
    }

    public function test_abstract_resolves_to_cypher_sets_lifetime(): void
    {
        $writer = new ContainerGraphWriter(new UnusedContainerGraphConnection);
        $template = $writer->cypherTemplates()['abstract_resolves_to'];

        $this->assertStringContainsString('RESOLVES_TO', $template);
        $this->assertStringContainsString('r.lifetime = row.lifetime', $template);
        $this->assertStringContainsString(':Abstract', $template);
        $this->assertStringContainsString(':Instance', $template);
        $this->assertStringNotContainsString(':Identifier', $template);
    }

    public function test_routes_cypher_uses_handled_by(): void
    {
        $writer = new ContainerGraphWriter(new UnusedContainerGraphConnection);
        $template = $writer->cypherTemplates()['routes'];

        $this->assertStringContainsString(':Route', $template);
        $this->assertStringContainsString('HANDLED_BY', $template);
        $this->assertStringContainsString(':Abstract', $template);
        $this->assertStringContainsString('REMOVE r.route_name', $template);
        $this->assertStringNotContainsString('r.route_name = row.route_name', $template);
        $this->assertStringNotContainsString(':Identifier', $template);
    }

    public function test_route_middleware_cypher_uses_middleware_and_identified_as(): void
    {
        $writer = new ContainerGraphWriter(new UnusedContainerGraphConnection);
        $template = $writer->cypherTemplates()['route_middleware'];

        $this->assertStringContainsString(':Route', $template);
        $this->assertStringContainsString(':Middleware', $template);
        $this->assertStringContainsString('USES_MIDDLEWARE', $template);
        $this->assertStringContainsString('IDENTIFIED_AS', $template);
        $this->assertStringContainsString(':Abstract', $template);
        $this->assertStringContainsString('m.name = row.middleware_key', $template);
        $this->assertStringNotContainsString(':Identifier', $template);
        $this->assertStringContainsString('u.parameters = coalesce(row.parameters', $template);
        $this->assertStringContainsString('order: row.order', $template);
    }

    public function test_events_cypher_uses_handled_by(): void
    {
        $writer = new ContainerGraphWriter(new UnusedContainerGraphConnection);
        $template = $writer->cypherTemplates()['events'];

        $this->assertStringContainsString(':Event', $template);
        $this->assertStringContainsString('HANDLED_BY', $template);
        $this->assertStringContainsString('MERGE (id:Abstract {name: row.identifier})', $template);
        $this->assertStringContainsString('e.name = row.name', $template);
        $this->assertStringContainsString('h.action = row.action', $template);
        $this->assertStringNotContainsString(':Identifier', $template);
    }

    public function test_jobs_cypher_uses_handled_by_and_optional_connection(): void
    {
        $writer = new ContainerGraphWriter(new UnusedContainerGraphConnection);
        $template = $writer->cypherTemplates()['jobs'];

        $this->assertStringContainsString(':Job', $template);
        $this->assertStringContainsString('HANDLED_BY', $template);
        $this->assertStringContainsString('USES_CONNECTION', $template);
        $this->assertStringContainsString('OPTIONAL MATCH (j)-[old:USES_CONNECTION]->()', $template);
        $this->assertStringContainsString('DELETE old', $template);
        $this->assertStringContainsString('MERGE (id:Abstract {name: row.identifier})', $template);
        $this->assertStringContainsString('h.action = row.action', $template);
        $this->assertStringNotContainsString(':Identifier', $template);
    }

    public function test_job_uses_connection_edges_are_replaced_on_rerun(): void
    {
        $connection = new TrackingContainerGraphConnection;
        $writer = new ContainerGraphWriter($connection);

        $jobRow = static fn (string $queueConnection): array => [
            'key' => 'App\\Jobs\\ExampleJob',
            'name' => 'ExampleJob',
            'action' => 'App\\Jobs\\ExampleJob@handle',
            'identifier' => 'App\\Jobs\\ExampleJob',
            'identifier_kind' => 'Class',
            'should_queue' => true,
            'connection' => $queueConnection,
            'queue' => 'default',
            'unique' => false,
        ];

        $queueRows = [
            ['key' => 'redis', 'driver' => 'redis', 'default_queue' => 'default', 'is_default' => false],
            ['key' => 'sqs', 'driver' => 'sqs', 'default_queue' => 'default', 'is_default' => false],
        ];

        $writer->write([], [], [], [], [], [], [], [$jobRow('redis')], $queueRows);
        $this->assertSame(['redis'], $connection->usesConnectionsFor('App\\Jobs\\ExampleJob'));

        $writer->write([], [], [], [], [], [], [], [$jobRow('sqs')], $queueRows);
        $this->assertSame(['sqs'], $connection->usesConnectionsFor('App\\Jobs\\ExampleJob'));

        $writer->write([], [], [], [], [], [], [], [$jobRow('')], $queueRows);
        $this->assertSame([], $connection->usesConnectionsFor('App\\Jobs\\ExampleJob'));
    }

    public function test_queue_connections_cypher_sets_driver_metadata(): void
    {
        $writer = new ContainerGraphWriter(new UnusedContainerGraphConnection);
        $template = $writer->cypherTemplates()['queue_connections'];

        $this->assertStringContainsString(':QueueConnection', $template);
        $this->assertStringContainsString('q.driver = row.driver', $template);
        $this->assertStringContainsString('q.is_default = row.is_default', $template);
    }

    public function test_scheduled_tasks_cypher_merges_task_and_optional_handled_by(): void
    {
        $writer = new ContainerGraphWriter(new UnusedContainerGraphConnection);
        $template = $writer->cypherTemplates()['scheduled_tasks'];

        $this->assertStringContainsString('MERGE (t:ScheduledTask {key: row.key})', $template);
        $this->assertStringContainsString('t.expression = row.expression', $template);
        $this->assertStringContainsString('t.kind = row.kind', $template);
        $this->assertStringContainsString('WHERE row.identifier <> \'\'', $template);
        $this->assertStringContainsString('HANDLED_BY', $template);
        $this->assertStringContainsString('h.action = row.action', $template);
        $this->assertStringContainsString(':Abstract', $template);
    }

    public function test_contextual_binds_cypher_sets_needs_and_give_metadata(): void
    {
        $writer = new ContainerGraphWriter(new UnusedContainerGraphConnection);
        $contextualTemplate = $writer->cypherTemplates()['contextual_binds'];

        $this->assertStringContainsString('CONTEXTUAL_BINDS', $contextualTemplate);
        $this->assertStringContainsString('r.needs = row.needs', $contextualTemplate);
        $this->assertStringContainsString('r.needs_kind = row.needs_kind', $contextualTemplate);
        $this->assertStringContainsString(':Instance', $contextualTemplate);
        $this->assertStringContainsString(':Abstract', $contextualTemplate);
        $this->assertStringNotContainsString(':Identifier', $contextualTemplate);
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
