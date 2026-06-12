<?php

namespace Neo4j\LaravelBoost;

use Neo4j\LaravelBoost\StaticAnalysis\DependencyEdgeSource;
use Neo4j\LaravelBoost\Support\ContainerGraphConnection;
use Neo4j\LaravelBoost\Support\Graph\BindsToType;
use Neo4j\LaravelBoost\Support\Graph\DependsOnType;

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

    private const CYPHER_CLASSES = <<<'CYPHER'
UNWIND $rows AS row
MERGE (:Class:Abstract {name: row.class})
CYPHER;

    private const CYPHER_DEPENDENCIES = <<<'CYPHER'
UNWIND $rows AS row
MERGE (c:Class:Abstract {name: row.class})
FOREACH (_ IN CASE WHEN row.dependencyKind = 'Interface' THEN [1] ELSE [] END |
  MERGE (d:Interface:Abstract {name: row.dependency})
  MERGE (c)-[r:DEPENDS_ON]->(d)
  SET r.type = row.type,
      r.source = row.source,
      r.via = row.via,
      r.file = row.file,
      r.line = row.line
)
FOREACH (_ IN CASE WHEN row.dependencyKind <> 'Interface' THEN [1] ELSE [] END |
  MERGE (d:Class:Abstract {name: row.dependency})
  MERGE (c)-[r:DEPENDS_ON]->(d)
  SET r.type = row.type,
      r.source = row.source,
      r.via = row.via,
      r.file = row.file,
      r.line = row.line
)
CYPHER;

    private const CYPHER_UNRESOLVED = <<<'CYPHER'
UNWIND $rows AS row
MERGE (c:Class:Abstract {name: row.class})
MERGE (u:UnresolvedDependency:Abstract {name: row.name})
SET u.reason = row.reason
MERGE (c)-[r:DEPENDS_ON]->(u)
SET r.type = row.type
CYPHER;

    public function __construct(
        private ContainerGraphConnection $connection,
    ) {}

    public function connect(): void
    {
        $this->connection->connect();
    }

    /**
     * @param  array<int, array{class: string}>  $classRows
     * @param  array<int, array{abstract: string, abstractKind: string, concrete: string, concreteKind: string, shared: bool, type: string}>  $bindingRows
     * @param  array<int, array{class: string, dependency: string, dependencyKind: string, type: string, source: string, via: string, file: string, line: int}>  $dependencyRows
     * @param  array<int, array{class: string, name: string, reason: string, type: string}>  $unresolvedRows
     */
    public function write(array $classRows, array $bindingRows, array $dependencyRows, array $unresolvedRows): void
    {
        $this->validateBindingRows($bindingRows);
        $this->validateDependencyRows($dependencyRows);
        $this->validateUnresolvedRows($unresolvedRows);

        if ($classRows !== []) {
            $this->connection->run(self::CYPHER_CLASSES, ['rows' => $classRows]);
        }
        if ($bindingRows !== []) {
            $this->connection->run(self::CYPHER_BINDINGS, ['rows' => $bindingRows]);
        }
        if ($dependencyRows !== []) {
            $this->connection->run(self::CYPHER_DEPENDENCIES, ['rows' => $dependencyRows]);
        }
        if ($unresolvedRows !== []) {
            $this->connection->run(self::CYPHER_UNRESOLVED, ['rows' => $unresolvedRows]);
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
     * @param  array<int, array{class: string, dependency: string, dependencyKind: string, type: string, source: string, via: string, file: string, line: int}>  $dependencyRows
     */
    private function validateDependencyRows(array $dependencyRows): void
    {
        foreach ($dependencyRows as $row) {
            DependsOnType::assertAllowed((string) ($row['type'] ?? ''));
            $this->assertDependencyMetadata($row);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function assertDependencyMetadata(array $row): void
    {
        foreach (['source', 'via', 'file'] as $key) {
            if (! array_key_exists($key, $row) || ! is_string($row[$key])) {
                throw new \InvalidArgumentException("Dependency row is missing string {$key}");
            }
        }

        if (! array_key_exists('line', $row) || ! is_int($row['line'])) {
            throw new \InvalidArgumentException('Dependency row is missing integer line');
        }

        if ($row['source'] === DependencyEdgeSource::Static->value && $row['type'] !== DependsOnType::ServiceLocation->value) {
            throw new \InvalidArgumentException('Static analysis edges must use service_location type');
        }
    }

    /**
     * @param  array<int, array{class: string, name: string, reason: string, type: string}>  $unresolvedRows
     */
    private function validateUnresolvedRows(array $unresolvedRows): void
    {
        foreach ($unresolvedRows as $row) {
            DependsOnType::assertAllowed((string) ($row['type'] ?? ''));
        }
    }
}
