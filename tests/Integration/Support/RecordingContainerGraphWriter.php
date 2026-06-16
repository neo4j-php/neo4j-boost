<?php

namespace Neo4j\LaravelBoost\Tests\Integration\Support;

use Neo4j\LaravelBoost\ContainerGraphWriter;
use Neo4j\LaravelBoost\Support\Graph\DependencyAccessType;
use Neo4j\LaravelBoost\Tests\Integration\Support\Stubs\UnusedContainerGraphConnection;

/**
 * In-memory stand-in for Neo4j used by container:graph E2E tests.
 */
class RecordingContainerGraphWriter extends ContainerGraphWriter
{
    public function __construct()
    {
        parent::__construct(new UnusedContainerGraphConnection);
    }

    /** @var array<int, array{class: string}> */
    public array $instanceRows = [];

    /** @var array<int, array{class: string}> */
    public array $classRows = [];

    /** @var array<int, array{abstract: string, abstractKind: string, concrete: string, concreteKind: string, shared: bool, type: string}> */
    public array $bindingRows = [];

    /** @var array<int, array{instance: string, dependency_key: string, access: string, identifier: string, identifier_kind: string, lifetime: string, via: string, file: string, line: int, reason?: string}> */
    public array $dependencyChainRows = [];

    /** @var array<int, array{instance: string, dependency_key: string, access: string, identifier: string, identifier_kind: string, lifetime: string, via: string, file: string, line: int, reason?: string}> */
    public array $dependencyRows = [];

    /** @var array<int, array{instance: string, dependency_key: string, access: string, identifier: string, identifier_kind: string, lifetime: string, via: string, file: string, line: int, reason?: string}> */
    public array $unresolvedRows = [];

    public function connect(): void
    {
        // No Neo4j required in tests.
    }

    /**
     * @param  array<int, array{class: string}>  $instanceRows
     * @param  array<int, array{abstract: string, abstractKind: string, concrete: string, concreteKind: string, shared: bool, type: string}>  $bindingRows
     * @param  array<int, array{instance: string, dependency_key: string, access: string, identifier: string, identifier_kind: string, lifetime: string, via: string, file: string, line: int}>  $dependencyChainRows
     */
    public function write(array $instanceRows, array $bindingRows, array $dependencyChainRows): void
    {
        $this->instanceRows = $instanceRows;
        $this->classRows = $instanceRows;
        $this->bindingRows = $bindingRows;
        $this->dependencyChainRows = $dependencyChainRows;
        $this->dependencyRows = array_values(array_filter(
            $dependencyChainRows,
            static fn (array $row): bool => ($row['instance'] ?? '') !== '',
        ));
        $this->unresolvedRows = array_values(array_filter(
            $dependencyChainRows,
            static fn (array $row): bool => ($row['identifier_kind'] ?? '') === 'Unresolved',
        ));
    }

    /**
     * @return null|array{abstract: string, abstractKind: string, concrete: string, concreteKind: string, shared: bool, type: string}
     */
    public function findBinding(string $abstract): ?array
    {
        foreach ($this->bindingRows as $row) {
            if ($row['abstract'] === $abstract) {
                return $row;
            }
        }

        return null;
    }

    public function hasBindsToEdge(string $abstract, string $concrete): bool
    {
        $binding = $this->findBinding($abstract);

        return $binding !== null && $binding['concrete'] === $concrete;
    }

    public function hasDependsOnEdge(string $class, string $dependency): bool
    {
        return $this->findDependencyChainRow($class, $dependency) !== null;
    }

    /**
     * @return null|array{instance: string, dependency_key: string, access: string, identifier: string, identifier_kind: string, lifetime: string, via: string, file: string, line: int, type: string}
     */
    public function findDependencyChainRow(string $instance, string $identifier): ?array
    {
        foreach ($this->dependencyChainRows as $row) {
            if ($row['instance'] === $instance && $row['identifier'] === $identifier) {
                return $this->withLegacyType($row);
            }
        }

        return null;
    }

    /**
     * @return null|array{class: string, dependency: string, dependencyKind: string, type: string, via?: string, file?: string, line?: int}
     */
    public function findDependencyRow(string $class, string $dependency): ?array
    {
        $chain = $this->findDependencyChainRow($class, $dependency);
        if ($chain === null) {
            return null;
        }

        return [
            'class' => $chain['instance'],
            'dependency' => $chain['identifier'],
            'dependencyKind' => $chain['identifier_kind'],
            'type' => $chain['type'],
            'via' => $chain['via'],
            'file' => $chain['file'],
            'line' => $chain['line'],
        ];
    }

    public function hasClassNode(string $class): bool
    {
        foreach ($this->instanceRows as $row) {
            if ($row['class'] === $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{instance: string, dependency_key: string, access: string, identifier: string, identifier_kind: string, lifetime: string, via: string, file: string, line: int}  $row
     * @return array{instance: string, dependency_key: string, access: string, identifier: string, identifier_kind: string, lifetime: string, via: string, file: string, line: int, type: string}
     */
    private function withLegacyType(array $row): array
    {
        $row['type'] = DependencyAccessType::assertAllowed($row['access'])->toDependsOnType()->value;

        return $row;
    }
}
