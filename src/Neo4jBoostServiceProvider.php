<?php

namespace Neo4j\LaravelBoost;

use Illuminate\Support\ServiceProvider;
use Neo4j\LaravelBoost\Boost\Tools\GetClassDependencyGraphTool;
use Neo4j\LaravelBoost\Boost\Tools\GetSchemaTool;
use Neo4j\LaravelBoost\Boost\Tools\ListGdsProceduresTool;
use Neo4j\LaravelBoost\Boost\Tools\ReadCypherTool;
use Neo4j\LaravelBoost\Boost\Tools\WriteCypherTool;
use Neo4j\LaravelBoost\Console\ContainerGraphCommand;
use Neo4j\LaravelBoost\Console\CursorConfigCommand;
use Neo4j\LaravelBoost\Console\DoctorCommand;
use Neo4j\LaravelBoost\Console\InstallMcpCommand;
use Neo4j\LaravelBoost\Console\SetupCommand;
use Neo4j\LaravelBoost\Console\StartNeo4jCommand;
use Neo4j\LaravelBoost\Console\TestStdioCommand;
use Neo4j\LaravelBoost\Contracts\BoltExecutorInterface;
use Neo4j\LaravelBoost\Contracts\Neo4jMcpClientInterface;
use Neo4j\LaravelBoost\StaticAnalysis\ServiceLocationEdgeFinder;
use Neo4j\LaravelBoost\Support\ContainerGraphConnection;
use Neo4j\LaravelBoost\Support\Neo4jBoltClient;

class Neo4jBoostServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/neo4j-boost.php', 'neo4j-boost');

        $this->app->singleton(Neo4jBoltClient::class);
        $this->app->singleton(BoltExecutorInterface::class, Neo4jBoltExecutor::class);

        $this->app->singleton(Neo4jMcpClientInterface::class, function ($app) {
            $driver = strtolower((string) config('neo4j-boost.neo4j_mcp.transport', 'stdio'));

            return match ($driver) {
                'driver' => new Neo4jDriverClient($app->make(BoltExecutorInterface::class)),
                'stdio' => new Neo4jStdioClient,
                default => new Neo4jHttpClient,
            };
        });
        $this->app->singleton(ContainerGraphConnection::class);
        $this->app->singleton(ClassDependencyGraphReader::class);
        $this->app->singleton(ServiceLocationEdgeFinder::class);
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
                DoctorCommand::class,
                InstallMcpCommand::class,
                SetupCommand::class,
                StartNeo4jCommand::class,
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
            GetClassDependencyGraphTool::class,
            ReadCypherTool::class,
            WriteCypherTool::class,
            ListGdsProceduresTool::class,
        ];

        $include = config('boost.mcp.tools.include', []);
        $merged = array_values(array_unique(array_merge($include, $ourTools)));
        config(['boost.mcp.tools.include' => $merged]);
    }
}
