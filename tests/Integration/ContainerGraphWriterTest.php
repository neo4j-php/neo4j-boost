<?php

namespace Neo4j\LaravelBoost\Tests\Integration;

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
use Neo4j\LaravelBoost\ContainerGraph\Models\ModelEventNode;
use Neo4j\LaravelBoost\ContainerGraph\Models\NotificationChannelNode;
use Neo4j\LaravelBoost\ContainerGraph\Models\NotificationNode;
use Neo4j\LaravelBoost\ContainerGraph\Models\PasswordBrokerNode;
use Neo4j\LaravelBoost\ContainerGraph\Models\PolicyNode;
use Neo4j\LaravelBoost\ContainerGraph\Models\QueueConnectionNode;
use Neo4j\LaravelBoost\ContainerGraph\Models\ScheduledTaskNode;
use Neo4j\LaravelBoost\ContainerGraphWriter;
use Neo4j\LaravelBoost\Support\ContainerGraphConnection;
use Neo4j\LaravelBoost\Support\Graph\RuntimeGraphModel;
use Neo4j\LaravelBoost\Support\Neo4jBoltClient;
use Neo4j\LaravelBoost\Tests\Integration\Support\RecordsGraphWrites;
use Neo4j\LaravelBoost\Tests\Integration\Support\Stubs\TrackingContainerGraphConnection;
use Neo4j\LaravelBoost\Tests\Integration\Support\Stubs\UnusedContainerGraphConnection;
use Neo4j\LaravelBoost\Tests\TestCase;
use Neo4j\Neo4jLaravel\Neo4jConnection;

class ContainerGraphWriterTest extends TestCase
{
    use RecordsGraphWrites;

    public function test_bindings_write_both_abstracts_and_binds_to_metadata_in_bulk(): void
    {
        $this->recordGraphWrites();
        $binding = static fn (string $abstract, string $concrete, string $concreteKind): array => [
            'abstract' => $abstract,
            'abstractKind' => 'Interface',
            'concrete' => $concrete,
            'concreteKind' => $concreteKind,
            'shared' => true,
            'type' => 'singleton',
            'source' => 'reflection',
            'confidence' => 'high',
            'provenance' => 'reflection',
            'remarks' => '',
        ];
        $edge = static fn (string $abstract, string $concrete): array => [
            'from' => ['name' => $abstract],
            'to' => ['name' => $concrete],
            'properties' => ['type' => 'singleton', 'source' => 'reflection', 'confidence' => 'high', 'provenance' => 'reflection', 'remarks' => ''],
        ];

        $this->writeRows([1 => [
            $binding('App\\Contracts\\Ledger', 'App\\Ledgers\\ArrayLedger', 'Class'),
            $binding('App\\Contracts\\Clock', 'clock', 'Alias'),
        ]]);
        $recorded = $this->takeGraphWrites();

        $this->assertWroteOnce($recorded, fn () => $this->graph(RuntimeGraphModel::LABEL_ABSTRACT)->upsert([
            ['name' => 'App\\Contracts\\Ledger', 'kind' => 'Interface'],
            ['name' => 'App\\Ledgers\\ArrayLedger', 'kind' => 'Class'],
            ['name' => 'App\\Contracts\\Clock', 'kind' => 'Interface'],
            ['name' => 'clock', 'kind' => 'Alias'],
        ], ['name']));
        $this->assertWroteOnce($recorded, fn () => $this->graph(RuntimeGraphModel::LABEL_ABSTRACT)->upsertRelationships('BINDS_TO>', RuntimeGraphModel::LABEL_ABSTRACT, [
            $edge('App\\Contracts\\Ledger', 'App\\Ledgers\\ArrayLedger'),
            $edge('App\\Contracts\\Clock', 'clock'),
        ]));
    }

    public function test_dependency_chains_link_dependency_identifier_and_instance(): void
    {
        $this->recordGraphWrites();
        $chain = static fn (string $instance, string $key): array => [
            'instance' => $instance,
            'dependency_key' => $key,
            'access' => 'di',
            'identifier' => 'Psr\\Log\\LoggerInterface',
            'identifier_kind' => 'Interface',
            'lifetime' => 'singleton',
            'injection_type' => 'constructor',
            'method' => '__construct',
            'parameter' => 'logger',
            'via' => 'constructor',
            'file' => 'app/Services/Billing.php',
            'line' => 12,
            'source' => 'reflection',
            'confidence' => 'high',
            'provenance' => 'reflection',
            'remarks' => '',
        ];

        $this->writeRows([2 => [$chain('App\\Services\\Billing', 'billing-logger'), $chain('', 'orphan-logger')]]);
        $recorded = $this->takeGraphWrites();

        $this->assertWroteOnce($recorded, fn () => $this->graph(RuntimeGraphModel::LABEL_DEPENDENCY)->upsert([
            ['key' => 'billing-logger', 'access' => 'di'],
            ['key' => 'orphan-logger', 'access' => 'di'],
        ], ['key']));
        $this->assertWroteOnce($recorded, fn () => $this->graph(RuntimeGraphModel::LABEL_DEPENDENCY)->upsertRelationships(RuntimeGraphModel::REL_IDENTIFIED_AS.'>', RuntimeGraphModel::LABEL_ABSTRACT, [
            ['from' => ['key' => 'billing-logger'], 'to' => ['name' => 'Psr\\Log\\LoggerInterface']],
            ['from' => ['key' => 'orphan-logger'], 'to' => ['name' => 'Psr\\Log\\LoggerInterface']],
        ]));
        $this->assertWroteOnce($recorded, fn () => $this->graph(RuntimeGraphModel::LABEL_INSTANCE)->upsertRelationships(RuntimeGraphModel::REL_DEPENDS_ON.'>', RuntimeGraphModel::LABEL_DEPENDENCY, [[
            'from' => ['name' => 'App\\Services\\Billing'],
            'to' => ['key' => 'billing-logger'],
            'properties' => [
                'file' => 'app/Services/Billing.php',
                'line' => 12,
                'via' => 'constructor',
                'type' => 'constructor',
                'method' => '__construct',
                'parameter' => 'logger',
                'helper' => '',
                'source' => 'reflection',
                'confidence' => 'high',
                'provenance' => 'reflection',
                'remarks' => '',
                'catalog_source' => '',
            ],
        ]]));
    }

    public function test_instances_resolve_to_themselves(): void
    {
        $this->recordGraphWrites();

        $this->writeRows([0 => [['class' => 'App\\Services\\Billing']]]);
        $recorded = $this->takeGraphWrites();

        $this->assertNotSame([], $this->writesLike($recorded, fn () => $this->graph(RuntimeGraphModel::LABEL_INSTANCE)->upsert([['name' => 'App\\Services\\Billing']], ['name'])));
        $this->assertWroteOnce($recorded, fn () => $this->graph(RuntimeGraphModel::LABEL_ABSTRACT)->upsertRelationships(RuntimeGraphModel::REL_RESOLVES_TO.'>', RuntimeGraphModel::LABEL_INSTANCE, [[
            'from' => ['name' => 'App\\Services\\Billing'],
            'to' => ['name' => 'App\\Services\\Billing'],
            'properties' => ['lifetime' => 'bind'],
        ]]));
    }

    public function test_routes_upsert_handler_and_clear_legacy_route_name(): void
    {
        $this->recordGraphWrites();

        $this->writeRows([4 => [[
            'key' => 'GET|orders',
            'uri' => 'orders',
            'methods' => 'GET|HEAD',
            'name' => 'orders.index',
            'action' => 'App\\Http\\Controllers\\OrderController@index',
            'identifier' => 'App\\Http\\Controllers\\OrderController',
            'identifier_kind' => 'Class',
        ]]]);
        $recorded = $this->takeGraphWrites();

        $this->assertWroteOnce($recorded, fn () => $this->graph(RuntimeGraphModel::LABEL_ROUTE)->upsert([[
            'key' => 'GET|orders',
            'uri' => 'orders',
            'methods' => 'GET|HEAD',
            'name' => 'orders.index',
            'action' => 'App\\Http\\Controllers\\OrderController@index',
            'route_name' => null,
        ]], ['key']));
        $this->assertWroteOnce($recorded, fn () => $this->graph(RuntimeGraphModel::LABEL_ROUTE)->upsertRelationships(RuntimeGraphModel::REL_HANDLED_BY.'>', RuntimeGraphModel::LABEL_ABSTRACT, [
            ['from' => ['key' => 'GET|orders'], 'to' => ['name' => 'App\\Http\\Controllers\\OrderController']],
        ]));
    }

    public function test_route_middleware_edges_are_replaced_and_keep_repeated_middleware(): void
    {
        $this->recordGraphWrites();
        $throttle = static fn (string $parameters, int $order): array => [
            'route_key' => 'GET|orders',
            'middleware_key' => 'throttle',
            'identifier' => 'Illuminate\\Routing\\Middleware\\ThrottleRequests',
            'identifier_kind' => 'Class',
            'parameters' => $parameters,
            'order' => $order,
        ];
        $usesThrottle = static fn (string $parameters, int $order): array => [
            'from' => ['key' => 'GET|orders'],
            'to' => ['key' => 'throttle'],
            'properties' => ['order' => $order, 'parameters' => $parameters],
        ];
        $identifiedAs = ['from' => ['key' => 'throttle'], 'to' => ['name' => 'Illuminate\\Routing\\Middleware\\ThrottleRequests']];

        $this->writeRows([5 => [$throttle('60,1', 0), $throttle('10,1', 1)]]);
        $recorded = $this->takeGraphWrites();

        $this->assertWroteOnce($recorded, fn () => $this->graph(RuntimeGraphModel::LABEL_MIDDLEWARE)->upsert([
            ['key' => 'throttle', 'name' => 'throttle'],
            ['key' => 'throttle', 'name' => 'throttle'],
        ], ['key']));
        $this->assertWroteOnce($recorded, fn () => $this->graph(RuntimeGraphModel::LABEL_MIDDLEWARE)->upsertRelationships(RuntimeGraphModel::REL_IDENTIFIED_AS.'>', RuntimeGraphModel::LABEL_ABSTRACT, [$identifiedAs, $identifiedAs]));
        $delete = $this->assertWroteOnce($recorded, fn () => $this->graph(RuntimeGraphModel::LABEL_ROUTE)->deleteRelationships(RuntimeGraphModel::REL_USES_MIDDLEWARE.'>', RuntimeGraphModel::LABEL_MIDDLEWARE, [
            ['from' => ['key' => 'GET|orders']],
        ]));
        $create = $this->assertWroteOnce($recorded, fn () => $this->graph(RuntimeGraphModel::LABEL_ROUTE)->insertRelationships(RuntimeGraphModel::REL_USES_MIDDLEWARE.'>', RuntimeGraphModel::LABEL_MIDDLEWARE, [
            $usesThrottle('60,1', 0),
            $usesThrottle('10,1', 1),
        ]));
        $this->assertLessThan($create, $delete);
    }

    public function test_authorization_models_use_package_connection_labels_and_keys(): void
    {
        $this->assertInstanceOf(Neo4jConnection::class, DB::connection(Neo4jBoltClient::ELOQUENT_CONNECTION));

        foreach ([
            [new PolicyNode, 'Policy', 'key'],
            [new GateAbilityNode, 'GateAbility', 'key'],
            [new AbstractNode, 'Abstract', 'name'],
        ] as [$model, $label, $keyName]) {
            $this->assertSame(Neo4jBoltClient::ELOQUENT_CONNECTION, $model->getConnectionName());
            $this->assertSame($label, $model->getLabel());
            $this->assertSame($keyName, $model->getKeyName());
            $this->assertFalse($model->usesTimestamps());
        }
    }

    public function test_authorization_relations_match_runtime_graph_edges(): void
    {
        $policy = new PolicyNode(['key' => 'App\\Models\\Post']);

        $this->assertRelationship(RuntimeGraphModel::REL_HANDLED_BY.'>', AbstractNode::class, $policy->handledBy());
        $this->assertRelationship(RuntimeGraphModel::REL_FOR_MODEL.'>', AbstractNode::class, $policy->forModel());
        $this->assertRelationship(RuntimeGraphModel::REL_HANDLED_BY.'>', AbstractNode::class, (new GateAbilityNode(['key' => 'publish-post']))->handledBy());
    }

    public function test_notification_models_use_package_connection_labels_and_keys(): void
    {
        foreach ([
            [new NotificationNode, 'Notification'],
            [new NotificationChannelNode, 'NotificationChannel'],
        ] as [$model, $label]) {
            $this->assertSame(Neo4jBoltClient::ELOQUENT_CONNECTION, $model->getConnectionName());
            $this->assertSame($label, $model->getLabel());
            $this->assertSame('key', $model->getKeyName());
            $this->assertFalse($model->usesTimestamps());
        }
    }

    public function test_notification_relations_match_runtime_graph_edges(): void
    {
        $notification = new NotificationNode(['key' => 'App\\Notifications\\InvoicePaid']);

        $this->assertRelationship(RuntimeGraphModel::REL_HANDLED_BY.'>', AbstractNode::class, $notification->handledBy());
        $this->assertRelationship(RuntimeGraphModel::REL_USES_CHANNEL.'>', NotificationChannelNode::class, $notification->usesChannel());
        $this->assertRelationship(RuntimeGraphModel::REL_IDENTIFIED_AS.'>', AbstractNode::class, (new NotificationChannelNode(['key' => 'mail']))->identifiedAs());
    }

    public function test_package_connection_keeps_boolean_bindings(): void
    {
        $this->assertSame(
            ['p0' => true, 'p1' => false],
            DB::connection(Neo4jBoltClient::ELOQUENT_CONNECTION)->prepareBindings([true, false]),
        );
    }

    public function test_config_models_use_package_connection_labels_and_keys(): void
    {
        foreach ([
            [new QueueConnectionNode, 'QueueConnection'],
            [new AuthProviderNode, 'AuthProvider'],
            [new AuthGuardNode, 'AuthGuard'],
            [new PasswordBrokerNode, 'PasswordBroker'],
            [new MailerNode, 'Mailer'],
            [new MailableNode, 'Mailable'],
            [new BroadcastConnectionNode, 'BroadcastConnection'],
            [new BroadcastChannelNode, 'BroadcastChannel'],
        ] as [$model, $label]) {
            $this->assertSame(Neo4jBoltClient::ELOQUENT_CONNECTION, $model->getConnectionName());
            $this->assertSame($label, $model->getLabel());
            $this->assertSame('key', $model->getKeyName());
            $this->assertFalse($model->usesTimestamps());
        }
    }

    public function test_config_relations_match_runtime_graph_edges(): void
    {
        $mailable = new MailableNode(['key' => 'App\\Mail\\Welcome']);

        $this->assertRelationship(RuntimeGraphModel::REL_USES_MODEL.'>', AbstractNode::class, (new AuthProviderNode(['key' => 'users']))->usesModel());
        $this->assertRelationship(RuntimeGraphModel::REL_USES_PROVIDER.'>', AuthProviderNode::class, (new AuthGuardNode(['key' => 'web']))->usesProvider());
        $this->assertRelationship(RuntimeGraphModel::REL_USES_PROVIDER.'>', AuthProviderNode::class, (new PasswordBrokerNode(['key' => 'users']))->usesProvider());
        $this->assertRelationship(RuntimeGraphModel::REL_HANDLED_BY.'>', AbstractNode::class, $mailable->handledBy());
        $this->assertRelationship(RuntimeGraphModel::REL_USES_MAILER.'>', MailerNode::class, $mailable->usesMailer());
        $this->assertRelationship(RuntimeGraphModel::REL_USES_CONNECTION.'>', QueueConnectionNode::class, $mailable->usesConnection());
        $this->assertRelationship(RuntimeGraphModel::REL_HANDLED_BY.'>', AbstractNode::class, (new BroadcastChannelNode(['key' => 'orders']))->handledBy());
    }

    public function test_auth_guard_and_broker_are_recreated_and_linked_only_to_current_provider(): void
    {
        $this->recordGraphWrites();
        $guard = static fn (string $provider): array => ['key' => 'web', 'driver' => 'session', 'provider' => $provider, 'is_default' => true];
        $broker = static fn (string $provider): array => ['key' => 'users', 'provider' => $provider, 'table' => 'password_reset_tokens', 'expire' => 60, 'throttle' => 60, 'is_default' => true];

        $this->writeRows([11 => [$guard('admins')], 12 => [$broker('admins')]]);
        $recorded = $this->takeGraphWrites();

        $delete = $this->assertWroteOnce($recorded, fn () => AuthGuardNode::whereKey('web')->delete());
        $link = $this->assertWroteOnce($recorded, fn () => (new AuthGuardNode(['key' => 'web']))->usesProvider()->attach('admins'));
        $this->assertLessThan($link, $delete);
        $this->assertWroteOnce($recorded, fn () => PasswordBrokerNode::whereKey('users')->delete());
        $this->assertWroteOnce($recorded, fn () => PasswordBrokerNode::create(['key' => 'users', 'table' => 'password_reset_tokens', 'expire' => 60, 'throttle' => 60, 'is_default' => true]));
        $this->assertWroteOnce($recorded, fn () => (new PasswordBrokerNode(['key' => 'users']))->usesProvider()->attach('admins'));

        $this->writeRows([11 => [$guard('')], 12 => [$broker('')]]);
        $recorded = $this->takeGraphWrites();

        $this->assertWroteOnce($recorded, fn () => AuthGuardNode::whereKey('web')->delete());
        $this->assertNeverWroteKind($recorded, fn () => (new AuthGuardNode(['key' => 'web']))->usesProvider()->attach('admins'));
        $this->assertNeverWroteKind($recorded, fn () => (new PasswordBrokerNode(['key' => 'users']))->usesProvider()->attach('admins'));
    }

    public function test_auth_provider_is_recreated_with_current_model(): void
    {
        $this->recordGraphWrites();

        $this->writeRows([10 => [['key' => 'users', 'driver' => 'eloquent', 'model' => 'App\\Models\\Admin', 'model_kind' => 'Class', 'table' => '']]]);
        $recorded = $this->takeGraphWrites();

        $this->assertWroteOnce($recorded, fn () => AuthProviderNode::whereKey('users')->delete());
        $this->assertWroteOnce($recorded, fn () => (new AuthProviderNode(['key' => 'users']))->usesModel()->attach('App\\Models\\Admin'));

        $this->writeRows([10 => [['key' => 'users', 'driver' => 'database', 'model' => '', 'model_kind' => '', 'table' => 'users']]]);

        $this->assertNeverWroteKind($this->takeGraphWrites(), fn () => (new AuthProviderNode(['key' => 'users']))->usesModel()->attach('App\\Models\\Admin'));
    }

    public function test_mailable_is_recreated_with_current_mailer_and_connection(): void
    {
        $this->recordGraphWrites();
        $mailable = static fn (string $mailer, string $queueConnection): array => [
            'key' => 'App\\Mail\\WelcomeMailable',
            'name' => 'WelcomeMailable',
            'action' => 'App\\Mail\\WelcomeMailable@build',
            'identifier' => 'App\\Mail\\WelcomeMailable',
            'identifier_kind' => 'Class',
            'should_queue' => true,
            'mailer' => $mailer,
            'connection' => $queueConnection,
            'queue' => 'mails',
            'unique' => false,
        ];
        $node = static fn (): MailableNode => new MailableNode(['key' => 'App\\Mail\\WelcomeMailable']);

        $this->writeRows([19 => [$mailable('ses', 'redis')]]);
        $recorded = $this->takeGraphWrites();

        $this->assertWroteOnce($recorded, fn () => MailableNode::whereKey('App\\Mail\\WelcomeMailable')->delete());
        $this->assertWroteOnce($recorded, fn () => MailableNode::create([
            'key' => 'App\\Mail\\WelcomeMailable',
            'name' => 'WelcomeMailable',
            'should_queue' => true,
            'mailer' => 'ses',
            'connection' => 'redis',
            'queue' => 'mails',
            'unique' => false,
        ]));
        $this->assertWroteOnce($recorded, fn () => $node()->handledBy()->attach('App\\Mail\\WelcomeMailable', ['action' => 'App\\Mail\\WelcomeMailable@build']));
        $this->assertWroteOnce($recorded, fn () => $node()->usesMailer()->attach('ses'));
        $this->assertWroteOnce($recorded, fn () => $node()->usesConnection()->attach('redis'));

        $this->writeRows([19 => [$mailable('', '')]]);
        $recorded = $this->takeGraphWrites();

        $this->assertWroteOnce($recorded, fn () => MailableNode::whereKey('App\\Mail\\WelcomeMailable')->delete());
        $this->assertNeverWroteKind($recorded, fn () => $node()->usesMailer()->attach('ses'));
        $this->assertNeverWroteKind($recorded, fn () => $node()->usesConnection()->attach('redis'));
    }

    public function test_mailers_and_broadcast_connections_are_updated_in_place(): void
    {
        $this->recordGraphWrites();

        $this->writeRows([
            18 => [['key' => 'smtp', 'transport' => 'smtp', 'nested_mailers' => '', 'is_default' => true]],
            20 => [['key' => 'pusher', 'driver' => 'pusher', 'is_default' => false]],
        ]);
        $recorded = $this->takeGraphWrites();

        $this->assertNeverWroteKind($recorded, fn () => MailerNode::whereKey('smtp')->delete());
        $this->assertNeverWroteKind($recorded, fn () => BroadcastConnectionNode::whereKey('pusher')->delete());
        $this->assertWroteOnce($recorded, fn () => MailerNode::create(['key' => 'smtp', 'transport' => 'smtp', 'nested_mailers' => '', 'is_default' => true]));
        $this->assertWroteOnce($recorded, fn () => BroadcastConnectionNode::create(['key' => 'pusher', 'driver' => 'pusher', 'is_default' => false]));
    }

    public function test_broadcast_channel_rows_sharing_a_key_collapse_to_one_node(): void
    {
        $this->recordGraphWrites();
        $row = static fn (string $identifier, string $action): array => ['key' => 'orders.{id}', 'name' => 'orders.{id}', 'guards' => 'web', 'action' => $action, 'identifier' => $identifier, 'identifier_kind' => $identifier === '' ? '' : 'Class'];
        $handledBy = static fn (string $identifier, string $action): mixed => (new BroadcastChannelNode(['key' => 'orders.{id}']))->handledBy()->attach($identifier, ['action' => $action]);

        $this->writeRows([21 => [
            $row('App\\Broadcasting\\OrderChannel', 'App\\Broadcasting\\OrderChannel@join'),
            $row('App\\Broadcasting\\AdminChannel', 'App\\Broadcasting\\AdminChannel@join'),
            $row('App\\Broadcasting\\OrderChannel', 'App\\Broadcasting\\OrderChannel@authorize'),
            $row('', ''),
        ]]);
        $recorded = $this->takeGraphWrites();

        $this->assertWroteOnce($recorded, fn () => BroadcastChannelNode::whereKey('orders.{id}')->delete());
        $this->assertWroteOnce($recorded, fn () => BroadcastChannelNode::create(['key' => 'orders.{id}', 'name' => 'orders.{id}', 'guards' => 'web']));
        $this->assertCount(2, $this->writesLike($recorded, fn () => $handledBy('App\\Broadcasting\\OrderChannel', ''), sameValues: false));
        $this->assertWroteOnce($recorded, fn () => $handledBy('App\\Broadcasting\\OrderChannel', 'App\\Broadcasting\\OrderChannel@authorize'));
        $this->assertWroteOnce($recorded, fn () => $handledBy('App\\Broadcasting\\AdminChannel', 'App\\Broadcasting\\AdminChannel@join'));
        $this->assertSame([], $this->writesLike($recorded, fn () => $handledBy('App\\Broadcasting\\OrderChannel', 'App\\Broadcasting\\OrderChannel@join')));
    }

    public function test_runtime_surface_models_use_package_connection_labels_and_keys(): void
    {
        foreach ([[new EventNode, 'Event'], [new JobNode, 'Job'], [new ScheduledTaskNode, 'ScheduledTask'], [new ModelEventNode, 'ModelEvent']] as [$model, $label]) {
            $this->assertSame(Neo4jBoltClient::ELOQUENT_CONNECTION, $model->getConnectionName());
            $this->assertSame($label, $model->getLabel());
            $this->assertSame('key', $model->getKeyName());
            $this->assertFalse($model->usesTimestamps());
        }

        $job = new JobNode(['key' => 'App\\Jobs\\ExampleJob']);
        $modelEvent = new ModelEventNode(['key' => 'eloquent.deleted: App\\Models\\Product']);
        $this->assertRelationship(RuntimeGraphModel::REL_HANDLED_BY.'>', AbstractNode::class, (new EventNode(['key' => 'App\\Events\\OrderShipped']))->handledBy());
        $this->assertRelationship(RuntimeGraphModel::REL_HANDLED_BY.'>', AbstractNode::class, $job->handledBy());
        $this->assertRelationship(RuntimeGraphModel::REL_USES_CONNECTION.'>', QueueConnectionNode::class, $job->usesConnection());
        $this->assertRelationship(RuntimeGraphModel::REL_HANDLED_BY.'>', AbstractNode::class, (new ScheduledTaskNode(['key' => 'task']))->handledBy());
        $this->assertRelationship(RuntimeGraphModel::REL_HANDLED_BY.'>', AbstractNode::class, $modelEvent->handledBy());
        $this->assertRelationship(RuntimeGraphModel::REL_FOR_MODEL.'>', AbstractNode::class, $modelEvent->forModel());
        $this->assertRelationship(RuntimeGraphModel::REL_DISPATCHES.'>', AbstractNode::class, $modelEvent->dispatches());
    }

    public function test_event_listeners_are_replaced_on_rerun(): void
    {
        $this->recordGraphWrites();
        $listener = static fn (string $identifier): array => [
            'key' => 'App\\Events\\OrderShipped',
            'name' => 'OrderShipped',
            'action' => $identifier.'@handle',
            'identifier' => $identifier,
            'identifier_kind' => 'Class',
        ];
        $handledBy = static fn (string $identifier): mixed => (new EventNode(['key' => 'App\\Events\\OrderShipped']))->handledBy()->attach($identifier, ['action' => $identifier.'@handle']);

        $this->writeRows([6 => [$listener('App\\Listeners\\SendEmail'), $listener('App\\Listeners\\NotifySlack'), $listener('App\\Listeners\\SendEmail')]]);
        $recorded = $this->takeGraphWrites();

        $this->assertWroteOnce($recorded, fn () => EventNode::whereKey('App\\Events\\OrderShipped')->delete());
        $this->assertWroteOnce($recorded, fn () => EventNode::create(['key' => 'App\\Events\\OrderShipped', 'name' => 'OrderShipped']));
        $this->assertCount(2, $this->writesLike($recorded, fn () => $handledBy('App\\Listeners\\SendEmail'), sameValues: false));

        $this->writeRows([6 => [$listener('App\\Listeners\\WriteAuditLog')]]);
        $recorded = $this->takeGraphWrites();

        $this->assertWroteOnce($recorded, fn () => EventNode::whereKey('App\\Events\\OrderShipped')->delete());
        $this->assertCount(1, $this->writesLike($recorded, fn () => $handledBy('App\\Listeners\\SendEmail'), sameValues: false));
        $this->assertWroteOnce($recorded, fn () => $handledBy('App\\Listeners\\WriteAuditLog'));
    }

    public function test_model_events_link_observer_model_and_dispatched_event(): void
    {
        $this->recordGraphWrites();
        $key = 'eloquent.created: App\\Models\\Invoice';
        $observer = [
            'key' => $key,
            'name' => 'Invoice created',
            'lifecycle' => 'created',
            'source' => 'observer',
            'model' => 'App\\Models\\Invoice',
            'model_kind' => 'Class',
            'identifier' => 'App\\Observers\\InvoiceObserver',
            'identifier_kind' => 'Class',
            'action' => 'App\\Observers\\InvoiceObserver@created',
            'event_class' => '',
            'event_class_kind' => '',
        ];
        $dispatched = [
            'key' => $key,
            'name' => 'Invoice created',
            'lifecycle' => 'created',
            'source' => 'dispatches_events',
            'model' => 'App\\Models\\Invoice',
            'model_kind' => 'Class',
            'identifier' => '',
            'identifier_kind' => '',
            'action' => '',
            'event_class' => 'App\\Events\\InvoiceCreated',
            'event_class_kind' => 'Class',
        ];

        $this->writeRows([25 => [$observer, $dispatched]]);
        $recorded = $this->takeGraphWrites();

        $this->assertWroteOnce($recorded, fn () => ModelEventNode::whereKey($key)->delete());
        $this->assertWroteOnce($recorded, fn () => ModelEventNode::create([
            'key' => $key,
            'name' => 'Invoice created',
            'lifecycle' => 'created',
            'source' => 'observer,dispatches_events',
        ]));
        $this->assertWroteOnce($recorded, fn () => (new ModelEventNode(['key' => $key]))->forModel()->attach('App\\Models\\Invoice'));
        $this->assertWroteOnce($recorded, fn () => (new ModelEventNode(['key' => $key]))->handledBy()->attach('App\\Observers\\InvoiceObserver', ['action' => 'App\\Observers\\InvoiceObserver@created']));
        $this->assertWroteOnce($recorded, fn () => (new ModelEventNode(['key' => $key]))->dispatches()->attach('App\\Events\\InvoiceCreated'));
    }

    public function test_model_event_rows_require_observer_or_dispatched_event(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Model event row needs an observer identifier or a dispatched event_class');

        (new ContainerGraphWriter(new UnusedContainerGraphConnection))->write([], [], [], modelEventRows: [[
            'key' => 'eloquent.created: App\\Models\\Invoice',
            'name' => 'Invoice created',
            'lifecycle' => 'created',
            'source' => 'observer',
            'model' => 'App\\Models\\Invoice',
            'model_kind' => 'Class',
            'identifier' => '',
            'identifier_kind' => '',
            'action' => '',
            'event_class' => '',
            'event_class_kind' => '',
        ]]);
    }

    public function test_job_is_recreated_with_current_connection_and_queue_connections_update_in_place(): void
    {
        $this->recordGraphWrites();
        $job = static fn (string $queueConnection): array => [
            'key' => 'App\\Jobs\\ExampleJob',
            'name' => 'ExampleJob',
            'action' => 'App\\Jobs\\ExampleJob@handle',
            'identifier' => 'App\\Jobs\\ExampleJob',
            'identifier_kind' => 'Class',
            'should_queue' => true,
            'connection' => $queueConnection,
            'queue' => 'default',
            'unique' => false,
        ];
        $queueRows = [['key' => 'sqs', 'driver' => 'sqs', 'default_queue' => 'default', 'is_default' => false]];
        $usesConnection = static fn (): mixed => (new JobNode(['key' => 'App\\Jobs\\ExampleJob']))->usesConnection()->attach('sqs');

        $this->writeRows([7 => [$job('sqs')], 8 => $queueRows]);
        $recorded = $this->takeGraphWrites();

        $this->assertNeverWroteKind($recorded, fn () => QueueConnectionNode::whereKey('sqs')->delete());
        $this->assertWroteOnce($recorded, fn () => QueueConnectionNode::create(['key' => 'sqs', 'driver' => 'sqs', 'default_queue' => 'default', 'is_default' => false]));
        $this->assertWroteOnce($recorded, fn () => JobNode::whereKey('App\\Jobs\\ExampleJob')->delete());
        $this->assertWroteOnce($recorded, fn () => JobNode::create([
            'key' => 'App\\Jobs\\ExampleJob',
            'name' => 'ExampleJob',
            'should_queue' => true,
            'connection' => 'sqs',
            'queue' => 'default',
            'unique' => false,
        ]));
        $this->assertWroteOnce($recorded, $usesConnection);

        $this->writeRows([7 => [$job('')], 8 => $queueRows]);

        $this->assertNeverWroteKind($this->takeGraphWrites(), $usesConnection);
    }

    public function test_scheduled_task_is_recreated_with_current_handler(): void
    {
        $this->recordGraphWrites();
        $task = static fn (string $identifier): array => [
            'key' => 'schedule:inspire',
            'name' => 'inspire',
            'expression' => '0 * * * *',
            'command' => 'inspire',
            'description' => '',
            'timezone' => 'UTC',
            'kind' => $identifier === '' ? 'closure' : 'command',
            'without_overlapping' => true,
            'on_one_server' => false,
            'run_in_background' => false,
            'even_in_maintenance_mode' => false,
            'action' => $identifier === '' ? '' : $identifier.'@handle',
            'identifier' => $identifier,
            'identifier_kind' => $identifier === '' ? '' : 'Class',
        ];
        $handledBy = static fn (): mixed => (new ScheduledTaskNode(['key' => 'schedule:inspire']))->handledBy()->attach('App\\Console\\Commands\\Inspire', ['action' => 'App\\Console\\Commands\\Inspire@handle']);

        $this->writeRows([9 => [$task('App\\Console\\Commands\\Inspire')]]);
        $recorded = $this->takeGraphWrites();

        $this->assertWroteOnce($recorded, fn () => ScheduledTaskNode::whereKey('schedule:inspire')->delete());
        $this->assertWroteOnce($recorded, fn () => ScheduledTaskNode::create([
            'key' => 'schedule:inspire',
            'name' => 'inspire',
            'expression' => '0 * * * *',
            'command' => 'inspire',
            'description' => '',
            'timezone' => 'UTC',
            'kind' => 'command',
            'without_overlapping' => true,
            'on_one_server' => false,
            'run_in_background' => false,
            'even_in_maintenance_mode' => false,
        ]));
        $this->assertWroteOnce($recorded, $handledBy);

        $this->writeRows([9 => [$task('')]]);
        $recorded = $this->takeGraphWrites();

        $this->assertWroteOnce($recorded, fn () => ScheduledTaskNode::whereKey('schedule:inspire')->delete());
        $this->assertNeverWroteKind($recorded, $handledBy);
    }

    public function test_artisan_commands_replace_handled_by_and_skip_it_without_handler(): void
    {
        $this->recordGraphWrites();
        $command = static fn (string $key, string $identifier): array => [
            'key' => $key,
            'name' => $key,
            'description' => 'Sync reports',
            'hidden' => false,
            'aliases' => '',
            'kind' => 'class',
            'source' => 'app',
            'action' => $identifier === '' ? '' : $identifier.'@handle',
            'identifier' => $identifier,
            'identifier_kind' => 'Class',
        ];

        $this->writeRows([22 => [
            $command('reports:sync', 'App\\Console\\Commands\\SyncReports'),
            $command('inspire', ''),
        ]]);
        $recorded = $this->takeGraphWrites();

        $commandNode = static fn (string $key): array => [
            'key' => $key,
            'name' => $key,
            'description' => 'Sync reports',
            'hidden' => false,
            'aliases' => '',
            'kind' => 'class',
            'source' => 'app',
        ];
        $this->assertWroteOnce($recorded, fn () => $this->graph(RuntimeGraphModel::LABEL_ARTISAN_COMMAND)->upsert(
            [$commandNode('reports:sync'), $commandNode('inspire')],
            ['key'],
        ));
        $clear = $this->assertWroteOnce($recorded, fn () => $this->graph(RuntimeGraphModel::LABEL_ARTISAN_COMMAND)->deleteRelationships(
            'HANDLED_BY>',
            RuntimeGraphModel::LABEL_ABSTRACT,
            [['from' => ['key' => 'reports:sync']], ['from' => ['key' => 'inspire']]],
        ));
        $this->assertWroteOnce($recorded, fn () => $this->graph(RuntimeGraphModel::LABEL_ABSTRACT)->upsert(
            [['name' => 'App\\Console\\Commands\\SyncReports', 'kind' => 'Class']],
            ['name'],
        ));
        $handledBy = $this->assertWroteOnce($recorded, fn () => $this->graph(RuntimeGraphModel::LABEL_ARTISAN_COMMAND)->upsertRelationships(
            'HANDLED_BY>',
            RuntimeGraphModel::LABEL_ABSTRACT,
            [[
                'from' => ['key' => 'reports:sync'],
                'to' => ['name' => 'App\\Console\\Commands\\SyncReports'],
                'properties' => ['action' => 'App\\Console\\Commands\\SyncReports@handle'],
            ]],
        ));
        $this->assertLessThan($handledBy, $clear);
    }

    public function test_artisan_command_rows_require_boolean_hidden(): void
    {
        $writer = new ContainerGraphWriter(new UnusedContainerGraphConnection);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Artisan command row is missing boolean hidden');

        $writer->write([], [], [], artisanCommandRows: [[
            'key' => 'reports:sync',
            'name' => 'reports:sync',
            'description' => '',
            'hidden' => 'no',
            'aliases' => '',
            'kind' => 'class',
            'source' => 'app',
            'action' => 'App\\Console\\Commands\\SyncReports@handle',
            'identifier' => 'App\\Console\\Commands\\SyncReports',
            'identifier_kind' => 'Class',
        ]]);
    }

    public function test_service_providers_replace_registers_edges_and_keep_existing_abstract_kind(): void
    {
        $this->recordGraphWrites();

        $this->writeRows([
            23 => [
                ['key' => 'App\\Providers\\BillingServiceProvider', 'name' => 'BillingServiceProvider', 'deferred' => false, 'loaded' => true, 'source' => 'app'],
                ['key' => 'App\\Providers\\EmptyServiceProvider', 'name' => 'EmptyServiceProvider', 'deferred' => true, 'loaded' => false, 'source' => 'app'],
            ],
            24 => [
                ['provider_key' => 'App\\Providers\\BillingServiceProvider', 'abstract' => 'billing.gateway', 'abstract_kind' => 'Alias', 'type' => 'singleton', 'via' => 'register'],
            ],
        ]);
        $recorded = $this->takeGraphWrites();

        $this->assertWroteOnce($recorded, fn () => $this->graph(RuntimeGraphModel::LABEL_SERVICE_PROVIDER)->upsert([
            ['key' => 'App\\Providers\\BillingServiceProvider', 'name' => 'BillingServiceProvider', 'deferred' => false, 'loaded' => true, 'source' => 'app'],
            ['key' => 'App\\Providers\\EmptyServiceProvider', 'name' => 'EmptyServiceProvider', 'deferred' => true, 'loaded' => false, 'source' => 'app'],
        ], ['key']));
        $clear = $this->assertWroteOnce($recorded, fn () => $this->graph(RuntimeGraphModel::LABEL_SERVICE_PROVIDER)->deleteRelationships(
            'REGISTERS>',
            RuntimeGraphModel::LABEL_ABSTRACT,
            [['from' => ['key' => 'App\\Providers\\BillingServiceProvider']], ['from' => ['key' => 'App\\Providers\\EmptyServiceProvider']]],
        ));
        $this->assertWroteOnce($recorded, fn () => $this->graph(RuntimeGraphModel::LABEL_ABSTRACT)->upsert(
            [['name' => 'billing.gateway', 'kind' => 'Alias']],
            ['name'],
            ['name'],
        ));
        $registers = $this->assertWroteOnce($recorded, fn () => $this->graph(RuntimeGraphModel::LABEL_SERVICE_PROVIDER)->upsertRelationships(
            'REGISTERS>',
            RuntimeGraphModel::LABEL_ABSTRACT,
            [[
                'from' => ['key' => 'App\\Providers\\BillingServiceProvider'],
                'to' => ['name' => 'billing.gateway'],
                'properties' => ['type' => 'singleton', 'via' => 'register'],
            ]],
        ));
        $this->assertLessThan($registers, $clear);
    }

    public function test_service_provider_rows_require_boolean_deferred(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Service provider row is missing boolean deferred');

        (new ContainerGraphWriter(new UnusedContainerGraphConnection))->write([], [], [], serviceProviderRows: [[
            'key' => 'App\\Providers\\BillingServiceProvider',
            'name' => 'BillingServiceProvider',
            'deferred' => 'no',
            'loaded' => true,
            'source' => 'app',
        ]]);
    }

    public function test_service_provider_registrations_require_a_known_provider(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Service provider registers row references unknown provider App\\Providers\\Missing');

        (new ContainerGraphWriter(new UnusedContainerGraphConnection))->write([], [], [], serviceProviderRegistersRows: [[
            'provider_key' => 'App\\Providers\\Missing',
            'abstract' => 'billing.gateway',
            'abstract_kind' => 'Alias',
            'type' => 'normal',
            'via' => 'bindings',
        ]]);
    }

    public function test_write_drops_legacy_identifier_nodes(): void
    {
        $this->recordGraphWrites();

        $this->writeRows([]);

        $this->assertWroteOnce($this->takeGraphWrites(), fn () => $this->graph('Identifier')->delete());
    }

    public function test_contextual_binds_keep_stored_reason_when_row_reason_is_empty(): void
    {
        $this->recordGraphWrites();
        $row = static fn (string $give, string $reason): array => [
            'when' => 'App\\Http\\Controllers\\ReportController',
            'when_kind' => 'Class',
            'needs' => 'App\\Contracts\\Exporter',
            'needs_kind' => 'Interface',
            'give' => $give,
            'give_kind' => 'Class',
            'reason' => $reason,
        ];
        $binds = static fn (string $give, array $properties): array => [
            'from' => ['name' => 'App\\Http\\Controllers\\ReportController'],
            'to' => ['name' => $give],
            'properties' => ['needs' => 'App\\Contracts\\Exporter', 'needs_kind' => 'Interface', ...$properties],
        ];

        $this->writeRows([3 => [$row('App\\Exporters\\CsvExporter', ''), $row('App\\Exporters\\PdfExporter', 'closure')]]);
        $recorded = $this->takeGraphWrites();

        $this->assertWroteOnce($recorded, fn () => $this->graph(RuntimeGraphModel::LABEL_ABSTRACT)->upsert([['name' => 'App\\Exporters\\CsvExporter', 'kind' => 'Class']], ['name']));
        $this->assertWroteOnce($recorded, fn () => $this->graph(RuntimeGraphModel::LABEL_ABSTRACT)->upsert([['name' => 'App\\Exporters\\PdfExporter', 'kind' => 'Class', 'reason' => 'closure']], ['name']));
        $this->assertWroteOnce($recorded, fn () => $this->graph(RuntimeGraphModel::LABEL_INSTANCE)->upsertRelationships('CONTEXTUAL_BINDS>', RuntimeGraphModel::LABEL_ABSTRACT, [$binds('App\\Exporters\\CsvExporter', [])]));
        $this->assertWroteOnce($recorded, fn () => $this->graph(RuntimeGraphModel::LABEL_INSTANCE)->upsertRelationships('CONTEXTUAL_BINDS>', RuntimeGraphModel::LABEL_ABSTRACT, [$binds('App\\Exporters\\PdfExporter', ['reason' => 'closure'])]));
    }

    public function test_parse_dsn_extracts_uri_and_credentials(): void
    {
        /** @var array{uri: string, user: string, password: string}|null $parsed */
        $parsed = ContainerGraphConnection::parseDsnToConnection('neo4j://neo4j:my-pass@neo4j-core1:7687');

        $this->assertNotNull($parsed);
        $this->assertSame('neo4j://neo4j-core1:7687', $parsed['uri']);
        $this->assertSame('neo4j', $parsed['user']);
        $this->assertSame('my-pass', $parsed['password']);
    }

    public function test_parse_dsn_returns_null_for_invalid_string(): void
    {
        $this->assertNull(ContainerGraphConnection::parseDsnToConnection('not-a-valid-url'));
    }

    /**
     * @param  array<int, list<array<string, mixed>>>  $rowsByPosition  write() argument position => rows
     */
    private function writeRows(array $rowsByPosition): void
    {
        $arguments = array_fill(0, 26, []);
        foreach ($rowsByPosition as $position => $rows) {
            $arguments[$position] = $rows;
        }

        (new ContainerGraphWriter(new TrackingContainerGraphConnection))->write(...$arguments);
    }
}
