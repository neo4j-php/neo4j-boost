<?php

namespace Neo4j\LaravelBoost\Console;

use Illuminate\Console\Command;
use Neo4j\LaravelBoost\Support\Neo4jMcpConfig;
use Symfony\Component\Process\Process;

class StartNeo4jCommand extends Command
{
    protected $signature = 'neo4j-boost:start-neo4j
                            {--container=neo4j-boost-local : Docker container name}
                            {--image=neo4j:5-community : Docker image to run}
                            {--bolt-port=7687 : Host Bolt port}
                            {--http-port=7474 : Host HTTP port}
                            {--recreate : Recreate container to apply required plugins/env}
                            {--password= : Neo4j password (defaults to NEO4J_PASSWORD)}';

    protected $description = 'Start a local Neo4j Docker container for STDIO mode';

    public function handle(): int
    {
        $container = (string) $this->option('container');
        $image = (string) $this->option('image');
        $boltPort = (string) $this->option('bolt-port');
        $httpPort = (string) $this->option('http-port');
        $recreate = (bool) $this->option('recreate');
        $password = $this->resolvedPassword();

        if (! $this->dockerIsAvailable()) {
            $this->components->error('Docker is not available. Install/start Docker, then run this command again.');

            return self::FAILURE;
        }

        if ($password === null) {
            $this->components->error(Neo4jMcpConfig::stdioPasswordRequiredMessage());
            $this->line('Set NEO4J_PASSWORD in your .env, or pass --password=...');

            return self::FAILURE;
        }

        $status = $this->containerStatus($container);
        if ($status !== null && $this->containerMissingRequiredPlugins($container)) {
            if (! $recreate) {
                $this->components->error('Existing Neo4j container is missing required APOC plugin settings.');
                $this->line('Re-run with --recreate to recreate the container with required APOC settings.');

                return self::FAILURE;
            }

            $removed = $this->removeContainer($container);
            if (! $removed) {
                return self::FAILURE;
            }
            $status = null;
        }

        if ($status === 'running') {
            $this->components->info('Neo4j container is already running: '.$container);
            $this->line('Bolt: bolt://localhost:'.$boltPort);

            return self::SUCCESS;
        }

        if ($status === 'stopped') {
            if ($recreate) {
                $removed = $this->removeContainer($container);
                if (! $removed) {
                    return self::FAILURE;
                }

                return $this->runNewContainer($container, $image, $boltPort, $httpPort, $password);
            }

            return $this->startExistingContainer($container, $boltPort);
        }

        return $this->runNewContainer($container, $image, $boltPort, $httpPort, $password);
    }

    private function resolvedPassword(): ?string
    {
        $passwordOption = $this->option('password');
        if (is_string($passwordOption) && trim($passwordOption) !== '') {
            return trim($passwordOption);
        }

        return Neo4jMcpConfig::neo4jPassword();
    }

    private function dockerIsAvailable(): bool
    {
        $process = new Process(['docker', '--version']);
        $process->run();

        return $process->isSuccessful();
    }

    private function containerStatus(string $container): ?string
    {
        $process = new Process([
            'docker',
            'ps',
            '-a',
            '--filter',
            'name=^/'.$container.'$',
            '--format',
            '{{.Status}}',
        ]);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        $output = trim($process->getOutput());
        if ($output === '') {
            return null;
        }

        return str_starts_with($output, 'Up') ? 'running' : 'stopped';
    }

    private function startExistingContainer(string $container, string $boltPort): int
    {
        $process = new Process(['docker', 'start', $container]);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->components->error('Failed to start existing Neo4j container.');
            $this->line(trim($process->getErrorOutput()) ?: trim($process->getOutput()));

            return self::FAILURE;
        }

        $this->components->info('Started existing Neo4j container: '.$container);
        $this->line('Bolt: bolt://localhost:'.$boltPort);

        return self::SUCCESS;
    }

    private function runNewContainer(
        string $container,
        string $image,
        string $boltPort,
        string $httpPort,
        string $password
    ): int {
        $process = new Process([
            'docker',
            'run',
            '-d',
            '--name',
            $container,
            '-p',
            $httpPort.':7474',
            '-p',
            $boltPort.':7687',
            '-e',
            'NEO4J_AUTH=neo4j/'.$password,
            '-e',
            'NEO4J_PLUGINS=["apoc"]',
            '-e',
            'NEO4J_dbms_security_procedures_unrestricted=apoc.*',
            '-e',
            'NEO4J_dbms_security_procedures_allowlist=apoc.*',
            $image,
        ]);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->components->error('Failed to run Neo4j container.');
            $this->line(trim($process->getErrorOutput()) ?: trim($process->getOutput()));

            return self::FAILURE;
        }

        $this->components->info('Started Neo4j container: '.$container);
        $this->line('Bolt: bolt://localhost:'.$boltPort);
        $this->line('HTTP: http://localhost:'.$httpPort);

        return self::SUCCESS;
    }

    private function containerMissingRequiredPlugins(string $container): bool
    {
        $process = new Process([
            'docker',
            'inspect',
            '--format',
            '{{json .Config.Env}}',
            $container,
        ]);
        $process->run();

        if (! $process->isSuccessful()) {
            return false;
        }

        $decoded = json_decode(trim($process->getOutput()), true);
        if (! is_array($decoded)) {
            return false;
        }

        foreach ($decoded as $entry) {
            if (! is_string($entry)) {
                continue;
            }
            if (str_starts_with($entry, 'NEO4J_PLUGINS=') && str_contains($entry, 'apoc')) {
                return false;
            }
        }

        return true;
    }

    private function removeContainer(string $container): bool
    {
        $process = new Process(['docker', 'rm', '-f', $container]);
        $process->run();

        if ($process->isSuccessful()) {
            $this->components->info('Removed container for recreation: '.$container);

            return true;
        }

        $this->components->error('Failed to remove existing container: '.$container);
        $this->line(trim($process->getErrorOutput()) ?: trim($process->getOutput()));

        return false;
    }
}
