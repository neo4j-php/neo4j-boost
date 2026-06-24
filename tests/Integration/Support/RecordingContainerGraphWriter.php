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
    public array $instanceRows = [];

    /** @var array<int, array{abstract: string, abstractKind: string, concrete: string, concreteKind: string, shared: bool, type: string}> */
    public array $bindingRows = [];

    /** @var array<int, array{instance: string, dependency_key: string, access: string, identifier: string, identifier_kind: string, lifetime: string, injection_type: string, method: string, parameter: string, via: string, file: string, line: int, reason?: string}> */
    public array $dependencyChainRows = [];

    public function connect(): void
    {
        // No Neo4j required in tests.
    }

    /**
     * @param  array<int, array{class: string}>  $instanceRows
     * @param  array<int, array{abstract: string, abstractKind: string, concrete: string, concreteKind: string, shared: bool, type: string}>  $bindingRows
     * @param  array<int, array{instance: string, dependency_key: string, access: string, identifier: string, identifier_kind: string, lifetime: string, injection_type: string, method: string, parameter: string, via: string, file: string, line: int}>  $dependencyChainRows
     */
    public function write(array $instanceRows, array $bindingRows, array $dependencyChainRows): void
    {
        $this->instanceRows = $instanceRows;
        $this->bindingRows = $bindingRows;
        $this->dependencyChainRows = $dependencyChainRows;
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
     * @return null|array{instance: string, dependency_key: string, access: string, identifier: string, identifier_kind: string, lifetime: string, via: string, file: string, line: int, reason?: string}
     */
    public function findDependencyChainRow(string $instance, string $identifier): ?array
    {
        foreach ($this->dependencyChainRows as $row) {
            if ($row['instance'] === $instance && $row['identifier'] === $identifier) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return null|array{instance: string, dependency_key: string, access: string, identifier: string, identifier_kind: string, lifetime: string, via: string, file: string, line: int, reason?: string}
     */
    public function findFacadeCatalogChain(string $facadeClass): ?array
    {
        foreach ($this->dependencyChainRows as $row) {
            if (($row['instance'] ?? '') === ''
                && ($row['access'] ?? '') === 'facade'
                && ($row['via'] ?? '') === $facadeClass) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return null|array{instance: string, dependency_key: string, access: string, identifier: string, identifier_kind: string, lifetime: string, injection_type: string, method: string, parameter: string, via: string, file: string, line: int, reason?: string}
     */
    public function findMethodInjectionChain(
        string $instance,
        string $identifier,
        string $method,
        string $parameter,
    ): ?array {
        foreach ($this->dependencyChainRows as $row) {
            if ($row['instance'] === $instance
                && $row['identifier'] === $identifier
                && ($row['method'] ?? '') === $method
                && ($row['parameter'] ?? '') === $parameter
            ) {
                return $row;
            }
        }

        return null;
    }

    public function hasInstanceNode(string $class): bool
    {
        foreach ($this->instanceRows as $row) {
            if ($row['class'] === $class) {
                return true;
            }
        }

        return false;
    }
}
