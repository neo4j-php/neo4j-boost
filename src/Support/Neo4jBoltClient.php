<?php

namespace Neo4j\LaravelBoost\Support;

use Laudis\Neo4j\Authentication\Authenticate;
use Laudis\Neo4j\ClientBuilder;
use Laudis\Neo4j\Contracts\ClientInterface;
use RuntimeException;

/**
 * Process-wide Neo4j Bolt client for package features (container graph, MCP driver, etc.).
 */
final class Neo4jBoltClient
{
    public const DRIVER_ALIAS = 'neo4j-boost';

    private ?ClientInterface $client = null;

    private ?ClientInterface $mcpDriverClient = null;

    public function client(): ClientInterface
    {
        if ($this->client !== null) {
            return $this->client;
        }

        [$uri, $user, $password] = $this->resolveConnectionParams(allowLocalhostDefault: true);

        $this->client = $this->buildClient($uri, $user, $password);

        return $this->client;
    }

    public function mcpDriverClient(): ClientInterface
    {
        if ($this->mcpDriverClient !== null) {
            return $this->mcpDriverClient;
        }

        [$uri, $user, $password] = $this->resolveConnectionParams(allowLocalhostDefault: false);

        if ($uri === '') {
            throw new RuntimeException(
                'Neo4j driver transport requires NEO4J_URI (and NEO4J_USERNAME / NEO4J_PASSWORD). '
                .'Set NEO4J_MCP_TRANSPORT=driver in .env or use http/stdio transport instead.'
            );
        }

        $this->mcpDriverClient = $this->buildClient($uri, $user, $password);

        return $this->mcpDriverClient;
    }

    public function driverAlias(): string
    {
        return self::DRIVER_ALIAS;
    }

    public function reset(): void
    {
        $this->client = null;
        $this->mcpDriverClient = null;
    }

    private function buildClient(string $uri, string $user, string $password): ClientInterface
    {
        return ClientBuilder::create()
            ->withDriver(self::DRIVER_ALIAS, $uri, Authenticate::basic($user, $password))
            ->withDefaultDriver(self::DRIVER_ALIAS)
            ->build();
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function resolveConnectionParams(bool $allowLocalhostDefault): array
    {
        $uri = trim((string) config('neo4j-boost.bolt.uri', ''));
        $username = (string) config('neo4j-boost.bolt.username', '');
        $password = (string) config('neo4j-boost.bolt.password', '');

        if ($uri === '') {
            $uri = trim($this->rawProcessEnv('NEO4J_URI'));
        }
        if ($username === '') {
            $username = $this->rawProcessEnv('NEO4J_USERNAME') ?: 'neo4j';
        }
        if ($password === '' && $this->rawProcessEnv('NEO4J_PASSWORD') !== '') {
            $password = $this->rawProcessEnv('NEO4J_PASSWORD');
        }

        if ($uri !== '' && str_contains($uri, '@')) {
            $parsed = self::parseDsnToConnection($uri);
            if ($parsed !== null) {
                return array_values($parsed);
            }
        }

        if ($uri === '') {
            $dsn = trim((string) config('neo4j-boost.container_graph.default_connection_dsn', ''));
            if ($dsn === '') {
                $dsn = trim($this->rawProcessEnv('NEO4J_DEFAULT_CONNECTION_DSN'));
            }
            if ($dsn !== '') {
                $parsed = ContainerGraphConnection::parseDsnToConnection($dsn);
                if ($parsed !== null) {
                    return array_values($parsed);
                }
            }
        }

        $explicitUri = trim((string) config('neo4j-boost.container_graph.uri', ''));
        if ($uri === '' && $explicitUri !== '') {
            $uri = $explicitUri;
            $username = (string) config('neo4j-boost.container_graph.username');
            $password = (string) config('neo4j-boost.container_graph.password');
        }

        if ($uri === '' && $allowLocalhostDefault) {
            return [
                'bolt://localhost:7687',
                (string) config('neo4j-boost.container_graph.username', 'neo4j'),
                (string) config('neo4j-boost.container_graph.password', ''),
            ];
        }

        return [$uri, $username, $password];
    }

    /**
     * @return null|array{uri: string, user: string, password: string}
     */
    public static function parseDsnToConnection(string $dsn): ?array
    {
        $parts = parse_url($dsn);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $user = isset($parts['user']) && $parts['user'] !== ''
            ? rawurldecode($parts['user'])
            : 'neo4j';
        $password = array_key_exists('pass', $parts) && (string) $parts['pass'] !== ''
            ? rawurldecode((string) $parts['pass'])
            : '';

        $uri = $parts['scheme'].'://'.$parts['host'];
        if (isset($parts['port'])) {
            $uri .= ':'.(int) $parts['port'];
        }

        return ['uri' => $uri, 'user' => $user, 'password' => $password];
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
