<?php

namespace Neo4j\LaravelBoost\Tests\Integration;

use Neo4j\LaravelBoost\Boost\Tools\GetSchemaTool;
use Neo4j\LaravelBoost\Contracts\Neo4jMcpClientInterface;
use Neo4j\LaravelBoost\Neo4jHttpClient;
use Neo4j\LaravelBoost\Neo4jStdioClient;
use Neo4j\LaravelBoost\Support\ContainerGraphConnection;
use Neo4j\LaravelBoost\Support\Neo4jBoltClient;
use Neo4j\LaravelBoost\Tests\TestCase;

class Neo4jBoostServiceProviderTest extends TestCase
{
    public function test_resolves_neo4j_mcp_client_as_stdio_by_default(): void
    {
        $client = $this->app->make(Neo4jMcpClientInterface::class);

        $this->assertInstanceOf(Neo4jStdioClient::class, $client);
    }

    public function test_resolves_neo4j_mcp_client_as_http_when_transport_is_http(): void
    {
        config(['neo4j-boost.neo4j_mcp.transport' => 'http']);

        $this->app->forgetInstance(Neo4jMcpClientInterface::class);
        $client = $this->app->make(Neo4jMcpClientInterface::class);

        $this->assertInstanceOf(Neo4jHttpClient::class, $client);
    }

    public function test_resolves_neo4j_bolt_client_and_connection_as_singletons(): void
    {
        $bolt = $this->app->make(Neo4jBoltClient::class);
        $connection = $this->app->make(ContainerGraphConnection::class);

        $this->assertSame($bolt, $this->app->make(Neo4jBoltClient::class));
        $this->assertSame($connection, $this->app->make(ContainerGraphConnection::class));
    }

    public function test_merges_neo4j_tools_into_boost_include_when_config_empty(): void
    {
        $include = config('boost.mcp.tools.include', []);

        $this->assertContains(
            GetSchemaTool::class,
            $include
        );
    }
}
