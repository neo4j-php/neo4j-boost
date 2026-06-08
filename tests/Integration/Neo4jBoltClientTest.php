<?php

namespace Neo4j\LaravelBoost\Tests\Integration;

use Neo4j\LaravelBoost\Support\Neo4jBoltClient;
use Neo4j\LaravelBoost\Tests\TestCase;

class Neo4jBoltClientTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->app->make(Neo4jBoltClient::class)->reset();
        $this->app->forgetInstance(Neo4jBoltClient::class);

        parent::tearDown();
    }

    public function test_container_resolves_process_wide_singleton(): void
    {
        $first = $this->app->make(Neo4jBoltClient::class);
        $second = $this->app->make(Neo4jBoltClient::class);

        $this->assertSame($first, $second);
        $this->assertSame($first->client(), $second->client());
    }
}
