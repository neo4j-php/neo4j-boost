<?php

namespace Neo4j\LaravelBoost\Tests\Integration;

use Neo4j\LaravelBoost\Boost\Tools\GetSchemaTool;
use Neo4j\LaravelBoost\Contracts\Neo4jMcpClientInterface;
use Neo4j\LaravelBoost\Neo4jHttpClient;
use Neo4j\LaravelBoost\Tests\TestCase;

class Neo4jBoostServiceProviderTest extends TestCase
{
    public function test_resolves_neo4j_mcp_client_as_http_by_default(): void
    {
        $client = $this->app->make(Neo4jMcpClientInterface::class);

        $this->assertInstanceOf(Neo4jHttpClient::class, $client);
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
