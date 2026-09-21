<?php

namespace Neo4j\LaravelBoost\Tests\Unit\Support\Graph;

use Neo4j\LaravelBoost\Support\Graph\RuntimeGraphModel;
use PHPUnit\Framework\TestCase;

class RuntimeGraphModelTest extends TestCase
{
    public function test_constraint_statements_cover_all_node_labels(): void
    {
        $statements = RuntimeGraphModel::constraintStatements();

        $this->assertCount(14, $statements);
        $this->assertStringContainsString(':Route', implode("\n", $statements));
        $this->assertStringContainsString(':Event', implode("\n", $statements));
        $this->assertStringContainsString(':Job', implode("\n", $statements));
        $this->assertStringContainsString(':QueueConnection', implode("\n", $statements));
        $this->assertStringContainsString(':ScheduledTask', implode("\n", $statements));
        $this->assertStringContainsString(':AuthGuard', implode("\n", $statements));
        $this->assertStringContainsString(':AuthProvider', implode("\n", $statements));
        $this->assertStringContainsString(':PasswordBroker', implode("\n", $statements));
        $this->assertStringContainsString(':Mailer', implode("\n", $statements));
        $this->assertStringContainsString(':Mailable', implode("\n", $statements));
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

    public function test_scheduled_task_traversal_cypher_uses_runtime_relationships(): void
    {
        $cypher = RuntimeGraphModel::scheduledTaskTraversalCypher();

        $this->assertStringContainsString(':ScheduledTask', $cypher);
        $this->assertStringContainsString('HANDLED_BY', $cypher);
        $this->assertStringContainsString('RESOLVES_TO', $cypher);
        $this->assertStringContainsString('DEPENDS_ON', $cypher);
        $this->assertStringContainsString(':Abstract', $cypher);
    }

    public function test_auth_guard_traversal_cypher_uses_runtime_relationships(): void
    {
        $cypher = RuntimeGraphModel::authGuardTraversalCypher();

        $this->assertStringContainsString(':AuthGuard', $cypher);
        $this->assertStringContainsString(':AuthProvider', $cypher);
        $this->assertStringContainsString('USES_PROVIDER', $cypher);
        $this->assertStringContainsString('USES_MODEL', $cypher);
        $this->assertStringContainsString(':Abstract', $cypher);
        $this->assertStringContainsString('RESOLVES_TO', $cypher);
    }

    public function test_mailable_traversal_cypher_uses_runtime_relationships(): void
    {
        $cypher = RuntimeGraphModel::mailableTraversalCypher();

        $this->assertStringContainsString(':Mailable', $cypher);
        $this->assertStringContainsString('HANDLED_BY', $cypher);
        $this->assertStringContainsString('RESOLVES_TO', $cypher);
        $this->assertStringContainsString('USES_MAILER', $cypher);
        $this->assertStringContainsString(':Mailer', $cypher);
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
        $this->assertSame('USES_PROVIDER', RuntimeGraphModel::REL_USES_PROVIDER);
        $this->assertSame('USES_MODEL', RuntimeGraphModel::REL_USES_MODEL);
        $this->assertSame('USES_MAILER', RuntimeGraphModel::REL_USES_MAILER);
        $this->assertSame('Middleware', RuntimeGraphModel::LABEL_MIDDLEWARE);
        $this->assertSame('Event', RuntimeGraphModel::LABEL_EVENT);
        $this->assertSame('Job', RuntimeGraphModel::LABEL_JOB);
        $this->assertSame('QueueConnection', RuntimeGraphModel::LABEL_QUEUE_CONNECTION);
        $this->assertSame('ScheduledTask', RuntimeGraphModel::LABEL_SCHEDULED_TASK);
        $this->assertSame('AuthGuard', RuntimeGraphModel::LABEL_AUTH_GUARD);
        $this->assertSame('AuthProvider', RuntimeGraphModel::LABEL_AUTH_PROVIDER);
        $this->assertSame('PasswordBroker', RuntimeGraphModel::LABEL_PASSWORD_BROKER);
        $this->assertSame('Mailer', RuntimeGraphModel::LABEL_MAILER);
        $this->assertSame('Mailable', RuntimeGraphModel::LABEL_MAILABLE);
        $this->assertSame('Abstract', RuntimeGraphModel::LABEL_ABSTRACT);
    }
}
