<?php

namespace Neo4j\LaravelBoost\Tests\Unit;

use Illuminate\Support\Facades\Http;
use Neo4j\LaravelBoost\Support\Neo4jMcpInstaller;
use Neo4j\LaravelBoost\Tests\TestCase;

class Neo4jMcpInstallerTest extends TestCase
{
    protected function tearDown(): void
    {
        $storageDir = storage_path('app/neo4j-mcp');
        if (is_dir($storageDir)) {
            $this->removeDirectory($storageDir);
        }

        parent::tearDown();
    }

    public function test_install_downloads_binary_with_mocked_http_and_marks_installed(): void
    {
        config([
            'neo4j-boost.neo4j_mcp.version' => 'v1.4.0',
            'neo4j-boost.neo4j_mcp.platform_asset' => 'Linux_x86_64',
            'neo4j-boost.neo4j_mcp.binary_path' => storage_path('app/neo4j-mcp/neo4j-mcp'),
        ]);

        Http::fake([
            'https://github.com/neo4j/mcp/releases/download/v1.4.0/neo4j-mcp_Linux_x86_64.tar.gz' => Http::response(
                'fake-archive-bytes',
                200
            ),
        ]);

        $installer = new class extends Neo4jMcpInstaller
        {
            protected function extractTarGz(string $archivePath, string $destinationDirectory): void
            {
                @mkdir($destinationDirectory, 0755, true);
                file_put_contents($destinationDirectory.'/neo4j-mcp', '#!/bin/sh'.PHP_EOL.'echo neo4j-mcp');
            }

            protected function extractZip(string $archivePath, string $destinationDirectory): void
            {
                @mkdir($destinationDirectory, 0755, true);
                file_put_contents($destinationDirectory.'/neo4j-mcp.exe', 'neo4j-mcp');
            }
        };

        $installer->install();

        $this->assertTrue($installer->isInstalled());

        Http::assertSentCount(1);
    }

    public function test_install_skips_http_when_binary_exists_and_force_is_false(): void
    {
        $binaryPath = storage_path('app/neo4j-mcp/neo4j-mcp');
        @mkdir(dirname($binaryPath), 0755, true);
        file_put_contents($binaryPath, 'existing-binary');
        @chmod($binaryPath, 0755);

        config([
            'neo4j-boost.neo4j_mcp.binary_path' => $binaryPath,
            'neo4j-boost.neo4j_mcp.platform_asset' => 'Linux_x86_64',
        ]);

        Http::fake();

        $installer = new Neo4jMcpInstaller;
        $installer->install(false);

        Http::assertNothingSent();
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
