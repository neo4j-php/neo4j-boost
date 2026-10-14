<?php

namespace Neo4j\LaravelBoost;

use Illuminate\Support\Facades\DB;
use Neo4j\LaravelBoost\ContainerGraph\Models\AbstractNode;
use Neo4j\LaravelBoost\ContainerGraph\Models\AuthGuardNode;
use Neo4j\LaravelBoost\ContainerGraph\Models\AuthProviderNode;
use Neo4j\LaravelBoost\ContainerGraph\Models\BroadcastChannelNode;
use Neo4j\LaravelBoost\ContainerGraph\Models\BroadcastConnectionNode;
use Neo4j\LaravelBoost\ContainerGraph\Models\EventNode;
use Neo4j\LaravelBoost\ContainerGraph\Models\GateAbilityNode;
use Neo4j\LaravelBoost\ContainerGraph\Models\JobNode;
use Neo4j\LaravelBoost\ContainerGraph\Models\MailableNode;
use Neo4j\LaravelBoost\ContainerGraph\Models\MailerNode;
use Neo4j\LaravelBoost\ContainerGraph\Models\NotificationChannelNode;
use Neo4j\LaravelBoost\ContainerGraph\Models\NotificationNode;
use Neo4j\LaravelBoost\ContainerGraph\Models\PasswordBrokerNode;
use Neo4j\LaravelBoost\ContainerGraph\Models\PolicyNode;
use Neo4j\LaravelBoost\ContainerGraph\Models\QueueConnectionNode;
use Neo4j\LaravelBoost\ContainerGraph\Models\ScheduledTaskNode;
use Neo4j\LaravelBoost\StaticAnalysis\DependencyEdgeSource;
use Neo4j\LaravelBoost\Support\ContainerGraphConnection;
use Neo4j\LaravelBoost\Support\Graph\BindsToType;
use Neo4j\LaravelBoost\Support\Graph\DependencyAccessType;
use Neo4j\LaravelBoost\Support\Graph\DependencyEdgeConfidence;
use Neo4j\LaravelBoost\Support\Graph\DependencyEdgeProvenance;
use Neo4j\LaravelBoost\Support\Graph\ResolvesToLifetime;
use Neo4j\LaravelBoost\Support\Graph\RuntimeGraphModel;
use Neo4j\LaravelBoost\Support\Neo4jBoltClient;
use Neo4j\Neo4jLaravel\Neo4jQueryBuilder;
use RuntimeException;

class ContainerGraphWriter
{
    /** Label used by graphs written before Identifier was merged into Abstract. */
    private const LEGACY_IDENTIFIER_LABEL = 'Identifier';

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
     * @param  array<int, array{key: string, transport: string, nested_mailers: string, is_default: bool}>  $mailerRows
     * @param  array<int, array{key: string, name: string, action: string, identifier: string, identifier_kind: string, should_queue: bool, mailer: string, connection: string, queue: string, unique: bool}>  $mailableRows
     * @param  array<int, array{key: string, driver: string, is_default: bool}>  $broadcastConnectionRows
     * @param  array<int, array{key: string, name: string, guards: string, action: string, identifier: string, identifier_kind: string}>  $broadcastChannelRows
     * @param  array<int, array{key: string, name: string, description: string, hidden: bool, aliases: string, kind: string, source: string, action: string, identifier: string, identifier_kind: string}>  $artisanCommandRows
     * @param  array<int, array{key: string, name: string, deferred: bool, loaded: bool, source: string}>  $serviceProviderRows
     * @param  array<int, array{provider_key: string, abstract: string, abstract_kind: string, type: string, via: string}>  $serviceProviderRegistersRows
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
        array $mailerRows = [],
        array $mailableRows = [],
        array $broadcastConnectionRows = [],
        array $broadcastChannelRows = [],
        array $artisanCommandRows = [],
        array $serviceProviderRows = [],
        array $serviceProviderRegistersRows = [],
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
        $this->validateMailerRows($mailerRows);
        $this->validateMailableRows($mailableRows);
        $this->validateBroadcastConnectionRows($broadcastConnectionRows);
        $this->validateBroadcastChannelRows($broadcastChannelRows);
        $this->validateArtisanCommandRows($artisanCommandRows);
        $this->validateServiceProviderRows($serviceProviderRows, $serviceProviderRegistersRows);

        $this->ensureConstraints();
        $this->table(self::LEGACY_IDENTIFIER_LABEL)->delete();

        $this->writeInstances(array_column($instanceRows, 'class'));
        $this->writeBindings($bindingRows);
        $this->writeDependencyChains($dependencyChainRows);
        $this->writeAbstractResolves($this->buildAbstractResolveRows($instanceRows, $bindingRows, $dependencyChainRows));
        $this->writeContextualBindings($contextualBindingRows);
        $this->writeRoutes($routeRows);
        $this->writeRouteMiddleware($routeMiddlewareRows);
        $this->writeEvents($eventRows);
        $this->writeQueueConnections($queueConnectionRows);
        $this->writeJobs($jobRows);
        $this->writeScheduledTasks($scheduledTaskRows);
        $this->writeAuthProviders($authProviderRows);
        $this->writeAuthGuards($authGuardRows);
        $this->writePasswordBrokers($passwordBrokerRows);
        $this->writePolicies($policyRows);
        $this->writeGateAbilities($gateAbilityRows);
        $this->writeNotificationChannels($notificationChannelRows);
        $this->writeNotifications($notificationRows);
        $this->writeNotificationUsesChannel($notificationUsesChannelRows);
        $this->writeMailers($mailerRows);
        $this->writeMailables($mailableRows);
        $this->writeBroadcastConnections($broadcastConnectionRows);
        $this->writeBroadcastChannels($broadcastChannelRows);
        $this->writeArtisanCommands($artisanCommandRows);
        $this->writeServiceProviders($serviceProviderRows, $serviceProviderRegistersRows);
    }

    private function table(string $label): Neo4jQueryBuilder
    {
        $query = DB::connection(Neo4jBoltClient::ELOQUENT_CONNECTION)->table($label);

        if (! $query instanceof Neo4jQueryBuilder) {
            throw new RuntimeException('The '.Neo4jBoltClient::ELOQUENT_CONNECTION.' connection must use the neo4j-laravel query builder.');
        }

        return $query;
    }

    /**
     * @param  list<array{name: string, kind: string, reason?: string}>  $rows
     */
    private function upsertAbstracts(array $rows): void
    {
        $withReason = array_values(array_filter($rows, static fn (array $row): bool => isset($row['reason'])));
        $withoutReason = array_values(array_filter($rows, static fn (array $row): bool => ! isset($row['reason'])));

        $this->table(RuntimeGraphModel::LABEL_ABSTRACT)->upsert($withoutReason, [RuntimeGraphModel::NAME_KEY]);
        $this->table(RuntimeGraphModel::LABEL_ABSTRACT)->upsert($withReason, [RuntimeGraphModel::NAME_KEY]);
    }

    /**
     * @param  list<string>  $names
     */
    private function writeInstances(array $names): void
    {
        $this->table(RuntimeGraphModel::LABEL_INSTANCE)->upsert(
            array_map(static fn (string $name): array => ['name' => $name], $names),
            [RuntimeGraphModel::NAME_KEY],
        );
    }

    /**
     * @param  array<int, array{abstract: string, abstractKind: string, concrete: string, concreteKind: string, shared: bool, type: string, source: string, confidence: string, provenance: string, remarks: string}>  $bindingRows
     */
    private function writeBindings(array $bindingRows): void
    {
        $abstracts = [];
        $edges = [];
        foreach ($bindingRows as $row) {
            $abstracts[] = ['name' => $row['abstract'], 'kind' => $row['abstractKind']];
            $abstracts[] = ['name' => $row['concrete'], 'kind' => $row['concreteKind']];
            $edges[] = [
                'from' => ['name' => $row['abstract']],
                'to' => ['name' => $row['concrete']],
                'properties' => [
                    'type' => $row['type'],
                    'source' => $row['source'],
                    'confidence' => $row['confidence'],
                    'provenance' => $row['provenance'],
                    'remarks' => $row['remarks'] ?? '',
                ],
            ];
        }

        $this->upsertAbstracts($abstracts);
        $this->table(RuntimeGraphModel::LABEL_ABSTRACT)->upsertRelationships('BINDS_TO>', RuntimeGraphModel::LABEL_ABSTRACT, $edges);
    }

    /**
     * Dependency -[:IDENTIFIED_AS]-> Abstract for every chain, plus Instance -[:DEPENDS_ON]-> Dependency
     * for chains that start at an instance.
     *
     * @param  array<int, array{instance: string, dependency_key: string, access: string, identifier: string, identifier_kind: string, lifetime: string, injection_type: string, method: string, parameter: string, via: string, file: string, line: int, source: string, confidence: string, provenance: string, remarks: string, catalog_source?: string, reason?: string, helper?: string}>  $dependencyChainRows
     */
    private function writeDependencyChains(array $dependencyChainRows): void
    {
        $dependencies = [];
        $abstracts = [];
        $identifiedAs = [];
        $instances = [];
        $dependsOn = [];
        foreach ($dependencyChainRows as $row) {
            $dependencies[] = ['key' => $row['dependency_key'], 'access' => $row['access']];
            $abstract = ['name' => $row['identifier'], 'kind' => $row['identifier_kind']];
            if (isset($row['reason'])) {
                $abstract['reason'] = $row['reason'];
            }
            $abstracts[] = $abstract;
            $identifiedAs[] = ['from' => ['key' => $row['dependency_key']], 'to' => ['name' => $row['identifier']]];

            if ($row['instance'] === '') {
                continue;
            }

            $instances[] = $row['instance'];
            $dependsOn[] = [
                'from' => ['name' => $row['instance']],
                'to' => ['key' => $row['dependency_key']],
                'properties' => [
                    'file' => $row['file'],
                    'line' => $row['line'],
                    'via' => $row['via'],
                    'type' => $row['injection_type'],
                    'method' => $row['method'],
                    'parameter' => $row['parameter'],
                    'helper' => $row['helper'] ?? '',
                    'source' => $row['source'],
                    'confidence' => $row['confidence'],
                    'provenance' => $row['provenance'],
                    'remarks' => $row['remarks'],
                    'catalog_source' => $row['catalog_source'] ?? '',
                ],
            ];
        }

        $this->table(RuntimeGraphModel::LABEL_DEPENDENCY)->upsert($dependencies, [RuntimeGraphModel::DEPENDENCY_KEY]);
        $this->upsertAbstracts($abstracts);
        $this->table(RuntimeGraphModel::LABEL_DEPENDENCY)->upsertRelationships(RuntimeGraphModel::REL_IDENTIFIED_AS.'>', RuntimeGraphModel::LABEL_ABSTRACT, $identifiedAs);
        $this->writeInstances(array_values(array_unique($instances)));
        $this->table(RuntimeGraphModel::LABEL_INSTANCE)->upsertRelationships(RuntimeGraphModel::REL_DEPENDS_ON.'>', RuntimeGraphModel::LABEL_DEPENDENCY, $dependsOn);
    }

    /**
     * @param  array<int, array{identifier: string, identifier_kind: string, instance: string, lifetime: string}>  $rows
     */
    private function writeAbstractResolves(array $rows): void
    {
        $this->upsertAbstracts(array_map(static fn (array $row): array => ['name' => $row['identifier'], 'kind' => $row['identifier_kind']], $rows));
        $this->writeInstances(array_values(array_unique(array_column($rows, 'instance'))));
        $this->table(RuntimeGraphModel::LABEL_ABSTRACT)->upsertRelationships(
            RuntimeGraphModel::REL_RESOLVES_TO.'>',
            RuntimeGraphModel::LABEL_INSTANCE,
            array_map(static fn (array $row): array => [
                'from' => ['name' => $row['identifier']],
                'to' => ['name' => $row['instance']],
                'properties' => ['lifetime' => $row['lifetime']],
            ], $rows),
        );
    }

    /**
     * An empty reason keeps the reason already stored on the node and edge.
     *
     * @param  array<int, array{when: string, when_kind: string, needs: string, needs_kind: string, give: string, give_kind: string, reason: string}>  $contextualBindingRows
     */
    private function writeContextualBindings(array $contextualBindingRows): void
    {
        $abstracts = [];
        $edges = ['with_reason' => [], 'without_reason' => []];
        foreach ($contextualBindingRows as $row) {
            $properties = ['needs' => $row['needs'], 'needs_kind' => $row['needs_kind']];
            $abstract = ['name' => $row['give'], 'kind' => $row['give_kind']];
            if ($row['reason'] !== '') {
                $abstract['reason'] = $properties['reason'] = $row['reason'];
            }
            $abstracts[] = $abstract;
            $edges[$row['reason'] !== '' ? 'with_reason' : 'without_reason'][] = [
                'from' => ['name' => $row['when']],
                'to' => ['name' => $row['give']],
                'properties' => $properties,
            ];
        }

        $this->writeInstances(array_values(array_unique(array_column($contextualBindingRows, 'when'))));
        $this->upsertAbstracts($abstracts);
        foreach ($edges as $group) {
            $this->table(RuntimeGraphModel::LABEL_INSTANCE)->upsertRelationships('CONTEXTUAL_BINDS>', RuntimeGraphModel::LABEL_ABSTRACT, $group);
        }
    }

    /**
     * route_name is a property from older exports; writing null removes it.
     *
     * @param  array<int, array{key: string, uri: string, methods: string, name: string, action: string, identifier: string, identifier_kind: string}>  $routeRows
     */
    private function writeRoutes(array $routeRows): void
    {
        $this->table(RuntimeGraphModel::LABEL_ROUTE)->upsert(array_map(static fn (array $row): array => [
            'key' => $row['key'],
            'uri' => $row['uri'],
            'methods' => $row['methods'],
            'name' => $row['name'],
            'action' => $row['action'],
            'route_name' => null,
        ], $routeRows), [RuntimeGraphModel::ROUTE_KEY]);
        $this->upsertAbstracts(array_map(static fn (array $row): array => ['name' => $row['identifier'], 'kind' => $row['identifier_kind']], $routeRows));
        $this->table(RuntimeGraphModel::LABEL_ROUTE)->upsertRelationships(
            RuntimeGraphModel::REL_HANDLED_BY.'>',
            RuntimeGraphModel::LABEL_ABSTRACT,
            array_map(static fn (array $row): array => ['from' => ['key' => $row['key']], 'to' => ['name' => $row['identifier']]], $routeRows),
        );
    }

    /**
     * A route can use the same middleware twice (e.g. two throttle rules), so each
     * route's USES_MIDDLEWARE edges are replaced instead of merged.
     *
     * @param  array<int, array{route_key: string, middleware_key: string, identifier: string, identifier_kind: string, parameters: string, order: int}>  $routeMiddlewareRows
     */
    private function writeRouteMiddleware(array $routeMiddlewareRows): void
    {
        $routeKeys = array_values(array_unique(array_column($routeMiddlewareRows, 'route_key')));

        $this->table(RuntimeGraphModel::LABEL_ROUTE)->upsert(
            array_map(static fn (string $key): array => ['key' => $key], $routeKeys),
            [RuntimeGraphModel::ROUTE_KEY],
        );
        $this->table(RuntimeGraphModel::LABEL_MIDDLEWARE)->upsert(
            array_map(static fn (array $row): array => ['key' => $row['middleware_key'], 'name' => $row['middleware_key']], $routeMiddlewareRows),
            [RuntimeGraphModel::MIDDLEWARE_KEY],
        );
        $this->upsertAbstracts(array_map(static fn (array $row): array => ['name' => $row['identifier'], 'kind' => $row['identifier_kind']], $routeMiddlewareRows));
        $this->table(RuntimeGraphModel::LABEL_MIDDLEWARE)->upsertRelationships(
            RuntimeGraphModel::REL_IDENTIFIED_AS.'>',
            RuntimeGraphModel::LABEL_ABSTRACT,
            array_map(static fn (array $row): array => ['from' => ['key' => $row['middleware_key']], 'to' => ['name' => $row['identifier']]], $routeMiddlewareRows),
        );

        $routes = $this->table(RuntimeGraphModel::LABEL_ROUTE);
        $routes->deleteRelationships(
            RuntimeGraphModel::REL_USES_MIDDLEWARE.'>',
            RuntimeGraphModel::LABEL_MIDDLEWARE,
            array_map(static fn (string $key): array => ['from' => ['key' => $key]], $routeKeys),
        );
        $routes->insertRelationships(
            RuntimeGraphModel::REL_USES_MIDDLEWARE.'>',
            RuntimeGraphModel::LABEL_MIDDLEWARE,
            array_map(static fn (array $row): array => [
                'from' => ['key' => $row['route_key']],
                'to' => ['key' => $row['middleware_key']],
                'properties' => ['order' => $row['order'], 'parameters' => $row['parameters']],
            ], $routeMiddlewareRows),
        );
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
     * Policy nodes are deleted and recreated so edges to a previous handler or model go away.
     *
     * @param  array<int, array{key: string, name: string, model: string, model_kind: string, identifier: string, identifier_kind: string, action: string}>  $policyRows
     */
    private function writePolicies(array $policyRows): void
    {
        foreach ($policyRows as $row) {
            PolicyNode::whereKey($row['key'])->delete();
            $policy = PolicyNode::create(['key' => $row['key'], 'name' => $row['name']]);

            if ($row['identifier'] === '') {
                continue;
            }

            AbstractNode::ensure($row['identifier'], $row['identifier_kind']);
            $policy->handledBy()->attach($row['identifier'], ['action' => $row['action']]);

            if ($row['model'] !== '') {
                AbstractNode::ensure($row['model'], $row['model_kind']);
                $policy->forModel()->attach($row['model']);
            }
        }
    }

    /**
     * @param  array<int, array{key: string, name: string, handler_kind: string, identifier: string, identifier_kind: string, action: string}>  $gateAbilityRows
     */
    private function writeGateAbilities(array $gateAbilityRows): void
    {
        foreach ($gateAbilityRows as $row) {
            GateAbilityNode::whereKey($row['key'])->delete();
            $ability = GateAbilityNode::create([
                'key' => $row['key'],
                'name' => $row['name'],
                'handler_kind' => $row['handler_kind'],
            ]);

            if ($row['identifier'] === '') {
                continue;
            }

            AbstractNode::ensure($row['identifier'], $row['identifier_kind']);
            $ability->handledBy()->attach($row['identifier'], ['action' => $row['action']]);
        }
    }

    /**
     * Recreating a channel also drops incoming USES_CHANNEL edges; they are
     * re-attached by writeNotificationUsesChannel() in the same export.
     *
     * @param  array<int, array{key: string, name: string, kind: string, resolved_class: string, resolved_class_kind: string, is_default: bool}>  $notificationChannelRows
     */
    private function writeNotificationChannels(array $notificationChannelRows): void
    {
        foreach ($notificationChannelRows as $row) {
            NotificationChannelNode::whereKey($row['key'])->delete();
            $channel = NotificationChannelNode::create([
                'key' => $row['key'],
                'name' => $row['name'],
                'kind' => $row['kind'],
                'is_default' => $row['is_default'],
            ]);

            if ($row['resolved_class'] === '') {
                continue;
            }

            AbstractNode::ensure($row['resolved_class'], $row['resolved_class_kind']);
            $channel->identifiedAs()->attach($row['resolved_class']);
        }
    }

    /**
     * @param  array<int, array{key: string, name: string, action: string, identifier: string, identifier_kind: string, should_queue: bool, connection: string, queue: string, unique: bool}>  $notificationRows
     */
    private function writeNotifications(array $notificationRows): void
    {
        foreach ($notificationRows as $row) {
            NotificationNode::whereKey($row['key'])->delete();
            $notification = NotificationNode::create([
                'key' => $row['key'],
                'name' => $row['name'],
                'should_queue' => $row['should_queue'],
                'connection' => $row['connection'],
                'queue' => $row['queue'],
                'unique' => $row['unique'],
            ]);

            if ($row['identifier'] === '') {
                continue;
            }

            AbstractNode::ensure($row['identifier'], $row['identifier_kind']);
            $notification->handledBy()->attach($row['identifier'], ['action' => $row['action']]);
        }
    }

    /**
     * Channels referenced only from via() (custom channel classes) are not recreated
     * by writeNotificationChannels(), so their IDENTIFIED_AS edge may already exist.
     *
     * @param  array<int, array{notification_key: string, channel_key: string, channel_kind: string, resolved_class: string, resolved_class_kind: string, order: int}>  $notificationUsesChannelRows
     */
    private function writeNotificationUsesChannel(array $notificationUsesChannelRows): void
    {
        $byPair = [];
        foreach ($notificationUsesChannelRows as $row) {
            $byPair[$row['notification_key']."\0".$row['channel_key']] = $row;
        }

        foreach ($byPair as $row) {
            $notification = NotificationNode::firstOrCreate(['key' => $row['notification_key']]);

            $channel = NotificationChannelNode::firstOrNew(['key' => $row['channel_key']]);
            $channel->name ??= $row['channel_key'];
            $channel->kind = $row['channel_kind'];
            $channel->save();

            $notification->usesChannel()->attach($row['channel_key'], ['order' => $row['order']]);

            if ($row['resolved_class'] === '') {
                continue;
            }

            AbstractNode::ensure($row['resolved_class'], $row['resolved_class_kind']);
            if (! $channel->identifiedAs()->get()->contains('name', $row['resolved_class'])) {
                $channel->identifiedAs()->attach($row['resolved_class']);
            }
        }
    }

    /**
     * One row per listener; rows sharing an event key collapse into one node with
     * one HANDLED_BY edge per distinct listener (last row wins).
     *
     * @param  array<int, array{key: string, name: string, action: string, identifier: string, identifier_kind: string}>  $eventRows
     */
    private function writeEvents(array $eventRows): void
    {
        $byKey = [];
        foreach ($eventRows as $row) {
            $byKey[$row['key']]['row'] = $row;
            if ($row['identifier'] !== '') {
                $byKey[$row['key']]['listeners'][$row['identifier']] = $row;
            }
        }

        foreach ($byKey as $key => $group) {
            EventNode::whereKey($key)->delete();
            $event = EventNode::create(['key' => $key, 'name' => $group['row']['name']]);

            foreach ($group['listeners'] ?? [] as $identifier => $listener) {
                AbstractNode::ensure($identifier, $listener['identifier_kind']);
                $event->handledBy()->attach($identifier, ['action' => $listener['action']]);
            }
        }
    }

    /**
     * Updated in place (not recreated) so incoming USES_CONNECTION edges from
     * jobs and mailables survive.
     *
     * @param  array<int, array{key: string, driver: string, default_queue: string, is_default: bool}>  $queueConnectionRows
     */
    private function writeQueueConnections(array $queueConnectionRows): void
    {
        foreach ($queueConnectionRows as $row) {
            QueueConnectionNode::updateOrCreate(['key' => $row['key']], [
                'driver' => $row['driver'],
                'default_queue' => $row['default_queue'],
                'is_default' => $row['is_default'],
            ]);
        }
    }

    /**
     * @param  array<int, array{key: string, name: string, action: string, identifier: string, identifier_kind: string, should_queue: bool, connection: string, queue: string, unique: bool}>  $jobRows
     */
    private function writeJobs(array $jobRows): void
    {
        foreach ($jobRows as $row) {
            JobNode::whereKey($row['key'])->delete();
            $job = JobNode::create([
                'key' => $row['key'],
                'name' => $row['name'],
                'should_queue' => $row['should_queue'],
                'connection' => $row['connection'],
                'queue' => $row['queue'],
                'unique' => $row['unique'],
            ]);

            if ($row['identifier'] !== '') {
                AbstractNode::ensure($row['identifier'], $row['identifier_kind']);
                $job->handledBy()->attach($row['identifier'], ['action' => $row['action']]);
            }

            if ($row['connection'] !== '') {
                QueueConnectionNode::firstOrCreate(['key' => $row['connection']]);
                $job->usesConnection()->attach($row['connection']);
            }
        }
    }

    /**
     * @param  array<int, array{key: string, name: string, expression: string, command: string, description: string, timezone: string, kind: string, without_overlapping: bool, on_one_server: bool, run_in_background: bool, even_in_maintenance_mode: bool, action: string, identifier: string, identifier_kind: string}>  $scheduledTaskRows
     */
    private function writeScheduledTasks(array $scheduledTaskRows): void
    {
        foreach ($scheduledTaskRows as $row) {
            ScheduledTaskNode::whereKey($row['key'])->delete();
            $task = ScheduledTaskNode::create([
                'key' => $row['key'],
                'name' => $row['name'],
                'expression' => $row['expression'],
                'command' => $row['command'],
                'description' => $row['description'],
                'timezone' => $row['timezone'],
                'kind' => $row['kind'],
                'without_overlapping' => $row['without_overlapping'],
                'on_one_server' => $row['on_one_server'],
                'run_in_background' => $row['run_in_background'],
                'even_in_maintenance_mode' => $row['even_in_maintenance_mode'],
            ]);

            if ($row['identifier'] === '') {
                continue;
            }

            AbstractNode::ensure($row['identifier'], $row['identifier_kind']);
            $task->handledBy()->attach($row['identifier'], ['action' => $row['action']]);
        }
    }

    /**
     * Recreating a provider also drops incoming USES_PROVIDER edges; guards and
     * password brokers re-attach them later in the same export.
     *
     * @param  array<int, array{key: string, driver: string, model: string, model_kind: string, table: string}>  $authProviderRows
     */
    private function writeAuthProviders(array $authProviderRows): void
    {
        foreach ($authProviderRows as $row) {
            AuthProviderNode::whereKey($row['key'])->delete();
            $provider = AuthProviderNode::create([
                'key' => $row['key'],
                'driver' => $row['driver'],
                'table' => $row['table'],
            ]);

            if ($row['model'] === '') {
                continue;
            }

            AbstractNode::ensure($row['model'], $row['model_kind']);
            $provider->usesModel()->attach($row['model']);
        }
    }

    /**
     * @param  array<int, array{key: string, driver: string, provider: string, is_default: bool}>  $authGuardRows
     */
    private function writeAuthGuards(array $authGuardRows): void
    {
        foreach ($authGuardRows as $row) {
            AuthGuardNode::whereKey($row['key'])->delete();
            $guard = AuthGuardNode::create([
                'key' => $row['key'],
                'driver' => $row['driver'],
                'is_default' => $row['is_default'],
            ]);

            if ($row['provider'] === '') {
                continue;
            }

            AuthProviderNode::firstOrCreate(['key' => $row['provider']]);
            $guard->usesProvider()->attach($row['provider']);
        }
    }

    /**
     * @param  array<int, array{key: string, provider: string, table: string, expire: int, throttle: int, is_default: bool}>  $passwordBrokerRows
     */
    private function writePasswordBrokers(array $passwordBrokerRows): void
    {
        foreach ($passwordBrokerRows as $row) {
            PasswordBrokerNode::whereKey($row['key'])->delete();
            $broker = PasswordBrokerNode::create([
                'key' => $row['key'],
                'table' => $row['table'],
                'expire' => $row['expire'],
                'throttle' => $row['throttle'],
                'is_default' => $row['is_default'],
            ]);

            if ($row['provider'] === '') {
                continue;
            }

            AuthProviderNode::firstOrCreate(['key' => $row['provider']]);
            $broker->usesProvider()->attach($row['provider']);
        }
    }

    /**
     * Updated in place (not recreated) so incoming USES_MAILER edges survive.
     *
     * @param  array<int, array{key: string, transport: string, nested_mailers: string, is_default: bool}>  $mailerRows
     */
    private function writeMailers(array $mailerRows): void
    {
        foreach ($mailerRows as $row) {
            MailerNode::updateOrCreate(['key' => $row['key']], [
                'transport' => $row['transport'],
                'nested_mailers' => $row['nested_mailers'],
                'is_default' => $row['is_default'],
            ]);
        }
    }

    /**
     * @param  array<int, array{key: string, name: string, action: string, identifier: string, identifier_kind: string, should_queue: bool, mailer: string, connection: string, queue: string, unique: bool}>  $mailableRows
     */
    private function writeMailables(array $mailableRows): void
    {
        foreach ($mailableRows as $row) {
            MailableNode::whereKey($row['key'])->delete();
            $mailable = MailableNode::create([
                'key' => $row['key'],
                'name' => $row['name'],
                'should_queue' => $row['should_queue'],
                'mailer' => $row['mailer'],
                'connection' => $row['connection'],
                'queue' => $row['queue'],
                'unique' => $row['unique'],
            ]);

            if ($row['identifier'] !== '') {
                AbstractNode::ensure($row['identifier'], $row['identifier_kind']);
                $mailable->handledBy()->attach($row['identifier'], ['action' => $row['action']]);
            }

            if ($row['mailer'] !== '') {
                MailerNode::firstOrCreate(['key' => $row['mailer']]);
                $mailable->usesMailer()->attach($row['mailer']);
            }

            if ($row['connection'] !== '') {
                QueueConnectionNode::firstOrCreate(['key' => $row['connection']]);
                $mailable->usesConnection()->attach($row['connection']);
            }
        }
    }

    /**
     * @param  array<int, array{key: string, driver: string, is_default: bool}>  $broadcastConnectionRows
     */
    private function writeBroadcastConnections(array $broadcastConnectionRows): void
    {
        foreach ($broadcastConnectionRows as $row) {
            BroadcastConnectionNode::updateOrCreate(['key' => $row['key']], [
                'driver' => $row['driver'],
                'is_default' => $row['is_default'],
            ]);
        }
    }

    /**
     * Several rows may share a channel key; they collapse into one node with one
     * HANDLED_BY edge per distinct handler (last row wins).
     *
     * @param  array<int, array{key: string, name: string, guards: string, action: string, identifier: string, identifier_kind: string}>  $broadcastChannelRows
     */
    private function writeBroadcastChannels(array $broadcastChannelRows): void
    {
        $byKey = [];
        foreach ($broadcastChannelRows as $row) {
            $byKey[$row['key']]['row'] = $row;
            if ($row['identifier'] !== '') {
                $byKey[$row['key']]['handlers'][$row['identifier']] = $row;
            }
        }

        foreach ($byKey as $key => $group) {
            BroadcastChannelNode::whereKey($key)->delete();
            $channel = BroadcastChannelNode::create([
                'key' => $key,
                'name' => $group['row']['name'],
                'guards' => $group['row']['guards'],
            ]);

            foreach ($group['handlers'] ?? [] as $identifier => $handler) {
                AbstractNode::ensure($identifier, $handler['identifier_kind']);
                $channel->handledBy()->attach($identifier, ['action' => $handler['action']]);
            }
        }
    }

    /**
     * Commands without a resolvable handler class keep no HANDLED_BY edge.
     *
     * @param  array<int, array{key: string, name: string, description: string, hidden: bool, aliases: string, kind: string, source: string, action: string, identifier: string, identifier_kind: string}>  $artisanCommandRows
     */
    private function writeArtisanCommands(array $artisanCommandRows): void
    {
        $handled = array_values(array_filter($artisanCommandRows, static fn (array $row): bool => $row['identifier'] !== ''));

        $commands = $this->table(RuntimeGraphModel::LABEL_ARTISAN_COMMAND);
        $commands->upsert(array_map(static fn (array $row): array => [
            'key' => $row['key'],
            'name' => $row['name'],
            'description' => $row['description'],
            'hidden' => $row['hidden'],
            'aliases' => $row['aliases'],
            'kind' => $row['kind'],
            'source' => $row['source'],
        ], $artisanCommandRows), [RuntimeGraphModel::ARTISAN_COMMAND_KEY]);
        $commands->deleteRelationships(
            RuntimeGraphModel::REL_HANDLED_BY.'>',
            RuntimeGraphModel::LABEL_ABSTRACT,
            array_map(static fn (string $key): array => ['from' => ['key' => $key]], array_values(array_unique(array_column($artisanCommandRows, 'key')))),
        );
        $this->upsertAbstracts(array_map(static fn (array $row): array => ['name' => $row['identifier'], 'kind' => $row['identifier_kind']], $handled));
        $commands->upsertRelationships(
            RuntimeGraphModel::REL_HANDLED_BY.'>',
            RuntimeGraphModel::LABEL_ABSTRACT,
            array_map(static fn (array $row): array => [
                'from' => ['key' => $row['key']],
                'to' => ['name' => $row['identifier']],
                'properties' => ['action' => $row['action']],
            ], $handled),
        );
    }

    /**
     * A provider only names what it registers, so an Abstract that already has a
     * kind keeps it. Each provider's REGISTERS edges are replaced on every export.
     *
     * @param  array<int, array{key: string, name: string, deferred: bool, loaded: bool, source: string}>  $serviceProviderRows
     * @param  array<int, array{provider_key: string, abstract: string, abstract_kind: string, type: string, via: string}>  $serviceProviderRegistersRows
     */
    private function writeServiceProviders(array $serviceProviderRows, array $serviceProviderRegistersRows): void
    {
        $providers = $this->table(RuntimeGraphModel::LABEL_SERVICE_PROVIDER);
        $providers->upsert(array_map(static fn (array $row): array => [
            'key' => $row['key'],
            'name' => $row['name'],
            'deferred' => $row['deferred'],
            'loaded' => $row['loaded'],
            'source' => $row['source'],
        ], $serviceProviderRows), [RuntimeGraphModel::SERVICE_PROVIDER_KEY]);
        $providers->deleteRelationships(
            RuntimeGraphModel::REL_REGISTERS.'>',
            RuntimeGraphModel::LABEL_ABSTRACT,
            array_map(static fn (array $row): array => ['from' => ['key' => $row['key']]], $serviceProviderRows),
        );
        $this->table(RuntimeGraphModel::LABEL_ABSTRACT)->upsert(
            array_map(static fn (array $row): array => ['name' => $row['abstract'], 'kind' => $row['abstract_kind']], $serviceProviderRegistersRows),
            [RuntimeGraphModel::NAME_KEY],
            [RuntimeGraphModel::NAME_KEY],
        );
        $providers->upsertRelationships(
            RuntimeGraphModel::REL_REGISTERS.'>',
            RuntimeGraphModel::LABEL_ABSTRACT,
            array_map(static fn (array $row): array => [
                'from' => ['key' => $row['provider_key']],
                'to' => ['name' => $row['abstract']],
                'properties' => ['type' => $row['type'], 'via' => $row['via']],
            ], $serviceProviderRegistersRows),
        );
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
     * @param  array<int, array{key: string, transport: string, nested_mailers: string, is_default: bool}>  $mailerRows
     */
    private function validateMailerRows(array $mailerRows): void
    {
        foreach ($mailerRows as $row) {
            foreach (['key', 'transport', 'nested_mailers'] as $key) {
                if (! array_key_exists($key, $row) || ! is_string($row[$key])) {
                    throw new \InvalidArgumentException("Mailer row is missing string {$key}");
                }
            }

            if (! array_key_exists('is_default', $row) || ! is_bool($row['is_default'])) {
                throw new \InvalidArgumentException('Mailer row is missing boolean is_default');
            }
        }
    }

    /**
     * @param  array<int, array{key: string, name: string, action: string, identifier: string, identifier_kind: string, should_queue: bool, mailer: string, connection: string, queue: string, unique: bool}>  $mailableRows
     */
    private function validateMailableRows(array $mailableRows): void
    {
        foreach ($mailableRows as $row) {
            foreach (['key', 'name', 'action', 'identifier', 'identifier_kind', 'mailer', 'connection', 'queue'] as $key) {
                if (! array_key_exists($key, $row) || ! is_string($row[$key])) {
                    throw new \InvalidArgumentException("Mailable row is missing string {$key}");
                }
            }

            foreach (['should_queue', 'unique'] as $key) {
                if (! array_key_exists($key, $row) || ! is_bool($row[$key])) {
                    throw new \InvalidArgumentException("Mailable row is missing boolean {$key}");
                }
            }
        }
    }

    /**
     * @param  array<int, array{key: string, driver: string, is_default: bool}>  $broadcastConnectionRows
     */
    private function validateBroadcastConnectionRows(array $broadcastConnectionRows): void
    {
        foreach ($broadcastConnectionRows as $row) {
            foreach (['key', 'driver'] as $key) {
                if (! array_key_exists($key, $row) || ! is_string($row[$key])) {
                    throw new \InvalidArgumentException("Broadcast connection row is missing string {$key}");
                }
            }

            if (! array_key_exists('is_default', $row) || ! is_bool($row['is_default'])) {
                throw new \InvalidArgumentException('Broadcast connection row is missing boolean is_default');
            }
        }
    }

    /**
     * @param  array<int, array{key: string, name: string, guards: string, action: string, identifier: string, identifier_kind: string}>  $broadcastChannelRows
     */
    private function validateBroadcastChannelRows(array $broadcastChannelRows): void
    {
        foreach ($broadcastChannelRows as $row) {
            foreach (['key', 'name', 'guards', 'action', 'identifier', 'identifier_kind'] as $key) {
                if (! array_key_exists($key, $row) || ! is_string($row[$key])) {
                    throw new \InvalidArgumentException("Broadcast channel row is missing string {$key}");
                }
            }
        }
    }

    /**
     * @param  array<int, array{key: string, name: string, description: string, hidden: bool, aliases: string, kind: string, source: string, action: string, identifier: string, identifier_kind: string}>  $artisanCommandRows
     */
    private function validateArtisanCommandRows(array $artisanCommandRows): void
    {
        foreach ($artisanCommandRows as $row) {
            foreach (['key', 'name', 'description', 'aliases', 'kind', 'source', 'action', 'identifier', 'identifier_kind'] as $key) {
                if (! array_key_exists($key, $row) || ! is_string($row[$key])) {
                    throw new \InvalidArgumentException("Artisan command row is missing string {$key}");
                }
            }

            if (! array_key_exists('hidden', $row) || ! is_bool($row['hidden'])) {
                throw new \InvalidArgumentException('Artisan command row is missing boolean hidden');
            }
        }
    }

    /**
     * @param  array<int, array{key: string, name: string, deferred: bool, loaded: bool, source: string}>  $serviceProviderRows
     * @param  array<int, array{provider_key: string, abstract: string, abstract_kind: string, type: string, via: string}>  $serviceProviderRegistersRows
     */
    private function validateServiceProviderRows(array $serviceProviderRows, array $serviceProviderRegistersRows): void
    {
        $providerKeys = [];

        foreach ($serviceProviderRows as $row) {
            foreach (['key', 'name', 'source'] as $key) {
                if (! array_key_exists($key, $row) || ! is_string($row[$key])) {
                    throw new \InvalidArgumentException("Service provider row is missing string {$key}");
                }
            }

            foreach (['deferred', 'loaded'] as $key) {
                if (! array_key_exists($key, $row) || ! is_bool($row[$key])) {
                    throw new \InvalidArgumentException("Service provider row is missing boolean {$key}");
                }
            }

            $providerKeys[$row['key']] = true;
        }

        foreach ($serviceProviderRegistersRows as $row) {
            foreach (['provider_key', 'abstract', 'abstract_kind', 'type', 'via'] as $key) {
                if (! array_key_exists($key, $row) || ! is_string($row[$key])) {
                    throw new \InvalidArgumentException("Service provider registers row is missing string {$key}");
                }
            }

            if (! isset($providerKeys[$row['provider_key']])) {
                throw new \InvalidArgumentException("Service provider registers row references unknown provider {$row['provider_key']}");
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
