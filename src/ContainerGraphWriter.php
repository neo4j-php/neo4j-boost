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
WITH row, a
MERGE (c:Abstract {name: row.concrete})
SET c.kind = row.concreteKind
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
MERGE (dep)-[:IDENTIFIED_AS]->(id)
CYPHER;

    private const CYPHER_ABSTRACT_RESOLVES_TO = <<<'CYPHER'
UNWIND $rows AS row
MERGE (id:Abstract {name: row.identifier})
SET id.kind = coalesce(row.identifier_kind, id.kind)
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
MERGE (r)-[:HANDLED_BY]->(id)
CYPHER;

    private const CYPHER_ROUTE_MIDDLEWARE = <<<'CYPHER'
UNWIND $rows AS row
MERGE (r:Route {key: row.route_key})
MERGE (m:Middleware {key: row.middleware_key})
SET m.name = row.middleware_key
MERGE (id:Abstract {name: row.identifier})
SET id.kind = coalesce(row.identifier_kind, id.kind)
MERGE (m)-[:IDENTIFIED_AS]->(id)
MERGE (r)-[u:USES_MIDDLEWARE {order: row.order}]->(m)
SET u.parameters = coalesce(row.parameters, '')
CYPHER;

    private const CYPHER_EVENTS_CLEAR_HANDLED_BY = <<<'CYPHER'
UNWIND $rows AS row
WITH DISTINCT row.key AS eventKey
MATCH (e:Event {key: eventKey})
OPTIONAL MATCH (e)-[old:HANDLED_BY]->()
DELETE old
CYPHER;

    private const CYPHER_EVENTS = <<<'CYPHER'
UNWIND $rows AS row
MERGE (e:Event {key: row.key})
SET e.name = row.name
MERGE (id:Abstract {name: row.identifier})
SET id.kind = coalesce(row.identifier_kind, id.kind)
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

    private const CYPHER_SCHEDULED_TASKS = <<<'CYPHER'
UNWIND $rows AS row
MERGE (t:ScheduledTask {key: row.key})
SET t.name = row.name,
    t.expression = row.expression,
    t.command = row.command,
    t.description = row.description,
    t.timezone = row.timezone,
    t.kind = row.kind,
    t.without_overlapping = row.without_overlapping,
    t.on_one_server = row.on_one_server,
    t.run_in_background = row.run_in_background,
    t.even_in_maintenance_mode = row.even_in_maintenance_mode
WITH t, row
WHERE row.identifier <> ''
MERGE (id:Abstract {name: row.identifier})
SET id.kind = coalesce(row.identifier_kind, id.kind)
MERGE (t)-[h:HANDLED_BY]->(id)
SET h.action = row.action
CYPHER;

    private const CYPHER_AUTH_PROVIDERS = <<<'CYPHER'
UNWIND $rows AS row
MERGE (p:AuthProvider {key: row.key})
SET p.driver = row.driver,
    p.table = row.table
WITH p, row
OPTIONAL MATCH (p)-[old:USES_MODEL]->()
DELETE old
WITH p, row
WHERE row.model <> ''
MERGE (a:Abstract {name: row.model})
SET a.kind = coalesce(row.model_kind, a.kind)
MERGE (p)-[:USES_MODEL]->(a)
CYPHER;

    private const CYPHER_AUTH_GUARDS = <<<'CYPHER'
UNWIND $rows AS row
MERGE (g:AuthGuard {key: row.key})
SET g.driver = row.driver,
    g.is_default = row.is_default
WITH g, row
OPTIONAL MATCH (g)-[old:USES_PROVIDER]->()
DELETE old
WITH g, row
WHERE row.provider <> ''
MERGE (p:AuthProvider {key: row.provider})
MERGE (g)-[:USES_PROVIDER]->(p)
CYPHER;

    private const CYPHER_PASSWORD_BROKERS = <<<'CYPHER'
UNWIND $rows AS row
MERGE (b:PasswordBroker {key: row.key})
SET b.table = row.table,
    b.expire = row.expire,
    b.throttle = row.throttle,
    b.is_default = row.is_default
WITH b, row
OPTIONAL MATCH (b)-[old:USES_PROVIDER]->()
DELETE old
WITH b, row
WHERE row.provider <> ''
MERGE (p:AuthProvider {key: row.provider})
MERGE (b)-[:USES_PROVIDER]->(p)
CYPHER;

    private const CYPHER_POLICIES = <<<'CYPHER'
UNWIND $rows AS row
MERGE (p:Policy {key: row.key})
SET p.name = row.name
WITH p, row
OPTIONAL MATCH (p)-[oldH:HANDLED_BY]->()
DELETE oldH
WITH p, row
OPTIONAL MATCH (p)-[oldM:FOR_MODEL]->()
DELETE oldM
WITH p, row
WHERE row.identifier <> ''
MERGE (id:Abstract {name: row.identifier})
SET id.kind = coalesce(row.identifier_kind, id.kind)
MERGE (p)-[h:HANDLED_BY]->(id)
SET h.action = row.action
WITH p, row
WHERE row.model <> ''
MERGE (m:Abstract {name: row.model})
SET m.kind = coalesce(row.model_kind, m.kind)
MERGE (p)-[:FOR_MODEL]->(m)
CYPHER;

    private const CYPHER_GATE_ABILITIES = <<<'CYPHER'
UNWIND $rows AS row
MERGE (a:GateAbility {key: row.key})
SET a.name = row.name,
    a.handler_kind = row.handler_kind
WITH a, row
OPTIONAL MATCH (a)-[old:HANDLED_BY]->()
DELETE old
WITH a, row
WHERE row.identifier <> ''
MERGE (id:Abstract {name: row.identifier})
SET id.kind = coalesce(row.identifier_kind, id.kind)
MERGE (a)-[h:HANDLED_BY]->(id)
SET h.action = row.action
CYPHER;

    private const CYPHER_NOTIFICATION_CHANNELS = <<<'CYPHER'
UNWIND $rows AS row
MERGE (c:NotificationChannel {key: row.key})
SET c.name = row.name,
    c.kind = row.kind,
    c.is_default = row.is_default
WITH c, row
OPTIONAL MATCH (c)-[old:IDENTIFIED_AS]->()
DELETE old
WITH c, row
WHERE row.resolved_class <> ''
MERGE (id:Abstract {name: row.resolved_class})
SET id.kind = coalesce(row.resolved_class_kind, id.kind)
MERGE (c)-[:IDENTIFIED_AS]->(id)
CYPHER;

    private const CYPHER_NOTIFICATIONS = <<<'CYPHER'
UNWIND $rows AS row
MERGE (n:Notification {key: row.key})
SET n.name = row.name,
    n.should_queue = row.should_queue,
    n.connection = row.connection,
    n.queue = row.queue,
    n.unique = row.unique
MERGE (id:Abstract {name: row.identifier})
SET id.kind = coalesce(row.identifier_kind, id.kind)
MERGE (n)-[h:HANDLED_BY]->(id)
SET h.action = row.action
WITH n, row
OPTIONAL MATCH (n)-[old:USES_CHANNEL]->()
DELETE old
CYPHER;

    private const CYPHER_NOTIFICATION_USES_CHANNEL = <<<'CYPHER'
UNWIND $rows AS row
MERGE (n:Notification {key: row.notification_key})
MERGE (c:NotificationChannel {key: row.channel_key})
SET c.name = coalesce(c.name, row.channel_key),
    c.kind = coalesce(row.channel_kind, c.kind)
MERGE (n)-[u:USES_CHANNEL]->(c)
SET u.order = row.order
WITH c, row
WHERE row.resolved_class <> ''
MERGE (id:Abstract {name: row.resolved_class})
SET id.kind = coalesce(row.resolved_class_kind, id.kind)
MERGE (c)-[:IDENTIFIED_AS]->(id)
CYPHER;

    private const CYPHER_DROP_LEGACY_IDENTIFIERS = <<<'CYPHER'
MATCH (n:Identifier)
DETACH DELETE n
CYPHER;

    private const CYPHER_DROP_LEGACY_ABSTRACT_SECONDARY_LABELS = <<<'CYPHER'
MATCH (a:Abstract)
REMOVE a:Interface, a:Class, a:AbstractType
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
     * @param  array<int, array{key: string, name: string, expression: string, command: string, description: string, timezone: string, kind: string, without_overlapping: bool, on_one_server: bool, run_in_background: bool, even_in_maintenance_mode: bool, action: string, identifier: string, identifier_kind: string}>  $scheduledTaskRows
     * @param  array<int, array{key: string, driver: string, model: string, model_kind: string, table: string}>  $authProviderRows
     * @param  array<int, array{key: string, driver: string, provider: string, is_default: bool}>  $authGuardRows
     * @param  array<int, array{key: string, provider: string, table: string, expire: int, throttle: int, is_default: bool}>  $passwordBrokerRows
     * @param  array<int, array{key: string, name: string, model: string, model_kind: string, identifier: string, identifier_kind: string, action: string}>  $policyRows
     * @param  array<int, array{key: string, name: string, handler_kind: string, identifier: string, identifier_kind: string, action: string}>  $gateAbilityRows
     * @param  array<int, array{key: string, name: string, action: string, identifier: string, identifier_kind: string, should_queue: bool, connection: string, queue: string, unique: bool}>  $notificationRows
     * @param  array<int, array{key: string, name: string, kind: string, resolved_class: string, resolved_class_kind: string, is_default: bool}>  $notificationChannelRows
     * @param  array<int, array{notification_key: string, channel_key: string, channel_kind: string, resolved_class: string, resolved_class_kind: string, order: int}>  $notificationUsesChannelRows
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
        array $authProviderRows = [],
        array $authGuardRows = [],
        array $passwordBrokerRows = [],
        array $policyRows = [],
        array $gateAbilityRows = [],
        array $notificationRows = [],
        array $notificationChannelRows = [],
        array $notificationUsesChannelRows = [],
    ): void {
        $this->validateBindingRows($bindingRows);
        $this->validateDependencyChainRows($dependencyChainRows);
        $this->validateContextualBindingRows($contextualBindingRows);
        $this->validateRouteRows($routeRows);
        $this->validateRouteMiddlewareRows($routeMiddlewareRows);
        $this->validateEventRows($eventRows);
        $this->validateJobRows($jobRows);
        $this->validateQueueConnectionRows($queueConnectionRows);
        $this->validateScheduledTaskRows($scheduledTaskRows);
        $this->validateAuthProviderRows($authProviderRows);
        $this->validateAuthGuardRows($authGuardRows);
        $this->validatePasswordBrokerRows($passwordBrokerRows);
        $this->validatePolicyRows($policyRows);
        $this->validateGateAbilityRows($gateAbilityRows);
        $this->validateNotificationRows($notificationRows);
        $this->validateNotificationChannelRows($notificationChannelRows);
        $this->validateNotificationUsesChannelRows($notificationUsesChannelRows);

        $this->ensureConstraints();
        $this->connection->run(self::CYPHER_DROP_LEGACY_IDENTIFIERS);
        $this->connection->run(self::CYPHER_DROP_LEGACY_ABSTRACT_SECONDARY_LABELS);

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
            // Clear first so removed listeners do not linger; Events may have many HANDLED_BY edges.
            $this->connection->run(self::CYPHER_EVENTS_CLEAR_HANDLED_BY, ['rows' => $eventRows]);
            $this->connection->run(self::CYPHER_EVENTS, ['rows' => $eventRows]);
        }
        if ($queueConnectionRows !== []) {
            $this->connection->run(self::CYPHER_QUEUE_CONNECTIONS, ['rows' => $queueConnectionRows]);
        }
        if ($jobRows !== []) {
            $this->connection->run(self::CYPHER_JOBS, ['rows' => $jobRows]);
        }
        if ($scheduledTaskRows !== []) {
            $this->connection->run(self::CYPHER_SCHEDULED_TASKS, ['rows' => $scheduledTaskRows]);
        }
        if ($authProviderRows !== []) {
            $this->connection->run(self::CYPHER_AUTH_PROVIDERS, ['rows' => $authProviderRows]);
        }
        if ($authGuardRows !== []) {
            $this->connection->run(self::CYPHER_AUTH_GUARDS, ['rows' => $authGuardRows]);
        }
        if ($passwordBrokerRows !== []) {
            $this->connection->run(self::CYPHER_PASSWORD_BROKERS, ['rows' => $passwordBrokerRows]);
        }
        if ($policyRows !== []) {
            $this->connection->run(self::CYPHER_POLICIES, ['rows' => $policyRows]);
        }
        if ($gateAbilityRows !== []) {
            $this->connection->run(self::CYPHER_GATE_ABILITIES, ['rows' => $gateAbilityRows]);
        }
        if ($notificationChannelRows !== []) {
            $this->connection->run(self::CYPHER_NOTIFICATION_CHANNELS, ['rows' => $notificationChannelRows]);
        }
        if ($notificationRows !== []) {
            $this->connection->run(self::CYPHER_NOTIFICATIONS, ['rows' => $notificationRows]);
        }
        if ($notificationUsesChannelRows !== []) {
            $this->connection->run(self::CYPHER_NOTIFICATION_USES_CHANNEL, ['rows' => $notificationUsesChannelRows]);
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
            'events_clear_handled_by' => self::CYPHER_EVENTS_CLEAR_HANDLED_BY,
            'events' => self::CYPHER_EVENTS,
            'jobs' => self::CYPHER_JOBS,
            'queue_connections' => self::CYPHER_QUEUE_CONNECTIONS,
            'scheduled_tasks' => self::CYPHER_SCHEDULED_TASKS,
            'auth_providers' => self::CYPHER_AUTH_PROVIDERS,
            'auth_guards' => self::CYPHER_AUTH_GUARDS,
            'password_brokers' => self::CYPHER_PASSWORD_BROKERS,
            'policies' => self::CYPHER_POLICIES,
            'gate_abilities' => self::CYPHER_GATE_ABILITIES,
            'notification_channels' => self::CYPHER_NOTIFICATION_CHANNELS,
            'notifications' => self::CYPHER_NOTIFICATIONS,
            'notification_uses_channel' => self::CYPHER_NOTIFICATION_USES_CHANNEL,
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
     * @param  array<int, array{key: string, name: string, expression: string, command: string, description: string, timezone: string, kind: string, without_overlapping: bool, on_one_server: bool, run_in_background: bool, even_in_maintenance_mode: bool, action: string, identifier: string, identifier_kind: string}>  $scheduledTaskRows
     */
    private function validateScheduledTaskRows(array $scheduledTaskRows): void
    {
        foreach ($scheduledTaskRows as $row) {
            foreach (['key', 'name', 'expression', 'command', 'description', 'timezone', 'kind', 'action', 'identifier', 'identifier_kind'] as $key) {
                if (! array_key_exists($key, $row) || ! is_string($row[$key])) {
                    throw new \InvalidArgumentException("Scheduled task row is missing string {$key}");
                }
            }

            foreach (['without_overlapping', 'on_one_server', 'run_in_background', 'even_in_maintenance_mode'] as $key) {
                if (! array_key_exists($key, $row) || ! is_bool($row[$key])) {
                    throw new \InvalidArgumentException("Scheduled task row is missing boolean {$key}");
                }
            }
        }
    }

    /**
     * @param  array<int, array{key: string, driver: string, model: string, model_kind: string, table: string}>  $authProviderRows
     */
    private function validateAuthProviderRows(array $authProviderRows): void
    {
        foreach ($authProviderRows as $row) {
            foreach (['key', 'driver', 'model', 'model_kind', 'table'] as $key) {
                if (! array_key_exists($key, $row) || ! is_string($row[$key])) {
                    throw new \InvalidArgumentException("Auth provider row is missing string {$key}");
                }
            }
        }
    }

    /**
     * @param  array<int, array{key: string, driver: string, provider: string, is_default: bool}>  $authGuardRows
     */
    private function validateAuthGuardRows(array $authGuardRows): void
    {
        foreach ($authGuardRows as $row) {
            foreach (['key', 'driver', 'provider'] as $key) {
                if (! array_key_exists($key, $row) || ! is_string($row[$key])) {
                    throw new \InvalidArgumentException("Auth guard row is missing string {$key}");
                }
            }

            if (! array_key_exists('is_default', $row) || ! is_bool($row['is_default'])) {
                throw new \InvalidArgumentException('Auth guard row is missing boolean is_default');
            }
        }
    }

    /**
     * @param  array<int, array{key: string, provider: string, table: string, expire: int, throttle: int, is_default: bool}>  $passwordBrokerRows
     */
    private function validatePasswordBrokerRows(array $passwordBrokerRows): void
    {
        foreach ($passwordBrokerRows as $row) {
            foreach (['key', 'provider', 'table'] as $key) {
                if (! array_key_exists($key, $row) || ! is_string($row[$key])) {
                    throw new \InvalidArgumentException("Password broker row is missing string {$key}");
                }
            }

            foreach (['expire', 'throttle'] as $key) {
                if (! array_key_exists($key, $row) || ! is_int($row[$key])) {
                    throw new \InvalidArgumentException("Password broker row is missing integer {$key}");
                }
            }

            if (! array_key_exists('is_default', $row) || ! is_bool($row['is_default'])) {
                throw new \InvalidArgumentException('Password broker row is missing boolean is_default');
            }
        }
    }

    /**
     * @param  array<int, array{key: string, name: string, model: string, model_kind: string, identifier: string, identifier_kind: string, action: string}>  $policyRows
     */
    private function validatePolicyRows(array $policyRows): void
    {
        foreach ($policyRows as $row) {
            foreach (['key', 'name', 'model', 'model_kind', 'identifier', 'identifier_kind', 'action'] as $key) {
                if (! array_key_exists($key, $row) || ! is_string($row[$key])) {
                    throw new \InvalidArgumentException("Policy row is missing string {$key}");
                }
            }
        }
    }

    /**
     * @param  array<int, array{key: string, name: string, handler_kind: string, identifier: string, identifier_kind: string, action: string}>  $gateAbilityRows
     */
    private function validateGateAbilityRows(array $gateAbilityRows): void
    {
        foreach ($gateAbilityRows as $row) {
            foreach (['key', 'name', 'handler_kind', 'identifier', 'identifier_kind', 'action'] as $key) {
                if (! array_key_exists($key, $row) || ! is_string($row[$key])) {
                    throw new \InvalidArgumentException("Gate ability row is missing string {$key}");
                }
            }
        }
    }

    /**
     * @param  array<int, array{key: string, name: string, action: string, identifier: string, identifier_kind: string, should_queue: bool, connection: string, queue: string, unique: bool}>  $notificationRows
     */
    private function validateNotificationRows(array $notificationRows): void
    {
        foreach ($notificationRows as $row) {
            foreach (['key', 'name', 'action', 'identifier', 'identifier_kind', 'connection', 'queue'] as $key) {
                if (! array_key_exists($key, $row) || ! is_string($row[$key])) {
                    throw new \InvalidArgumentException("Notification row is missing string {$key}");
                }
            }

            foreach (['should_queue', 'unique'] as $key) {
                if (! array_key_exists($key, $row) || ! is_bool($row[$key])) {
                    throw new \InvalidArgumentException("Notification row is missing boolean {$key}");
                }
            }
        }
    }

    /**
     * @param  array<int, array{key: string, name: string, kind: string, resolved_class: string, resolved_class_kind: string, is_default: bool}>  $notificationChannelRows
     */
    private function validateNotificationChannelRows(array $notificationChannelRows): void
    {
        foreach ($notificationChannelRows as $row) {
            foreach (['key', 'name', 'kind', 'resolved_class', 'resolved_class_kind'] as $key) {
                if (! array_key_exists($key, $row) || ! is_string($row[$key])) {
                    throw new \InvalidArgumentException("Notification channel row is missing string {$key}");
                }
            }

            if (! array_key_exists('is_default', $row) || ! is_bool($row['is_default'])) {
                throw new \InvalidArgumentException('Notification channel row is missing boolean is_default');
            }
        }
    }

    /**
     * @param  array<int, array{notification_key: string, channel_key: string, channel_kind: string, resolved_class: string, resolved_class_kind: string, order: int}>  $notificationUsesChannelRows
     */
    private function validateNotificationUsesChannelRows(array $notificationUsesChannelRows): void
    {
        foreach ($notificationUsesChannelRows as $row) {
            foreach (['notification_key', 'channel_key', 'channel_kind', 'resolved_class', 'resolved_class_kind'] as $key) {
                if (! array_key_exists($key, $row) || ! is_string($row[$key])) {
                    throw new \InvalidArgumentException("Notification USES_CHANNEL row is missing string {$key}");
                }
            }

            if (! array_key_exists('order', $row) || ! is_int($row['order'])) {
                throw new \InvalidArgumentException('Notification USES_CHANNEL row is missing integer order');
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
