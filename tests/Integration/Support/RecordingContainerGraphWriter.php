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

    /** @var array<int, array{when: string, when_kind: string, needs: string, needs_kind: string, give: string, give_kind: string, reason: string}> */
    public array $contextualBindingRows = [];

    /** @var array<int, array{key: string, uri: string, methods: string, name: string, action: string, identifier: string, identifier_kind: string}> */
    public array $routeRows = [];

    /** @var array<int, array{route_key: string, middleware_key: string, identifier: string, identifier_kind: string, parameters: string, order: int}> */
    public array $routeMiddlewareRows = [];

    public function connect(): void
    {
        // No Neo4j required in tests.
    }

    /**
     * @param  array<int, array{class: string}>  $instanceRows
     * @param  array<int, array{abstract: string, abstractKind: string, concrete: string, concreteKind: string, shared: bool, type: string}>  $bindingRows
     * @param  array<int, array{instance: string, dependency_key: string, access: string, identifier: string, identifier_kind: string, lifetime: string, injection_type: string, method: string, parameter: string, via: string, file: string, line: int}>  $dependencyChainRows
     * @param  array<int, array{when: string, when_kind: string, needs: string, needs_kind: string, give: string, give_kind: string, reason: string}>  $contextualBindingRows
     * @param  array<int, array{key: string, uri: string, methods: string, name: string, action: string, identifier: string, identifier_kind: string}>  $routeRows
     * @param  array<int, array{route_key: string, middleware_key: string, identifier: string, identifier_kind: string, parameters: string, order: int}>  $routeMiddlewareRows
     */
    public function write(
        array $instanceRows,
        array $bindingRows,
        array $dependencyChainRows,
        array $contextualBindingRows = [],
        array $routeRows = [],
        array $routeMiddlewareRows = [],
    ): void {
        $this->instanceRows = $instanceRows;
        $this->bindingRows = $bindingRows;
        $this->dependencyChainRows = $dependencyChainRows;
        $this->contextualBindingRows = $contextualBindingRows;
        $this->routeRows = $routeRows;
        $this->routeMiddlewareRows = $routeMiddlewareRows;
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

    /**
     * @return null|array{when: string, when_kind: string, needs: string, needs_kind: string, give: string, give_kind: string, reason: string}
     */
    public function findContextualBindingRow(string $when, string $needs, string $give): ?array
    {
        foreach ($this->contextualBindingRows as $row) {
            if ($row['when'] === $when && $row['needs'] === $needs && $row['give'] === $give) {
                return $row;
            }
        }

        return null;
    }

    public function hasContextualBindsEdge(string $when, string $needs, string $give): bool
    {
        return $this->findContextualBindingRow($when, $needs, $give) !== null;
    }

    public function hasRouteHandledBy(string $routeKey, string $identifier): bool
    {
        foreach ($this->routeRows as $row) {
            if ($row['key'] === $routeKey && $row['identifier'] === $identifier) {
                return true;
            }
        }

        return false;
    }

    public function hasRouteMiddleware(string $routeKey, string $middlewareKey, ?string $parameters = null): bool
    {
        foreach ($this->routeMiddlewareRows as $row) {
            if ($row['route_key'] !== $routeKey || $row['middleware_key'] !== $middlewareKey) {
                continue;
            }

            if ($parameters !== null && $row['parameters'] !== $parameters) {
                continue;
            }

            return true;
        }

        return false;
    }
}
