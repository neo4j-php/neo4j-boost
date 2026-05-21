<?php

namespace Neo4j\LaravelBoost;

use Illuminate\Support\ServiceProvider;
use Neo4j\LaravelBoost\Boost\Tools\GetSchemaTool;
use Neo4j\LaravelBoost\Boost\Tools\ListGdsProceduresTool;
use Neo4j\LaravelBoost\Boost\Tools\ReadCypherTool;
use Neo4j\LaravelBoost\Boost\Tools\WriteCypherTool;
use Neo4j\LaravelBoost\Console\ContainerGraphCommand;
use Neo4j\LaravelBoost\Console\CursorConfigCommand;
use Neo4j\LaravelBoost\Console\TestStdioCommand;
use Neo4j\LaravelBoost\Contracts\Neo4jMcpClientInterface;

class Neo4jBoostServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/neo4j-boost.php', 'neo4j-boost');

        $this->app->singleton(Neo4jMcpClientInterface::class, function () {
            $transport = config('neo4j-boost.transport', 'http');
            $driver = is_string($transport) ? $transport : ($transport['driver'] ?? 'http');

            return $driver === 'stdio'
                ? new Neo4jStdioClient
                : new Neo4jHttpClient;
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/neo4j-boost.php' => config_path('neo4j-boost.php'),
        ], 'neo4j-boost-config');

        $this->mergeBoostTools();

        if ($this->app->runningInConsole()) {
            $this->commands([
                ContainerGraphCommand::class,
                CursorConfigCommand::class,
                TestStdioCommand::class,
            ]);
        }
    }

    /**
     * Add Neo4j tools to boost.mcp.tools.include so one MCP server (boost:mcp)
     * exposes both Boost and official Neo4j tools.
     */
    private function mergeBoostTools(): void
    {
        $ourTools = [
            GetSchemaTool::class,
            ReadCypherTool::class,
            WriteCypherTool::class,
            ListGdsProceduresTool::class,
        ];

        $include = config('boost.mcp.tools.include', []);
        $merged = array_values(array_unique(array_merge($include, $ourTools)));
        config(['boost.mcp.tools.include' => $merged]);
    }
}
