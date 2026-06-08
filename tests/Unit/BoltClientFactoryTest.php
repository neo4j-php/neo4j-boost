<?php

namespace Neo4j\LaravelBoost\Tests\Unit;

use Neo4j\LaravelBoost\Support\Neo4jBoltClient;
use Neo4j\LaravelBoost\Tests\TestCase;
use RuntimeException;

class BoltClientFactoryTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->app->make(Neo4jBoltClient::class)->reset();
        $this->app->forgetInstance(Neo4jBoltClient::class);

        parent::tearDown();
    }

    public function test_missing_uri_throws_actionable_error(): void
    {
        config([
            'neo4j-boost.bolt.uri' => '',
            'neo4j-boost.container_graph.uri' => '',
            'neo4j-boost.container_graph.default_connection_dsn' => '',
        ]);

        putenv('NEO4J_URI');
        putenv('NEO4J_DEFAULT_CONNECTION_DSN');
        unset($_ENV['NEO4J_URI'], $_SERVER['NEO4J_URI']);
        unset($_ENV['NEO4J_DEFAULT_CONNECTION_DSN'], $_SERVER['NEO4J_DEFAULT_CONNECTION_DSN']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Neo4j driver transport requires NEO4J_URI');

        $this->app->make(Neo4jBoltClient::class)->mcpDriverClient();
    }
}
