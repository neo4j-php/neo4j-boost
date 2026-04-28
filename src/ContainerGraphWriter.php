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
  MERGE (a:Interface {name: row.abstract})
  MERGE (c:Class {name: row.concrete})
  MERGE (a)-[r:BINDS_TO]->(c)
  SET r.shared = row.shared
)
FOREACH (_ IN CASE WHEN row.abstractKind <> 'Interface' THEN [1] ELSE [] END |
  MERGE (a:Class {name: row.abstract})
  MERGE (c:Class {name: row.concrete})
  MERGE (a)-[r:BINDS_TO]->(c)
  SET r.shared = row.shared
)
CYPHER;

    private const CYPHER_DEPENDENCIES = <<<'CYPHER'
UNWIND $rows AS row
MERGE (c:Class {name: row.class})
FOREACH (_ IN CASE WHEN row.dependencyKind = 'Interface' THEN [1] ELSE [] END |
  MERGE (d:Interface {name: row.dependency})
  MERGE (c)-[:DEPENDS_ON]->(d)
)
FOREACH (_ IN CASE WHEN row.dependencyKind <> 'Interface' THEN [1] ELSE [] END |
  MERGE (d:Class {name: row.dependency})
  MERGE (c)-[:DEPENDS_ON]->(d)
)
CYPHER;

    private const CYPHER_UNRESOLVED = <<<'CYPHER'
UNWIND $rows AS row
MERGE (c:Class {name: row.class})
MERGE (u:UnresolvedDependency {name: row.name})
SET u.reason = row.reason
MERGE (c)-[:DEPENDS_ON]->(u)
CYPHER;

    private ?ClientInterface $client = null;

    public function connect(): void
    {
        $this->client()->verifyConnectivity(self::DRIVER_ALIAS);
    }

    /**
     * @param array<int, array{abstract: string, abstractKind: string, concrete: string, shared: bool}> $bindingRows
     * @param array<int, array{class: string, dependency: string, dependencyKind: string}> $dependencyRows
     * @param array<int, array{class: string, name: string, reason: string}> $unresolvedRows
     */
    public function write(array $bindingRows, array $dependencyRows, array $unresolvedRows): void
    {
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

        $uri = (string) env('NEO4J_URI', 'bolt://localhost:7687');
        $user = (string) env('NEO4J_USER', env('NEO4J_USERNAME', 'neo4j'));
        $password = (string) env('NEO4J_PASSWORD', '');

        $this->client = ClientBuilder::create()
            ->withDriver(self::DRIVER_ALIAS, $uri, Authenticate::basic($user, $password))
            ->withDefaultDriver(self::DRIVER_ALIAS)
            ->build();

        return $this->client;
    }
}
