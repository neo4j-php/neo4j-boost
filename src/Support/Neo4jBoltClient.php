<?php

namespace Neo4j\LaravelBoost\Support;

use Laudis\Neo4j\Authentication\Authenticate;
use Laudis\Neo4j\ClientBuilder;
use Laudis\Neo4j\Contracts\ClientInterface;

/**
 * Process-wide Neo4j Bolt client for package features (container graph, MCP driver, etc.).
 */
final class Neo4jBoltClient
{
    public const DRIVER_ALIAS = 'neo4j-boost';

    private ?ClientInterface $client = null;

    public function client(): ClientInterface
    {
        if ($this->client !== null) {
            return $this->client;
        }

        [$uri, $user, $password] = $this->resolveConnectionParams();

        $this->client = ClientBuilder::create()
            ->withDriver(self::DRIVER_ALIAS, $uri, Authenticate::basic($user, $password))
            ->withDefaultDriver(self::DRIVER_ALIAS)
            ->build();

        return $this->client;
    }

    public function driverAlias(): string
    {
        return self::DRIVER_ALIAS;
    }

    public function reset(): void
    {
        $this->client = null;
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function resolveConnectionParams(): array
    {
        $explicitUri = trim((string) config('neo4j-boost.container_graph.uri', ''));
        if ($explicitUri === '') {
            $explicitUri = trim($this->rawProcessEnv('NEO4J_URI'));
        }
        if ($explicitUri !== '') {
            return [
                $explicitUri,
                (string) config('neo4j-boost.container_graph.username'),
                (string) config('neo4j-boost.container_graph.password'),
            ];
        }

        $dsn = trim((string) config('neo4j-boost.container_graph.default_connection_dsn', ''));
        if ($dsn === '') {
            $dsn = trim($this->rawProcessEnv('NEO4J_DEFAULT_CONNECTION_DSN'));
        }
        if ($dsn !== '') {
            $fromDsn = ContainerGraphConnection::parseDsnToConnection($dsn);
            if ($fromDsn !== null) {
                return array_values($fromDsn);
            }
        }

        return [
            'bolt://localhost:7687',
            (string) config('neo4j-boost.container_graph.username'),
            (string) config('neo4j-boost.container_graph.password'),
        ];
    }

    private function rawProcessEnv(string $key): string
    {
        if (getenv($key) !== false && (string) getenv($key) !== '') {
            return (string) getenv($key);
        }
        if (isset($_ENV[$key]) && (string) $_ENV[$key] !== '') {
            return (string) $_ENV[$key];
        }
        if (isset($_SERVER[$key]) && (string) $_SERVER[$key] !== '') {
            return (string) $_SERVER[$key];
        }

        return '';
    }
}
