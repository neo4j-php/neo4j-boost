<?php

namespace Neo4j\LaravelBoost\Console;

use Illuminate\Console\Command;
use Neo4j\LaravelBoost\Support\Neo4jMcpConfig;
use Neo4j\LaravelBoost\Support\Neo4jMcpHealth;

class DoctorCommand extends Command
{
    protected $signature = 'neo4j-boost:doctor';

    protected $description = 'Diagnose Neo4j MCP binary/server readiness and offer guided fixes';

    public function handle(): int
    {
        $transport = Neo4jMcpConfig::transport();

        $this->printProxyArchitecture($transport);

        $health = new Neo4jMcpHealth;
        $diagnosis = $health->diagnose();

        $binaryInstalled = (bool) ($diagnosis['binary_installed'] ?? false);
        $serverReachable = (bool) ($diagnosis['server_reachable'] ?? false);
        $httpUrl = (string) ($diagnosis['http_url'] ?? 'http://localhost:8080/mcp');

        $this->components->twoColumnDetail('Transport', $transport);
        $this->components->twoColumnDetail('Neo4j MCP binary', $binaryInstalled ? 'installed' : 'missing');
        $this->components->twoColumnDetail('NEO4J_PASSWORD', Neo4jMcpConfig::hasNeo4jPassword() ? 'set' : 'missing');

        if ($transport === 'stdio') {
            $this->components->twoColumnDetail(
                'STDIO readiness',
                ($binaryInstalled && Neo4jMcpConfig::hasNeo4jPassword()) ? 'ready' : 'not ready'
            );
        } else {
            $this->components->twoColumnDetail('Neo4j MCP HTTP', $serverReachable ? 'reachable' : 'not reachable');
            $this->components->twoColumnDetail('Configured URL', $httpUrl);
        }

        if ($transport === 'stdio' && ! Neo4jMcpConfig::hasNeo4jPassword()) {
            $this->newLine();
            $this->components->error(Neo4jMcpConfig::stdioPasswordRequiredMessage());
        }

        if (! $binaryInstalled) {
            $this->newLine();
            $this->components->warn('Neo4j MCP binary is missing for local STDIO flows.');

            if ($this->canPromptInteractively()) {
                $shouldInstall = $this->confirm('Install the Neo4j MCP server binary now?', true);

                if ($shouldInstall) {
                    return $this->call('neo4j-boost:install-mcp', [
                        '--no-cursor-config' => true,
                    ]);
                }
            }
        }

        foreach ($diagnosis['suggested_resolutions'] ?? [] as $resolution) {
            $this->line('  - '.$resolution);
        }

        return self::SUCCESS;
    }

    private function printProxyArchitecture(string $transport): void
    {
        $this->newLine();
        $this->components->info('Neo4j MCP proxy architecture');

        if ($transport === 'stdio') {
            $this->line('  Cursor / IDE -> Laravel Boost MCP (php artisan boost:mcp) -> STDIO -> neo4j-mcp binary -> Neo4j');
        } else {
            $this->line('  Cursor / IDE -> Laravel Boost MCP (php artisan boost:mcp) -> HTTP -> neo4j-mcp -> Neo4j');
        }

        $this->newLine();
    }

    private function canPromptInteractively(): bool
    {
        return $this->input->isInteractive();
    }
}
