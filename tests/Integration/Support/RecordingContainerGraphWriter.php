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

    /** @var array<int, array{key: string, name: string, action: string, identifier: string, identifier_kind: string}> */
    public array $eventRows = [];

    /** @var array<int, array{key: string, name: string, action: string, identifier: string, identifier_kind: string, should_queue: bool, connection: string, queue: string, unique: bool}> */
    public array $jobRows = [];

    /** @var array<int, array{key: string, driver: string, default_queue: string, is_default: bool}> */
    public array $queueConnectionRows = [];

    /** @var array<int, array{key: string, name: string, expression: string, command: string, description: string, timezone: string, kind: string, without_overlapping: bool, on_one_server: bool, run_in_background: bool, even_in_maintenance_mode: bool, action: string, identifier: string, identifier_kind: string}> */
    public array $scheduledTaskRows = [];

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
     * @param  array<int, array{key: string, name: string, action: string, identifier: string, identifier_kind: string}>  $eventRows
     * @param  array<int, array{key: string, name: string, action: string, identifier: string, identifier_kind: string, should_queue: bool, connection: string, queue: string, unique: bool}>  $jobRows
     * @param  array<int, array{key: string, driver: string, default_queue: string, is_default: bool}>  $queueConnectionRows
     * @param  array<int, array{key: string, name: string, expression: string, command: string, description: string, timezone: string, kind: string, without_overlapping: bool, on_one_server: bool, run_in_background: bool, even_in_maintenance_mode: bool, action: string, identifier: string, identifier_kind: string}>  $scheduledTaskRows
     */
    public function write(
        array $instanceRows,
        array $bindingRows,
        array $dependencyChainRows,
        array $contextualBindingRows = [],
        array $routeRows = [],
        array $routeMiddlewareRows = [],
        array $eventRows = [],
        array $jobRows = [],
        array $queueConnectionRows = [],
        array $scheduledTaskRows = [],
    ): void {
        $this->instanceRows = $instanceRows;
        $this->bindingRows = $bindingRows;
        $this->dependencyChainRows = $dependencyChainRows;
        $this->contextualBindingRows = $contextualBindingRows;
        $this->routeRows = $routeRows;
        $this->routeMiddlewareRows = $routeMiddlewareRows;
        $this->eventRows = $eventRows;
        $this->jobRows = $jobRows;
        $this->queueConnectionRows = $queueConnectionRows;
        $this->scheduledTaskRows = $scheduledTaskRows;
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

    public function hasEventHandledBy(string $eventKey, string $identifier): bool
    {
        foreach ($this->eventRows as $row) {
            if ($row['key'] === $eventKey && $row['identifier'] === $identifier) {
                return true;
            }
        }

        return false;
    }

    public function hasJobHandledBy(string $jobKey, string $identifier): bool
    {
        foreach ($this->jobRows as $row) {
            if ($row['key'] === $jobKey && $row['identifier'] === $identifier) {
                return true;
            }
        }

        return false;
    }

    public function hasQueueConnection(string $key): bool
    {
        foreach ($this->queueConnectionRows as $row) {
            if ($row['key'] === $key) {
                return true;
            }
        }

        return false;
    }

    public function hasScheduledTaskHandledBy(string $identifier, ?string $kind = null): bool
    {
        foreach ($this->scheduledTaskRows as $row) {
            if ($row['identifier'] !== $identifier) {
                continue;
            }

            if ($kind !== null && $row['kind'] !== $kind) {
                continue;
            }

            return true;
        }

        return false;
    }
}
