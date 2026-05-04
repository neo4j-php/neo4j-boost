<?php

namespace Neo4j\LaravelBoost;

use Laudis\Neo4j\Authentication\Authenticate;
use Laudis\Neo4j\ClientBuilder;
use Laudis\Neo4j\Contracts\ClientInterface;

class ContainerGraphWriter
{
    private const DRIVER_ALIAS = 'container-graph';

    private const CYPHER_BINDINGS = <<<'CYPHER'
UNWIND $rows AS row
FOREACH (_ IN CASE WHEN row.abstractKind = 'Interface' THEN [1] ELSE [] END |
  MERGE (a:Interface:Abstract {name: row.abstract})
  MERGE (c:Class:Abstract {name: row.concrete})
  MERGE (a)-[r:BINDS_TO]->(c)
  SET r.shared = row.shared
)
FOREACH (_ IN CASE WHEN row.abstractKind <> 'Interface' THEN [1] ELSE [] END |
  MERGE (a:Class:Abstract {name: row.abstract})
  MERGE (c:Class:Abstract {name: row.concrete})
  MERGE (a)-[r:BINDS_TO]->(c)
  SET r.shared = row.shared
)
CYPHER;

    private const CYPHER_CLASSES = <<<'CYPHER'
UNWIND $rows AS row
MERGE (:Class:Abstract {name: row.class})
CYPHER;

    private const CYPHER_DEPENDENCIES = <<<'CYPHER'
UNWIND $rows AS row
MERGE (c:Class:Abstract {name: row.class})
FOREACH (_ IN CASE WHEN row.dependencyKind = 'Interface' THEN [1] ELSE [] END |
  MERGE (d:Interface:Abstract {name: row.dependency})
  MERGE (c)-[:DEPENDS_ON]->(d)
)
FOREACH (_ IN CASE WHEN row.dependencyKind <> 'Interface' THEN [1] ELSE [] END |
  MERGE (d:Class:Abstract {name: row.dependency})
  MERGE (c)-[:DEPENDS_ON]->(d)
)
CYPHER;

    private const CYPHER_UNRESOLVED = <<<'CYPHER'
UNWIND $rows AS row
MERGE (c:Class:Abstract {name: row.class})
MERGE (u:UnresolvedDependency:Abstract {name: row.name})
SET u.reason = row.reason
MERGE (c)-[:DEPENDS_ON]->(u)
CYPHER;

    private ?ClientInterface $client = null;

    public function connect(): void
    {
        $this->client()->verifyConnectivity(self::DRIVER_ALIAS);
    }

    /**
     * @param array<int, array{class: string}> $classRows
     * @param array<int, array{abstract: string, abstractKind: string, concrete: string, shared: bool}> $bindingRows
     * @param array<int, array{class: string, dependency: string, dependencyKind: string}> $dependencyRows
     * @param array<int, array{class: string, name: string, reason: string}> $unresolvedRows
     */
    public function write(array $classRows, array $bindingRows, array $dependencyRows, array $unresolvedRows): void
    {
        if ($classRows !== []) {
            $this->client()->run(self::CYPHER_CLASSES, ['rows' => $classRows], self::DRIVER_ALIAS);
        }
        if ($bindingRows !== []) {
            $this->client()->run(self::CYPHER_BINDINGS, ['rows' => $bindingRows], self::DRIVER_ALIAS);
        }
        if ($dependencyRows !== []) {
            $this->client()->run(self::CYPHER_DEPENDENCIES, ['rows' => $dependencyRows], self::DRIVER_ALIAS);
        }
        if ($unresolvedRows !== []) {
            $this->client()->run(self::CYPHER_UNRESOLVED, ['rows' => $unresolvedRows], self::DRIVER_ALIAS);
        }
    }

    /**
     * @return array<string, string>
     */
    public function cypherTemplates(): array
    {
        return [
            'classes' => self::CYPHER_CLASSES,
            'bindings' => self::CYPHER_BINDINGS,
            'dependencies' => self::CYPHER_DEPENDENCIES,
            'unresolved' => self::CYPHER_UNRESOLVED,
        ];
    }

    private function client(): ClientInterface
    {
        if ($this->client !== null) {
            return $this->client;
        }

        [$uri, $user, $password] = $this->buildConnectionParams();

        $this->client = ClientBuilder::create()
            ->withDriver(self::DRIVER_ALIAS, $uri, Authenticate::basic($user, $password))
            ->withDefaultDriver(self::DRIVER_ALIAS)
            ->build();

        return $this->client;
    }

    /**
     * NEO4J_URI wins; else NEO4J_DEFAULT_CONNECTION_DSN (e.g. neo4j://user:pass@host:7687 from Docker);
     * else defaults to local Bolt.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function buildConnectionParams(): array
    {
        $explicitUri = trim((string) config('neo4j-boost.container_graph.uri', ''));
        if ($explicitUri === '') {
            $explicitUri = trim($this->rawProcessEnv('NEO4J_URI'));
        }
        if ($explicitUri !== '') {
            return [
                $explicitUri,
                (string) env('NEO4J_USER', env('NEO4J_USERNAME', 'neo4j')),
                (string) env('NEO4J_PASSWORD', ''),
            ];
        }

        $dsn = trim((string) config('neo4j-boost.container_graph.default_connection_dsn', ''));
        if ($dsn === '') {
            $dsn = trim($this->rawProcessEnv('NEO4J_DEFAULT_CONNECTION_DSN'));
        }
        if ($dsn !== '') {
            $fromDsn = self::parseDsnToConnection($dsn);
            if ($fromDsn !== null) {
                return array_values($fromDsn);
            }
        }

        return [
            'bolt://localhost:7687',
            (string) env('NEO4J_USER', env('NEO4J_USERNAME', 'neo4j')),
            (string) env('NEO4J_PASSWORD', ''),
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

    /**
     * @return null|array{uri: string, user: string, password: string}
     */
    private static function parseDsnToConnection(string $dsn): ?array
    {
        $parts = parse_url($dsn);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        if (isset($parts['user']) && $parts['user'] !== '') {
            $user = rawurldecode($parts['user']);
        } else {
            $user = (string) env('NEO4J_USER', env('NEO4J_USERNAME', 'neo4j'));
        }
        if (array_key_exists('pass', $parts) && (string) $parts['pass'] !== '') {
            $password = rawurldecode($parts['pass']);
        } else {
            $password = (string) env('NEO4J_PASSWORD', '');
        }

        $uri = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $uri .= ':' . (int) $parts['port'];
        }
        if (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/') {
            $uri .= $parts['path'];
        }
        if (isset($parts['query'])) {
            $uri .= '?' . $parts['query'];
        }
        if (isset($parts['fragment'])) {
            $uri .= '#' . $parts['fragment'];
        }

        return [
            'uri' => $uri,
            'user' => $user,
            'password' => $password,
        ];
    }
}
