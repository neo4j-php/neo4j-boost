<?php

namespace Neo4j\LaravelBoost\Tests\Unit;

use Illuminate\Support\Facades\Http;
use Neo4j\LaravelBoost\Support\Neo4jMcpHealth;
use Neo4j\LaravelBoost\Tests\TestCase;

class Neo4jMcpHealthTest extends TestCase
{
    protected function tearDown(): void
    {
        $storageDir = storage_path('app/neo4j-mcp');
        if (is_dir($storageDir)) {
            $this->removeDirectory($storageDir);
        }

        parent::tearDown();
    }

    public function test_diagnose_maps_unhealthy_messages_when_binary_missing_and_server_unreachable(): void
    {
        config([
            'neo4j-boost.neo4j_mcp.binary_path' => storage_path('app/neo4j-mcp/missing-binary'),
            'neo4j-boost.http.url' => 'http://localhost:8080/mcp',
        ]);

        Http::fake([
            'http://localhost:8080/mcp' => Http::failedConnection(),
        ]);

        $health = new Neo4jMcpHealth;
        $diagnosis = $health->diagnose();

        $this->assertSame('unhealthy', $diagnosis['status']);
        $this->assertFalse($diagnosis['binary_installed']);
        $this->assertFalse($diagnosis['server_reachable']);
        $this->assertContains(
            'Install the official binary: php artisan neo4j-boost:install-mcp',
            $diagnosis['suggested_resolutions']
        );
        $this->assertContains(
            'Start neo4j-mcp in HTTP mode and verify NEO4J_MCP_URL points to /mcp.',
            $diagnosis['suggested_resolutions']
        );
    }

    public function test_diagnose_maps_degraded_message_when_server_reachable_but_binary_missing(): void
    {
        config([
            'neo4j-boost.neo4j_mcp.binary_path' => storage_path('app/neo4j-mcp/missing-binary'),
            'neo4j-boost.http.url' => 'http://localhost:8080/mcp',
        ]);

        Http::fake([
            'http://localhost:8080/mcp' => Http::response(['error' => 'bad request'], 400),
        ]);

        $health = new Neo4jMcpHealth;
        $diagnosis = $health->diagnose();

        $this->assertSame('degraded', $diagnosis['status']);
        $this->assertFalse($diagnosis['binary_installed']);
        $this->assertTrue($diagnosis['server_reachable']);
        $this->assertContains(
            'Binary installation is optional when using a remote MCP server URL.',
            $diagnosis['suggested_resolutions']
        );
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }

        @rmdir($directory);
    }
}
