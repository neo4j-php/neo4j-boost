<?php

namespace Neo4j\LaravelBoost\Console;

use Illuminate\Console\Command;
use Neo4j\LaravelBoost\CursorMcpConfig;

class CursorConfigCommand extends Command
{
    protected $signature = 'neo4j-boost:cursor-config';

    protected $description = 'Create or update .cursor/mcp.json with the neo4j-boost MCP server URL (merges with existing servers)';

    public function handle(): int
    {
        if (CursorMcpConfig::writeOrMerge(base_path())) {
            $this->info('Created/updated ' . CursorMcpConfig::getPath(base_path()));
            $this->line('Open this Laravel app folder in Cursor and enable the neo4j-boost MCP server.');
            return self::SUCCESS;
        }
        $this->error('Could not write .cursor/mcp.json.');
        return self::FAILURE;
    }
}
