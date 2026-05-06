<?php

namespace Neo4j\LaravelBoost\Tests\Unit;

use Neo4j\LaravelBoost\CursorMcpConfig;
use PHPUnit\Framework\TestCase;

class CursorMcpConfigTest extends TestCase
{
    public function test_get_path_appends_cursor_mcp_json(): void
    {
        $this->assertSame('/tmp/project/.cursor/mcp.json', CursorMcpConfig::getPath('/tmp/project'));
    }
}
