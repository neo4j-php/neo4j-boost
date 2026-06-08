<?php

namespace Neo4j\LaravelBoost\Console;

use Illuminate\Console\Command;
use Neo4j\LaravelBoost\Support\Neo4jMcpInstaller;
use RuntimeException;

class InstallMcpCommand extends Command
{
    protected $signature = 'neo4j-boost:install-mcp
                            {--force : Re-download and replace the Neo4j MCP binary even if already installed}
                            {--no-cursor-config : Skip running neo4j-boost:cursor-config after a successful install}';

    protected $description = 'Download and install the official Neo4j MCP binary from GitHub releases';

    public function handle(): int
    {
        $installer = new Neo4jMcpInstaller;
        $force = (bool) $this->option('force');
        $binaryPath = $installer->getBinaryPath();
        $version = (string) config('neo4j-boost.neo4j_mcp.version', 'v1.4.0');

        $this->components->info('Neo4j MCP binary installer');
        $this->line("  Version: <fg=cyan>{$version}</>");
        $this->line("  Target:  <fg=cyan>{$binaryPath}</>");

        $installSucceeded = false;

        if ($installer->isInstalled() && ! $force) {
            $this->newLine();
            $this->components->info('Neo4j MCP binary is already installed.');
            $installSucceeded = true;
        } else {
            $downloadUrl = $installer->getDownloadUrl();
            if ($downloadUrl !== null) {
                $this->line("  Source:  <fg=gray>{$downloadUrl}</>");
            }

            $this->newLine();

            $installError = null;

            $this->components->task(
                'Downloading and installing Neo4j MCP binary',
                function () use ($installer, $force, &$installError): bool {
                    try {
                        $installer->install($force);

                        return $installer->isInstalled();
                    } catch (RuntimeException $exception) {
                        $installError = $exception->getMessage();

                        return false;
                    }
                }
            );

            if ($installError !== null) {
                $this->newLine();
                $this->components->error($installError);

                return self::FAILURE;
            }

            $installSucceeded = $installer->isInstalled();
        }

        if (! $installSucceeded) {
            $this->newLine();
            $this->components->error('Installation did not complete successfully.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info('Neo4j MCP binary ready at: '.$binaryPath);

        if (! $this->option('no-cursor-config')) {
            $this->newLine();
            $this->call('neo4j-boost:cursor-config');
        }

        return self::SUCCESS;
    }
}
