<?php

namespace Neo4j\LaravelBoost;

use Neo4j\LaravelBoost\StaticAnalysis\DependencyEdgeSource;
use Neo4j\LaravelBoost\Support\ContainerGraphConnection;
use Neo4j\LaravelBoost\Support\Graph\BindsToType;
use Neo4j\LaravelBoost\Support\Graph\DependencyAccessType;
use Neo4j\LaravelBoost\Support\Graph\DependencyEdgeConfidence;
use Neo4j\LaravelBoost\Support\Graph\DependencyEdgeProvenance;
use Neo4j\LaravelBoost\Support\Graph\ResolvesToLifetime;
use Neo4j\LaravelBoost\Support\Graph\RuntimeGraphModel;

class ContainerGraphWriter
{
    private const CYPHER_BINDINGS = <<<'CYPHER'
UNWIND $rows AS row
MERGE (a:Abstract {name: row.abstract})
SET a.kind = row.abstractKind
REMOVE a:Interface, a:Class, a:AbstractType
FOREACH (_ IN CASE WHEN row.abstractKind = 'Interface' THEN [1] ELSE [] END | SET a:Interface)
FOREACH (_ IN CASE WHEN row.abstractKind = 'Class' THEN [1] ELSE [] END | SET a:Class)
FOREACH (_ IN CASE WHEN row.abstractKind <> 'Interface' AND row.abstractKind <> 'Class' THEN [1] ELSE [] END | SET a:AbstractType)
WITH row, a
MERGE (c:Abstract {name: row.concrete})
SET c.kind = row.concreteKind
REMOVE c:Interface, c:Class, c:AbstractType
FOREACH (_ IN CASE WHEN row.concreteKind = 'Interface' THEN [1] ELSE [] END | SET c:Interface)
FOREACH (_ IN CASE WHEN row.concreteKind = 'Class' THEN [1] ELSE [] END | SET c:Class)
FOREACH (_ IN CASE WHEN row.concreteKind <> 'Interface' AND row.concreteKind <> 'Class' THEN [1] ELSE [] END | SET c:AbstractType)
MERGE (a)-[r:BINDS_TO]->(c)
SET r.type = row.type,
    r.source = row.source,
    r.confidence = row.confidence,
    r.provenance = row.provenance,
    r.remarks = coalesce(row.remarks, '')
CYPHER;

    private const CYPHER_INSTANCES = <<<'CYPHER'
UNWIND $rows AS row
MERGE (:Instance {name: row.class})
CYPHER;

    private const CYPHER_IDENTIFIED_AS = <<<'CYPHER'
UNWIND $rows AS row
MERGE (dep:Dependency {key: row.dependency_key})
SET dep.access = row.access
MERGE (id:Abstract {name: row.identifier})
SET id.kind = row.identifier_kind,
    id.reason = coalesce(row.reason, id.reason)
REMOVE id:Interface, id:Class, id:AbstractType
FOREACH (_ IN CASE WHEN row.identifier_kind = 'Interface' THEN [1] ELSE [] END | SET id:Interface)
FOREACH (_ IN CASE WHEN row.identifier_kind = 'Class' THEN [1] ELSE [] END | SET id:Class)
FOREACH (_ IN CASE WHEN row.identifier_kind <> 'Interface' AND row.identifier_kind <> 'Class' THEN [1] ELSE [] END | SET id:AbstractType)
MERGE (dep)-[:IDENTIFIED_AS]->(id)
CYPHER;

    private const CYPHER_ABSTRACT_RESOLVES_TO = <<<'CYPHER'
UNWIND $rows AS row
MERGE (id:Abstract {name: row.identifier})
SET id.kind = coalesce(row.identifier_kind, id.kind)
REMOVE id:Interface, id:Class, id:AbstractType
FOREACH (_ IN CASE WHEN row.identifier_kind = 'Interface' THEN [1] ELSE [] END | SET id:Interface)
FOREACH (_ IN CASE WHEN row.identifier_kind = 'Class' THEN [1] ELSE [] END | SET id:Class)
FOREACH (_ IN CASE WHEN row.identifier_kind <> 'Interface' AND row.identifier_kind <> 'Class' THEN [1] ELSE [] END | SET id:AbstractType)
MERGE (i:Instance {name: row.instance})
MERGE (id)-[r:RESOLVES_TO]->(i)
SET r.lifetime = row.lifetime
CYPHER;

    private const CYPHER_INSTANCE_DEPENDS_ON = <<<'CYPHER'
UNWIND $rows AS row
MERGE (i:Instance {name: row.instance})
MERGE (dep:Dependency {key: row.dependency_key})
MERGE (i)-[d:DEPENDS_ON]->(dep)
SET d.file = row.file,
    d.line = row.line,
    d.via = row.via,
    d.type = row.injection_type,
    d.method = row.method,
    d.parameter = row.parameter,
    d.helper = coalesce(row.helper, ''),
    d.source = row.source,
    d.confidence = row.confidence,
    d.provenance = row.provenance,
    d.remarks = coalesce(row.remarks, ''),
    d.catalog_source = coalesce(row.catalog_source, '')
CYPHER;

    private const CYPHER_CONTEXTUAL_BINDS = <<<'CYPHER'
UNWIND $rows AS row
MERGE (i:Instance {name: row.when})
MERGE (g:Abstract {name: row.give})
SET g.kind = row.give_kind,
    g.reason = CASE WHEN row.reason <> '' THEN row.reason ELSE g.reason END
REMOVE g:Interface, g:Class, g:AbstractType
FOREACH (_ IN CASE WHEN row.give_kind = 'Interface' THEN [1] ELSE [] END | SET g:Interface)
FOREACH (_ IN CASE WHEN row.give_kind = 'Class' THEN [1] ELSE [] END | SET g:Class)
FOREACH (_ IN CASE WHEN row.give_kind <> 'Interface' AND row.give_kind <> 'Class' THEN [1] ELSE [] END | SET g:AbstractType)
MERGE (i)-[r:CONTEXTUAL_BINDS]->(g)
SET r.needs = row.needs,
    r.needs_kind = row.needs_kind,
    r.reason = CASE WHEN row.reason <> '' THEN row.reason ELSE r.reason END
CYPHER;

    private const CYPHER_ROUTES = <<<'CYPHER'
UNWIND $rows AS row
MERGE (r:Route {key: row.key})
SET r.uri = row.uri,
    r.methods = row.methods,
    r.name = row.name,
    r.action = row.action
REMOVE r.route_name
MERGE (id:Abstract {name: row.identifier})
SET id.kind = coalesce(row.identifier_kind, id.kind)
REMOVE id:Interface, id:Class, id:AbstractType
FOREACH (_ IN CASE WHEN row.identifier_kind = 'Interface' THEN [1] ELSE [] END | SET id:Interface)
FOREACH (_ IN CASE WHEN row.identifier_kind = 'Class' THEN [1] ELSE [] END | SET id:Class)
FOREACH (_ IN CASE WHEN row.identifier_kind <> 'Interface' AND row.identifier_kind <> 'Class' THEN [1] ELSE [] END | SET id:AbstractType)
MERGE (r)-[:HANDLED_BY]->(id)
CYPHER;

    private const CYPHER_ROUTE_MIDDLEWARE = <<<'CYPHER'
UNWIND $rows AS row
MERGE (r:Route {key: row.route_key})
MERGE (m:Middleware {key: row.middleware_key})
SET m.name = row.middleware_key
MERGE (id:Abstract {name: row.identifier})
SET id.kind = coalesce(row.identifier_kind, id.kind)
REMOVE id:Interface, id:Class, id:AbstractType
FOREACH (_ IN CASE WHEN row.identifier_kind = 'Interface' THEN [1] ELSE [] END | SET id:Interface)
FOREACH (_ IN CASE WHEN row.identifier_kind = 'Class' THEN [1] ELSE [] END | SET id:Class)
FOREACH (_ IN CASE WHEN row.identifier_kind <> 'Interface' AND row.identifier_kind <> 'Class' THEN [1] ELSE [] END | SET id:AbstractType)
MERGE (m)-[:IDENTIFIED_AS]->(id)
MERGE (r)-[u:USES_MIDDLEWARE {order: row.order}]->(m)
SET u.parameters = coalesce(row.parameters, '')
CYPHER;

    private const CYPHER_EVENTS = <<<'CYPHER'
UNWIND $rows AS row
MERGE (e:Event {key: row.key})
SET e.name = row.name
MERGE (id:Abstract {name: row.identifier})
SET id.kind = coalesce(row.identifier_kind, id.kind)
REMOVE id:Interface, id:Class, id:AbstractType
FOREACH (_ IN CASE WHEN row.identifier_kind = 'Interface' THEN [1] ELSE [] END | SET id:Interface)
FOREACH (_ IN CASE WHEN row.identifier_kind = 'Class' THEN [1] ELSE [] END | SET id:Class)
FOREACH (_ IN CASE WHEN row.identifier_kind <> 'Interface' AND row.identifier_kind <> 'Class' THEN [1] ELSE [] END | SET id:AbstractType)
MERGE (e)-[h:HANDLED_BY]->(id)
SET h.action = row.action
CYPHER;

    private const CYPHER_QUEUE_CONNECTIONS = <<<'CYPHER'
UNWIND $rows AS row
MERGE (q:QueueConnection {key: row.key})
SET q.driver = row.driver,
    q.default_queue = row.default_queue,
    q.is_default = row.is_default
CYPHER;

    private const CYPHER_JOBS = <<<'CYPHER'
UNWIND $rows AS row
MERGE (j:Job {key: row.key})
SET j.name = row.name,
    j.should_queue = row.should_queue,
    j.connection = row.connection,
    j.queue = row.queue,
    j.unique = row.unique
MERGE (id:Abstract {name: row.identifier})
SET id.kind = coalesce(row.identifier_kind, id.kind)
REMOVE id:Interface, id:Class, id:AbstractType
FOREACH (_ IN CASE WHEN row.identifier_kind = 'Interface' THEN [1] ELSE [] END | SET id:Interface)
FOREACH (_ IN CASE WHEN row.identifier_kind = 'Class' THEN [1] ELSE [] END | SET id:Class)
FOREACH (_ IN CASE WHEN row.identifier_kind <> 'Interface' AND row.identifier_kind <> 'Class' THEN [1] ELSE [] END | SET id:AbstractType)
MERGE (j)-[h:HANDLED_BY]->(id)
SET h.action = row.action
WITH j, row
OPTIONAL MATCH (j)-[old:USES_CONNECTION]->()
DELETE old
WITH j, row
WHERE row.connection <> ''
MERGE (q:QueueConnection {key: row.connection})
MERGE (j)-[:USES_CONNECTION]->(q)
CYPHER;

    private const CYPHER_DROP_LEGACY_IDENTIFIERS = <<<'CYPHER'
MATCH (n:Identifier)
DETACH DELETE n
CYPHER;

    public function __construct(
        private ContainerGraphConnection $connection,
    ) {}

    public function connect(): void
    {
        $this->connection->connect();
    }

    /**
     * Ensure unique identity constraints for runtime graph nodes.
     */
    public function ensureConstraints(): void
    {
        foreach (RuntimeGraphModel::constraintStatements() as $statement) {
            $this->connection->run($statement);
        }
    }

    /**
     * @param  array<int, array{class: string}>  $instanceRows
     * @param  array<int, array{abstract: string, abstractKind: string, concrete: string, concreteKind: string, shared: bool, type: string, source: string, confidence: string, provenance: string, remarks: string}>  $bindingRows
     * @param  array<int, array{instance: string, dependency_key: string, access: string, identifier: string, identifier_kind: string, lifetime: string, injection_type: string, method: string, parameter: string, via: string, file: string, line: int, source: string, confidence: string, provenance: string, remarks: string, catalog_source?: string}>  $dependencyChainRows
     * @param  array<int, array{when: string, when_kind: string, needs: string, needs_kind: string, give: string, give_kind: string, reason: string}>  $contextualBindingRows
     * @param  array<int, array{key: string, uri: string, methods: string, name: string, action: string, identifier: string, identifier_kind: string}>  $routeRows
     * @param  array<int, array{route_key: string, middleware_key: string, identifier: string, identifier_kind: string, parameters: string, order: int}>  $routeMiddlewareRows
     * @param  array<int, array{key: string, name: string, action: string, identifier: string, identifier_kind: string}>  $eventRows
     * @param  array<int, array{key: string, name: string, action: string, identifier: string, identifier_kind: string, should_queue: bool, connection: string, queue: string, unique: bool}>  $jobRows
     * @param  array<int, array{key: string, driver: string, default_queue: string, is_default: bool}>  $queueConnectionRows
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
    ): void {
        $this->validateBindingRows($bindingRows);
        $this->validateDependencyChainRows($dependencyChainRows);
        $this->validateContextualBindingRows($contextualBindingRows);
        $this->validateRouteRows($routeRows);
        $this->validateRouteMiddlewareRows($routeMiddlewareRows);
        $this->validateEventRows($eventRows);
        $this->validateJobRows($jobRows);
        $this->validateQueueConnectionRows($queueConnectionRows);

        $this->ensureConstraints();
        $this->connection->run(self::CYPHER_DROP_LEGACY_IDENTIFIERS);

        if ($instanceRows !== []) {
            $this->connection->run(self::CYPHER_INSTANCES, ['rows' => $instanceRows]);
        }
        if ($bindingRows !== []) {
            $this->connection->run(self::CYPHER_BINDINGS, ['rows' => $bindingRows]);
        }
        if ($dependencyChainRows !== []) {
            $this->connection->run(self::CYPHER_IDENTIFIED_AS, ['rows' => $dependencyChainRows]);

            $instanceChains = array_values(array_filter(
                $dependencyChainRows,
                static fn (array $row): bool => ($row['instance'] ?? '') !== '',
            ));

            if ($instanceChains !== []) {
                $this->connection->run(self::CYPHER_INSTANCE_DEPENDS_ON, ['rows' => $instanceChains]);
            }
        }

        $abstractResolveRows = $this->buildAbstractResolveRows($instanceRows, $bindingRows, $dependencyChainRows);
        if ($abstractResolveRows !== []) {
            $this->connection->run(self::CYPHER_ABSTRACT_RESOLVES_TO, ['rows' => $abstractResolveRows]);
        }

        if ($contextualBindingRows !== []) {
            $this->connection->run(self::CYPHER_CONTEXTUAL_BINDS, ['rows' => $contextualBindingRows]);
        }
        if ($routeRows !== []) {
            $this->connection->run(self::CYPHER_ROUTES, ['rows' => $routeRows]);
        }
        if ($routeMiddlewareRows !== []) {
            $this->connection->run(self::CYPHER_ROUTE_MIDDLEWARE, ['rows' => $routeMiddlewareRows]);
        }
        if ($eventRows !== []) {
            $this->connection->run(self::CYPHER_EVENTS, ['rows' => $eventRows]);
        }
        if ($queueConnectionRows !== []) {
            $this->connection->run(self::CYPHER_QUEUE_CONNECTIONS, ['rows' => $queueConnectionRows]);
        }
        if ($jobRows !== []) {
            $this->connection->run(self::CYPHER_JOBS, ['rows' => $jobRows]);
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
            'identified_as' => self::CYPHER_IDENTIFIED_AS,
            'abstract_resolves_to' => self::CYPHER_ABSTRACT_RESOLVES_TO,
            'instance_depends_on' => self::CYPHER_INSTANCE_DEPENDS_ON,
            'contextual_binds' => self::CYPHER_CONTEXTUAL_BINDS,
            'routes' => self::CYPHER_ROUTES,
            'route_middleware' => self::CYPHER_ROUTE_MIDDLEWARE,
            'events' => self::CYPHER_EVENTS,
            'jobs' => self::CYPHER_JOBS,
            'queue_connections' => self::CYPHER_QUEUE_CONNECTIONS,
        ];
    }

    /**
     * @param  array<int, array{abstract: string, abstractKind: string, concrete: string, concreteKind: string, shared: bool, type: string, source: string, confidence: string, provenance: string, remarks: string}>  $bindingRows
     */
    private function validateBindingRows(array $bindingRows): void
    {
        foreach ($bindingRows as $row) {
            BindsToType::assertAllowed((string) ($row['type'] ?? ''));
            DependencyEdgeSource::assertAllowed((string) ($row['source'] ?? ''));
            DependencyEdgeConfidence::assertAllowed((string) ($row['confidence'] ?? ''));
            DependencyEdgeProvenance::assertAllowed((string) ($row['provenance'] ?? ''));
        }
    }

    /**
     * @param  array<int, array{instance: string, dependency_key: string, access: string, identifier: string, identifier_kind: string, lifetime: string, injection_type: string, method: string, parameter: string, via: string, file: string, line: int, source: string, confidence: string, provenance: string, remarks: string, catalog_source?: string}>  $dependencyChainRows
     */
    private function validateDependencyChainRows(array $dependencyChainRows): void
    {
        foreach ($dependencyChainRows as $row) {
            DependencyAccessType::assertAllowed((string) ($row['access'] ?? ''));
            ResolvesToLifetime::assertAllowed((string) ($row['lifetime'] ?? ''));

            DependencyEdgeSource::assertAllowed((string) ($row['source'] ?? ''));
            DependencyEdgeConfidence::assertAllowed((string) ($row['confidence'] ?? ''));
            DependencyEdgeProvenance::assertAllowed((string) ($row['provenance'] ?? ''));

            foreach (['dependency_key', 'identifier', 'identifier_kind', 'via', 'file', 'injection_type', 'method', 'parameter', 'source', 'confidence', 'provenance', 'remarks'] as $key) {
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

    /**
     * @param  array<int, array{when: string, when_kind: string, needs: string, needs_kind: string, give: string, give_kind: string, reason: string}>  $contextualBindingRows
     */
    private function validateContextualBindingRows(array $contextualBindingRows): void
    {
        foreach ($contextualBindingRows as $row) {
            foreach (['when', 'when_kind', 'needs', 'needs_kind', 'give', 'give_kind', 'reason'] as $key) {
                if (! array_key_exists($key, $row) || ! is_string($row[$key])) {
                    throw new \InvalidArgumentException("Contextual binding row is missing string {$key}");
                }
            }
        }
    }

    /**
     * @param  array<int, array{key: string, uri: string, methods: string, name: string, action: string, identifier: string, identifier_kind: string}>  $routeRows
     */
    private function validateRouteRows(array $routeRows): void
    {
        foreach ($routeRows as $row) {
            foreach (['key', 'uri', 'methods', 'name', 'action', 'identifier', 'identifier_kind'] as $key) {
                if (! array_key_exists($key, $row) || ! is_string($row[$key])) {
                    throw new \InvalidArgumentException("Route row is missing string {$key}");
                }
            }
        }
    }

    /**
     * @param  array<int, array{route_key: string, middleware_key: string, identifier: string, identifier_kind: string, parameters: string, order: int}>  $routeMiddlewareRows
     */
    private function validateRouteMiddlewareRows(array $routeMiddlewareRows): void
    {
        foreach ($routeMiddlewareRows as $row) {
            foreach (['route_key', 'middleware_key', 'identifier', 'identifier_kind', 'parameters'] as $key) {
                if (! array_key_exists($key, $row) || ! is_string($row[$key])) {
                    throw new \InvalidArgumentException("Route middleware row is missing string {$key}");
                }
            }

            if (! array_key_exists('order', $row) || ! is_int($row['order'])) {
                throw new \InvalidArgumentException('Route middleware row is missing integer order');
            }
        }
    }

    /**
     * @param  array<int, array{key: string, name: string, action: string, identifier: string, identifier_kind: string}>  $eventRows
     */
    private function validateEventRows(array $eventRows): void
    {
        foreach ($eventRows as $row) {
            foreach (['key', 'name', 'action', 'identifier', 'identifier_kind'] as $key) {
                if (! array_key_exists($key, $row) || ! is_string($row[$key])) {
                    throw new \InvalidArgumentException("Event row is missing string {$key}");
                }
            }
        }
    }

    /**
     * @param  array<int, array{key: string, name: string, action: string, identifier: string, identifier_kind: string, should_queue: bool, connection: string, queue: string, unique: bool}>  $jobRows
     */
    private function validateJobRows(array $jobRows): void
    {
        foreach ($jobRows as $row) {
            foreach (['key', 'name', 'action', 'identifier', 'identifier_kind', 'connection', 'queue'] as $key) {
                if (! array_key_exists($key, $row) || ! is_string($row[$key])) {
                    throw new \InvalidArgumentException("Job row is missing string {$key}");
                }
            }

            foreach (['should_queue', 'unique'] as $key) {
                if (! array_key_exists($key, $row) || ! is_bool($row[$key])) {
                    throw new \InvalidArgumentException("Job row is missing boolean {$key}");
                }
            }
        }
    }

    /**
     * @param  array<int, array{key: string, driver: string, default_queue: string, is_default: bool}>  $queueConnectionRows
     */
    private function validateQueueConnectionRows(array $queueConnectionRows): void
    {
        foreach ($queueConnectionRows as $row) {
            foreach (['key', 'driver', 'default_queue'] as $key) {
                if (! array_key_exists($key, $row) || ! is_string($row[$key])) {
                    throw new \InvalidArgumentException("Queue connection row is missing string {$key}");
                }
            }

            if (! array_key_exists('is_default', $row) || ! is_bool($row['is_default'])) {
                throw new \InvalidArgumentException('Queue connection row is missing boolean is_default');
            }
        }
    }

    /**
     * @param  array<int, array{class: string}>  $instanceRows
     * @param  array<int, array{abstract: string, abstractKind: string, concrete: string, concreteKind: string, shared: bool, type: string, source: string, confidence: string, provenance: string, remarks: string}>  $bindingRows
     * @param  array<int, array{instance: string, dependency_key: string, access: string, identifier: string, identifier_kind: string, lifetime: string, injection_type: string, method: string, parameter: string, via: string, file: string, line: int, source: string, confidence: string, provenance: string, remarks: string, catalog_source?: string}>  $dependencyChainRows
     * @return array<int, array{identifier: string, identifier_kind: string, instance: string, lifetime: string}>
     */
    private function buildAbstractResolveRows(array $instanceRows, array $bindingRows, array $dependencyChainRows): array
    {
        $rows = [];
        $seen = [];

        $add = static function (string $identifier, string $identifierKind, string $instance, string $lifetime) use (&$rows, &$seen): void {
            if ($identifier === '' || $instance === '') {
                return;
            }

            $key = $identifier."\0".$instance;
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $rows[] = [
                'identifier' => $identifier,
                'identifier_kind' => $identifierKind !== '' ? $identifierKind : 'Class',
                'instance' => $instance,
                'lifetime' => $lifetime,
            ];
        };

        foreach ($instanceRows as $row) {
            $class = (string) ($row['class'] ?? '');
            $add($class, 'Class', $class, ResolvesToLifetime::Bind->value);
        }

        foreach ($bindingRows as $row) {
            if (($row['concreteKind'] ?? '') !== 'Class') {
                continue;
            }

            $lifetime = ! empty($row['shared'])
                ? ResolvesToLifetime::Singleton->value
                : ResolvesToLifetime::Bind->value;

            $add(
                (string) $row['abstract'],
                (string) ($row['abstractKind'] ?? 'Class'),
                (string) $row['concrete'],
                $lifetime,
            );
            $add(
                (string) $row['concrete'],
                'Class',
                (string) $row['concrete'],
                $lifetime,
            );
        }

        foreach ($dependencyChainRows as $row) {
            $identifier = (string) ($row['identifier'] ?? '');
            $kind = (string) ($row['identifier_kind'] ?? '');
            $lifetime = (string) ($row['lifetime'] ?? ResolvesToLifetime::Bind->value);

            if ($kind === 'Class' || class_exists($identifier)) {
                $add($identifier, $kind !== '' ? $kind : 'Class', $identifier, $lifetime);
            }
        }

        return $rows;
    }
}
