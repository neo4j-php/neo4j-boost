<?php

namespace Neo4j\LaravelBoost\Console;

use Illuminate\Console\Command;
use Neo4j\LaravelBoost\Support\Neo4jMcpConfig;
use Neo4j\LaravelBoost\Support\Neo4jMcpInstaller;

class SetupCommand extends Command
{
    protected $signature = 'neo4j-boost:setup
                            {--install-mcp : Install the Neo4j MCP binary without prompting}
                            {--skip-mcp : Skip Neo4j MCP binary installation}
                            {--no-cursor-config : Skip running neo4j-boost:cursor-config}';

    protected $description = 'Interactive setup for Neo4j Laravel Boost (STDIO binary + Cursor config). Use -n/--no-interaction for manual steps only.';

    public function handle(): int
    {
        $this->printProxyModelDescription();

        if ($this->option('no-interaction')) {
            $this->printManualInstructions();

            return self::SUCCESS;
        }

        $installer = new Neo4jMcpInstaller;
        $firstSetupRun = $this->isFirstSetupRun();

        if ($this->shouldInstallMcpBinary()) {
            $exitCode = $this->call('neo4j-boost:install-mcp', [
                '--no-cursor-config' => true,
            ]);

            if ($exitCode !== self::SUCCESS) {
                return $exitCode;
            }
        }

        if ($firstSetupRun) {
            $this->newLine();
            $this->components->info('First-time setup detected. Attempting to start local Neo4j...');
            $startNeo4jExitCode = $this->call('neo4j-boost:start-neo4j');

            if ($startNeo4jExitCode === self::SUCCESS) {
                $this->markSetupCompleted();
            } else {
                $this->components->warn('Could not auto-start Neo4j. Run php artisan neo4j-boost:start-neo4j after reviewing your Docker setup.');
            }
        }

        if (! $this->option('no-cursor-config')) {
            $this->newLine();
            $exitCode = $this->call('neo4j-boost:cursor-config');
            if ($exitCode !== self::SUCCESS) {
                return $exitCode;
            }
        }

        $this->newLine();

        if ($installer->isInstalled() && Neo4jMcpConfig::hasNeo4jPassword()) {
            $this->components->info('Neo4j Laravel Boost setup complete. STDIO transport is ready with the local neo4j-mcp binary.');

            return self::SUCCESS;
        }

        if ($installer->isInstalled() && ! Neo4jMcpConfig::hasNeo4jPassword()) {
            $this->components->warn(Neo4jMcpConfig::stdioPasswordRequiredMessage());

            return self::SUCCESS;
        }

        $this->components->warn('Setup finished, but neo4j-mcp binary is not installed. Run php artisan neo4j-boost:install-mcp or re-run setup.');

        return self::SUCCESS;
    }

    protected function printProxyModelDescription(): void
    {
        $this->newLine();
        $this->components->info('Neo4j Laravel Boost setup');
        $this->line('Default proxy path: <fg=cyan>Boost MCP -> STDIO -> neo4j-mcp binary</>');
        $this->line('STDIO is the default transport, so installing the binary is enough for local setup.');
        $this->newLine();
        $this->line('Add these lines to your <fg=cyan>.env</> (reminder):');
        $this->line('  <fg=gray>NEO4J_TRANSPORT_MODE=stdio</>');
        $this->line('  <fg=gray>NEO4J_URI=bolt://localhost:7687</>');
        $this->line('  <fg=gray>NEO4J_USERNAME=neo4j</>');
        $this->line('  <fg=gray>NEO4J_PASSWORD=password</>');
        $this->newLine();
    }

    protected function printManualInstructions(): void
    {
        $this->components->warn('Non-interactive mode: run these steps manually.');
        $this->newLine();
        $this->line('  1. Install the Neo4j MCP binary:');
        $this->line('     <fg=gray>php artisan neo4j-boost:install-mcp</>');
        $this->newLine();
        $this->line('  2. Start local Neo4j:');
        $this->line('     <fg=gray>php artisan neo4j-boost:start-neo4j</>');
        $this->newLine();
        $this->line('  3. Ensure .env contains:');
        $this->line('     <fg=gray>NEO4J_TRANSPORT_MODE=stdio</>');
        $this->line('     <fg=gray>NEO4J_URI=bolt://localhost:7687</>');
        $this->line('     <fg=gray>NEO4J_USERNAME=neo4j</>');
        $this->line('     <fg=gray>NEO4J_PASSWORD=password</>');
        $this->newLine();
        $this->line('  4. Configure Cursor MCP:');
        $this->line('     <fg=gray>php artisan neo4j-boost:cursor-config</>');
        $this->newLine();
    }

    protected function shouldInstallMcpBinary(): bool
    {
        if ($this->option('skip-mcp')) {
            return false;
        }

        if ($this->option('install-mcp')) {
            return true;
        }

        if (! $this->canPromptInteractively()) {
            return false;
        }

        return $this->confirm(
            'Install the official Neo4j MCP server binary for this project?',
            true
        );
    }

    protected function canPromptInteractively(): bool
    {
        return $this->input->isInteractive();
    }

    private function isFirstSetupRun(): bool
    {
        return ! is_file($this->setupMarkerPath());
    }

    private function markSetupCompleted(): void
    {
        $markerPath = $this->setupMarkerPath();
        $markerDirectory = dirname($markerPath);

        if (! is_dir($markerDirectory)) {
            @mkdir($markerDirectory, 0755, true);
        }

        @file_put_contents($markerPath, (string) time());
    }

    private function setupMarkerPath(): string
    {
        return storage_path('app/neo4j-mcp/.setup-complete');
    }
}
