<?php

namespace Neo4j\LaravelBoost\Tests\Integration\Support;

use Neo4j\LaravelBoost\ContainerGraphWriter;
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
    public array $classRows = [];

    /** @var array<int, array{abstract: string, abstractKind: string, concrete: string, concreteKind: string, shared: bool, type: string}> */
    public array $bindingRows = [];

    /** @var array<int, array{class: string, dependency: string, dependencyKind: string, type: string}> */
    public array $dependencyRows = [];

    /** @var array<int, array{class: string, name: string, reason: string, type: string}> */
    public array $unresolvedRows = [];

    public function connect(): void
    {
        // No Neo4j required in tests.
    }

    /**
     * @param  array<int, array{class: string}>  $classRows
     * @param  array<int, array{abstract: string, abstractKind: string, concrete: string, concreteKind: string, shared: bool, type: string}>  $bindingRows
     * @param  array<int, array{class: string, dependency: string, dependencyKind: string, type: string}>  $dependencyRows
     * @param  array<int, array{class: string, name: string, reason: string, type: string}>  $unresolvedRows
     */
    public function write(array $classRows, array $bindingRows, array $dependencyRows, array $unresolvedRows): void
    {
        $this->classRows = $classRows;
        $this->bindingRows = $bindingRows;
        $this->dependencyRows = $dependencyRows;
        $this->unresolvedRows = $unresolvedRows;
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
        return $this->findDependencyRow($class, $dependency) !== null;
    }

    /**
     * @return null|array{class: string, dependency: string, dependencyKind: string, type: string}
     */
    public function findDependencyRow(string $class, string $dependency): ?array
    {
        foreach ($this->dependencyRows as $row) {
            if ($row['class'] === $class && $row['dependency'] === $dependency) {
                return $row;
            }
        }

        return null;
    }

    public function hasClassNode(string $class): bool
    {
        foreach ($this->classRows as $row) {
            if ($row['class'] === $class) {
                return true;
            }
        }

        return false;
    }
}
