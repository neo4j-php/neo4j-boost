<?php

namespace Neo4j\LaravelBoost\Tests\Unit\Support\Graph;

use Neo4j\LaravelBoost\Support\Graph\RuntimeGraphModel;
use PHPUnit\Framework\TestCase;

class RuntimeGraphModelTest extends TestCase
{
    public function test_constraint_statements_cover_all_node_labels(): void
    {
        $statements = RuntimeGraphModel::constraintStatements();

        $this->assertCount(8, $statements);
        $this->assertStringContainsString(':Route', implode("\n", $statements));
        $this->assertStringContainsString(':Event', implode("\n", $statements));
        $this->assertStringContainsString(':Job', implode("\n", $statements));
        $this->assertStringContainsString(':QueueConnection', implode("\n", $statements));
        $this->assertStringContainsString(':Instance', implode("\n", $statements));
        $this->assertStringContainsString(':Dependency', implode("\n", $statements));
        $this->assertStringContainsString(':Abstract', implode("\n", $statements));
        $this->assertStringContainsString(':Middleware', implode("\n", $statements));
        $this->assertStringNotContainsString(':Identifier', implode("\n", $statements));
        $this->assertTrue(array_reduce(
            $statements,
            static fn (bool $carry, string $cypher): bool => $carry && str_contains($cypher, 'IF NOT EXISTS'),
            true,
        ));
    }

    public function test_route_traversal_cypher_uses_runtime_relationships(): void
    {
        $cypher = RuntimeGraphModel::routeTraversalCypher();

        $this->assertStringContainsString('HANDLED_BY', $cypher);
        $this->assertStringContainsString('RESOLVES_TO', $cypher);
        $this->assertStringContainsString('DEPENDS_ON', $cypher);
        $this->assertStringContainsString('IDENTIFIED_AS', $cypher);
        $this->assertStringContainsString('USES_MIDDLEWARE', $cypher);
        $this->assertStringContainsString(':Route', $cypher);
        $this->assertStringContainsString(':Instance', $cypher);
        $this->assertStringContainsString(':Middleware', $cypher);
        $this->assertStringContainsString(':Abstract', $cypher);
        $this->assertStringNotContainsString(':Identifier', $cypher);
    }

    public function test_event_traversal_cypher_uses_runtime_relationships(): void
    {
        $cypher = RuntimeGraphModel::eventTraversalCypher();

        $this->assertStringContainsString(':Event', $cypher);
        $this->assertStringContainsString('HANDLED_BY', $cypher);
        $this->assertStringContainsString('RESOLVES_TO', $cypher);
        $this->assertStringContainsString('DEPENDS_ON', $cypher);
        $this->assertStringContainsString(':Abstract', $cypher);
    }

    public function test_job_traversal_cypher_uses_runtime_relationships(): void
    {
        $cypher = RuntimeGraphModel::jobTraversalCypher();

        $this->assertStringContainsString(':Job', $cypher);
        $this->assertStringContainsString('HANDLED_BY', $cypher);
        $this->assertStringContainsString('RESOLVES_TO', $cypher);
        $this->assertStringContainsString('USES_CONNECTION', $cypher);
        $this->assertStringContainsString(':QueueConnection', $cypher);
        $this->assertStringContainsString(':Abstract', $cypher);
    }

    public function test_relationship_constants_match_acceptance_model(): void
    {
        $this->assertSame('HANDLED_BY', RuntimeGraphModel::REL_HANDLED_BY);
        $this->assertSame('RESOLVES_TO', RuntimeGraphModel::REL_RESOLVES_TO);
        $this->assertSame('DEPENDS_ON', RuntimeGraphModel::REL_DEPENDS_ON);
        $this->assertSame('IDENTIFIED_AS', RuntimeGraphModel::REL_IDENTIFIED_AS);
        $this->assertSame('USES_MIDDLEWARE', RuntimeGraphModel::REL_USES_MIDDLEWARE);
        $this->assertSame('USES_CONNECTION', RuntimeGraphModel::REL_USES_CONNECTION);
        $this->assertSame('Middleware', RuntimeGraphModel::LABEL_MIDDLEWARE);
        $this->assertSame('Event', RuntimeGraphModel::LABEL_EVENT);
        $this->assertSame('Job', RuntimeGraphModel::LABEL_JOB);
        $this->assertSame('QueueConnection', RuntimeGraphModel::LABEL_QUEUE_CONNECTION);
        $this->assertSame('Abstract', RuntimeGraphModel::LABEL_ABSTRACT);
    }
}
