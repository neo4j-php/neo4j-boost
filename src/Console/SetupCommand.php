<?php

namespace Neo4j\LaravelBoost\Console;

use Illuminate\Console\Command;
use Neo4j\LaravelBoost\Support\EnvFileWriter;
use Neo4j\LaravelBoost\Support\Neo4jMcpInstaller;
use Symfony\Component\Process\Process;

class SetupCommand extends Command
{
    protected $signature = 'neo4j-boost:setup
                            {--install-mcp : Install the Neo4j MCP binary without prompting}
                            {--skip-mcp : Skip Neo4j MCP binary installation}
                            {--no-cursor-config : Skip running neo4j-boost:cursor-config}';

    protected $description = 'Interactive setup for Neo4j Laravel Boost (binary, HTTP server, .env, Cursor). Use -n/--no-interaction for manual steps only.';

    public function handle(): int
    {
        $this->printProxyModelDescription();

        if ($this->option('no-interaction')) {
            $this->printManualInstructions();

            return self::SUCCESS;
        }

        if ($this->shouldInstallMcpBinary()) {
            $exitCode = $this->call('neo4j-boost:install-mcp', [
                '--no-cursor-config' => true,
            ]);

            if ($exitCode !== self::SUCCESS) {
                return $exitCode;
            }
        }

        if ($this->shouldStartHttpServer()) {
            $this->startOrShowHttpServerInstructions();
        }

        $envExitCode = $this->ensureNeo4jMcpUrlInEnv();
        if ($envExitCode !== self::SUCCESS) {
            return $envExitCode;
        }

        if (! $this->option('no-cursor-config')) {
            $this->newLine();
            $exitCode = $this->call('neo4j-boost:cursor-config');
            if ($exitCode !== self::SUCCESS) {
                return $exitCode;
            }
        }

        $this->newLine();
        $this->components->info('Neo4j Laravel Boost setup complete.');

        return self::SUCCESS;
    }

    protected function printProxyModelDescription(): void
    {
        $this->newLine();
        $this->components->info('Neo4j Laravel Boost setup');
        $this->line('This package uses a <fg=cyan>proxy model</>:');
        $this->line('  <fg=yellow>Cursor / IDE</> → <fg=cyan>Laravel Boost MCP</> (<fg=gray>php artisan boost:mcp</>) → <fg=cyan>HTTP</> → <fg=cyan>neo4j-mcp</> → <fg=cyan>Neo4j</>');
        $this->newLine();
        $this->line('You run <fg=cyan>neo4j-mcp</> in HTTP mode separately; Boost tools call it at <fg=cyan>NEO4J_MCP_URL</>.');
        $this->newLine();
    }

    protected function printManualInstructions(): void
    {
        $this->components->warn('Non-interactive mode: run these steps manually.');
        $this->newLine();
        $this->line('  1. Install the Neo4j MCP binary:');
        $this->line('     <fg=gray>php artisan neo4j-boost:install-mcp</>');
        $this->newLine();
        $this->line('  2. Start neo4j-mcp in HTTP mode (choose one):');
        $this->line('     <fg=gray>'.$this->localBinaryHttpCommand().'</>');
        $this->newLine();
        $this->line('     Or with Docker:');
        $this->line('     <fg=gray>'.$this->dockerHttpRunCommand().'</>');
        $this->newLine();
        $this->line('  3. Add to your <fg=cyan>.env</>:');
        foreach (EnvFileWriter::neo4jMcpUrlTemplateLines() as $line) {
            if ($line === '') {
                continue;
            }
            $this->line('     <fg=gray>'.$line.'</>');
        }
        $this->newLine();
        $this->line('  4. Configure Cursor MCP:');
        $this->line('     <fg=gray>php artisan neo4j-boost:cursor-config</>');
        $this->newLine();
        $this->line('  5. Open your Laravel app in Cursor and enable the <fg=cyan>laravel-boost</> MCP server.');
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

    protected function shouldStartHttpServer(): bool
    {
        if (! $this->canPromptInteractively()) {
            return false;
        }

        return $this->confirm(
            'Start MCP server in HTTP mode now?',
            true
        );
    }

    protected function canPromptInteractively(): bool
    {
        return $this->input->isInteractive() && stream_isatty(STDIN);
    }

    protected function startOrShowHttpServerInstructions(): void
    {
        $installer = new Neo4jMcpInstaller;

        if ($installer->isInstalled()) {
            $this->components->task(
                'Starting neo4j-mcp in HTTP mode (background)',
                function () use ($installer): bool {
                    return $this->startNeo4jMcpHttpProcess($installer->getBinaryPath());
                }
            );
            $this->line('  MCP endpoint: <fg=cyan>'.config('neo4j-boost.transport.http.url', 'http://localhost:8080/mcp').'</>');
            $this->line('  Stop the process when you are done developing.');

            return;
        }

        $this->components->warn('Neo4j MCP binary is not installed yet.');
        $this->line('  Run locally after install:');
        $this->line('  <fg=gray>'.$this->localBinaryHttpCommand().'</>');
        $this->newLine();
        $this->line('  Or use Docker:');
        $this->line('  <fg=gray>'.$this->dockerHttpRunCommand().'</>');
        $this->newLine();
    }

    protected function startNeo4jMcpHttpProcess(string $binaryPath): bool
    {
        $env = $this->neo4jMcpProcessEnvironment();

        $process = new Process(
            [$binaryPath, '--neo4j-transport-mode', 'http'],
            base_path(),
            $env,
            null,
            null
        );

        $process->start();

        if (! $process->isRunning()) {
            return false;
        }

        $this->line('  Background PID: <fg=cyan>'.$process->getPid().'</>');

        return true;
    }

    /**
     * @return array<string, string>
     */
    protected function neo4jMcpProcessEnvironment(): array
    {
        $fromGetenv = getenv();
        $base = is_array($fromGetenv) ? $fromGetenv : [];
        $configured = config('neo4j-boost.transport.stdio.env', []);
        $overrides = is_array($configured) ? $configured : [];

        return array_merge($base, array_filter(
            $overrides,
            static fn (mixed $value): bool => is_string($value) || is_numeric($value)
        ));
    }

    protected function localBinaryHttpCommand(): string
    {
        $installer = new Neo4jMcpInstaller;
        $binary = $installer->getBinaryPath();

        return 'NEO4J_URI=bolt://localhost:7687 NEO4J_TRANSPORT_MODE=http '.$binary.' --neo4j-transport-mode http';
    }

    protected function dockerHttpRunCommand(): string
    {
        return 'docker run --rm -p 8080:8080 '
            .'-e NEO4J_URI=bolt://host.docker.internal:7687 '
            .'-e NEO4J_TRANSPORT_MODE=http '
            .'docker.io/mcp/neo4j:latest';
    }

    protected function ensureNeo4jMcpUrlInEnv(): int
    {
        $envPath = base_path('.env');
        $examplePath = base_path('.env.example');

        if (! EnvFileWriter::ensureEnvFileExists($envPath, $examplePath)) {
            $this->components->error('No .env file found. Create .env in your project root, then run this command again.');

            return self::FAILURE;
        }

        if (EnvFileWriter::hasNeo4jMcpUrl($envPath)) {
            $this->components->info('NEO4J_MCP_URL is already set in .env.');

            return self::SUCCESS;
        }

        $this->components->warn('NEO4J_MCP_URL is missing from .env.');

        if ($this->canPromptInteractively()) {
            $shouldAppend = $this->confirm(
                'Append Neo4j MCP URL settings from the package template to .env?',
                true
            );

            if (! $shouldAppend) {
                $this->line('Add these lines to .env manually:');
                foreach (EnvFileWriter::neo4jMcpUrlTemplateLines() as $line) {
                    $this->line('  <fg=gray>'.$line.'</>');
                }

                return self::SUCCESS;
            }
        } else {
            if (! EnvFileWriter::appendNeo4jMcpUrlTemplate($envPath)) {
                $this->components->error('Could not append NEO4J_MCP_URL to .env.');

                return self::FAILURE;
            }

            $this->components->info('Appended NEO4J_MCP_URL template to .env.');

            return self::SUCCESS;
        }

        if (! EnvFileWriter::appendNeo4jMcpUrlTemplate($envPath)) {
            $this->components->error('Could not append NEO4J_MCP_URL to .env.');

            return self::FAILURE;
        }

        $this->components->info('Appended NEO4J_MCP_URL template to .env.');

        return self::SUCCESS;
    }
}
