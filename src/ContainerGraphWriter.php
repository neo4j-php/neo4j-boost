<?php

namespace Neo4j\LaravelBoost;

use Neo4j\LaravelBoost\Support\ContainerGraphConnection;
use Neo4j\LaravelBoost\Support\Graph\BindsToType;
use Neo4j\LaravelBoost\Support\Graph\DependencyAccessType;
use Neo4j\LaravelBoost\Support\Graph\ResolvesToLifetime;

class ContainerGraphWriter
{
    private const CYPHER_BINDINGS = <<<'CYPHER'
UNWIND $rows AS row
FOREACH (_ IN CASE WHEN row.abstractKind = 'Interface' THEN [1] ELSE [] END |
  MERGE (:Interface:Abstract {name: row.abstract})
)
FOREACH (_ IN CASE WHEN row.abstractKind = 'Class' THEN [1] ELSE [] END |
  MERGE (:Class:Abstract {name: row.abstract})
)
FOREACH (_ IN CASE WHEN row.abstractKind <> 'Interface' AND row.abstractKind <> 'Class' THEN [1] ELSE [] END |
  MERGE (a:AbstractType:Abstract {name: row.abstract})
  SET a.kind = row.abstractKind
)
FOREACH (_ IN CASE WHEN row.concreteKind = 'Interface' THEN [1] ELSE [] END |
  MERGE (:Interface:Abstract {name: row.concrete})
)
FOREACH (_ IN CASE WHEN row.concreteKind = 'Class' THEN [1] ELSE [] END |
  MERGE (:Class:Abstract {name: row.concrete})
)
FOREACH (_ IN CASE WHEN row.concreteKind <> 'Interface' AND row.concreteKind <> 'Class' THEN [1] ELSE [] END |
  MERGE (c:AbstractType:Abstract {name: row.concrete})
  SET c.kind = row.concreteKind
)
WITH row
MATCH (a:Abstract {name: row.abstract})
MATCH (c:Abstract {name: row.concrete})
MERGE (a)-[r:BINDS_TO]->(c)
SET r.type = row.type
CYPHER;

    private const CYPHER_INSTANCES = <<<'CYPHER'
UNWIND $rows AS row
MERGE (:Instance {name: row.class})
CYPHER;

    private const CYPHER_RESOLVES_TO = <<<'CYPHER'
UNWIND $rows AS row
MERGE (dep:Dependency {key: row.dependency_key})
SET dep.access = row.access
MERGE (id:Identifier {name: row.identifier})
SET id.kind = row.identifier_kind,
    id.reason = coalesce(row.reason, id.reason)
MERGE (dep)-[r:RESOLVES_TO]->(id)
SET r.lifetime = row.lifetime
CYPHER;

    private const CYPHER_INSTANCE_DEPENDS_ON = <<<'CYPHER'
UNWIND $rows AS row
MERGE (i:Instance {name: row.instance})
MERGE (dep:Dependency {key: row.dependency_key})
MERGE (i)-[d:DEPENDS_ON]->(dep)
SET d.file = row.file, d.line = row.line, d.via = row.via
CYPHER;

    public function __construct(
        private ContainerGraphConnection $connection,
    ) {}

    public function connect(): void
    {
        $this->connection->connect();
    }

    /**
     * @param  array<int, array{class: string}>  $instanceRows
     * @param  array<int, array{abstract: string, abstractKind: string, concrete: string, concreteKind: string, shared: bool, type: string}>  $bindingRows
     * @param  array<int, array{instance: string, dependency_key: string, access: string, identifier: string, identifier_kind: string, lifetime: string, via: string, file: string, line: int}>  $dependencyChainRows
     */
    public function write(array $instanceRows, array $bindingRows, array $dependencyChainRows): void
    {
        $this->validateBindingRows($bindingRows);
        $this->validateDependencyChainRows($dependencyChainRows);

        if ($instanceRows !== []) {
            $this->connection->run(self::CYPHER_INSTANCES, ['rows' => $instanceRows]);
        }
        if ($bindingRows !== []) {
            $this->connection->run(self::CYPHER_BINDINGS, ['rows' => $bindingRows]);
        }
        if ($dependencyChainRows !== []) {
            $this->connection->run(self::CYPHER_RESOLVES_TO, ['rows' => $dependencyChainRows]);

            $instanceChains = array_values(array_filter(
                $dependencyChainRows,
                static fn (array $row): bool => ($row['instance'] ?? '') !== '',
            ));

            if ($instanceChains !== []) {
                $this->connection->run(self::CYPHER_INSTANCE_DEPENDS_ON, ['rows' => $instanceChains]);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    public function cypherTemplates(): array
    {
        return [
            'instances' => self::CYPHER_INSTANCES,
            'bindings' => self::CYPHER_BINDINGS,
            'resolves_to' => self::CYPHER_RESOLVES_TO,
            'instance_depends_on' => self::CYPHER_INSTANCE_DEPENDS_ON,
        ];
    }

    /**
     * @param  array<int, array{abstract: string, abstractKind: string, concrete: string, concreteKind: string, shared: bool, type: string}>  $bindingRows
     */
    private function validateBindingRows(array $bindingRows): void
    {
        foreach ($bindingRows as $row) {
            BindsToType::assertAllowed((string) ($row['type'] ?? ''));
        }
    }

    /**
     * @param  array<int, array{instance: string, dependency_key: string, access: string, identifier: string, identifier_kind: string, lifetime: string, via: string, file: string, line: int}>  $dependencyChainRows
     */
    private function validateDependencyChainRows(array $dependencyChainRows): void
    {
        foreach ($dependencyChainRows as $row) {
            DependencyAccessType::assertAllowed((string) ($row['access'] ?? ''));
            ResolvesToLifetime::assertAllowed((string) ($row['lifetime'] ?? ''));

            foreach (['dependency_key', 'identifier', 'identifier_kind', 'via', 'file'] as $key) {
                if (! array_key_exists($key, $row) || ! is_string($row[$key])) {
                    throw new \InvalidArgumentException("Dependency chain row is missing string {$key}");
                }
            }

            if (! array_key_exists('line', $row) || ! is_int($row['line'])) {
                throw new \InvalidArgumentException('Dependency chain row is missing integer line');
            }

            if (! array_key_exists('instance', $row) || ! is_string($row['instance'])) {
                throw new \InvalidArgumentException('Dependency chain row is missing string instance');
            }
        }
    }
}
