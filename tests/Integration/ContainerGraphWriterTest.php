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
use Neo4j\LaravelBoost\ContainerGraph\Models\NotificationChannelNode;
use Neo4j\LaravelBoost\ContainerGraph\Models\NotificationNode;
use Neo4j\LaravelBoost\ContainerGraph\Models\PasswordBrokerNode;
use Neo4j\LaravelBoost\ContainerGraph\Models\PolicyNode;
use Neo4j\LaravelBoost\ContainerGraph\Models\QueueConnectionNode;
use Neo4j\LaravelBoost\ContainerGraph\Models\ScheduledTaskNode;
use Neo4j\LaravelBoost\ContainerGraphWriter;
use Neo4j\LaravelBoost\Support\ContainerGraphConnection;
use Neo4j\LaravelBoost\Support\Neo4jBoltClient;
use Neo4j\LaravelBoost\Tests\Integration\Support\RecordsEloquentCypher;
use Neo4j\LaravelBoost\Tests\Integration\Support\Stubs\TrackingContainerGraphConnection;
use Neo4j\LaravelBoost\Tests\Integration\Support\Stubs\UnusedContainerGraphConnection;
use Neo4j\LaravelBoost\Tests\TestCase;
use Neo4j\Neo4jLaravel\Neo4jConnection;

class ContainerGraphWriterTest extends TestCase
{
    use RecordsEloquentCypher;

    public function test_cypher_templates_include_core_keys(): void
    {
        $writer = new ContainerGraphWriter(new UnusedContainerGraphConnection);
        $keys = array_keys($writer->cypherTemplates());
        sort($keys);

        $this->assertSame(['abstract_resolves_to', 'bindings', 'contextual_binds', 'identified_as', 'instance_depends_on', 'instances', 'route_middleware', 'routes'], $keys);
    }

    public function test_binding_cypher_uses_concrete_kind_for_non_class_targets(): void
    {
        $writer = new ContainerGraphWriter(new UnusedContainerGraphConnection);
        $bindingsTemplate = $writer->cypherTemplates()['bindings'];

        $this->assertStringContainsString('row.concreteKind', $bindingsTemplate);
        $this->assertStringContainsString('MERGE (a:Abstract {name: row.abstract})', $bindingsTemplate);
        $this->assertStringContainsString('MERGE (c:Abstract {name: row.concrete})', $bindingsTemplate);
        $this->assertStringContainsString('SET a.kind = row.abstractKind', $bindingsTemplate);
        $this->assertStringContainsString('SET c.kind = row.concreteKind', $bindingsTemplate);
        $this->assertStringNotContainsString('SET a:Class', $bindingsTemplate);
        $this->assertStringNotContainsString('SET a:Interface', $bindingsTemplate);
        $this->assertStringNotContainsString('SET a:AbstractType', $bindingsTemplate);
        $this->assertStringContainsString('r.type = row.type', $bindingsTemplate);
        $this->assertStringNotContainsString('MERGE (:Interface:Abstract', $bindingsTemplate);
    }

    public function test_instance_depends_on_cypher_sets_metadata_on_edges(): void
    {
        $writer = new ContainerGraphWriter(new UnusedContainerGraphConnection);
        $dependsOnTemplate = $writer->cypherTemplates()['instance_depends_on'];

        $this->assertStringContainsString('d.via = row.via', $dependsOnTemplate);
        $this->assertStringContainsString('d.file = row.file', $dependsOnTemplate);
        $this->assertStringContainsString('d.line = row.line', $dependsOnTemplate);
        $this->assertStringContainsString('d.type = row.injection_type', $dependsOnTemplate);
        $this->assertStringContainsString('d.method = row.method', $dependsOnTemplate);
        $this->assertStringContainsString('d.parameter = row.parameter', $dependsOnTemplate);
        $this->assertStringContainsString('d.source = row.source', $dependsOnTemplate);
        $this->assertStringContainsString('d.confidence = row.confidence', $dependsOnTemplate);
        $this->assertStringContainsString('d.provenance = row.provenance', $dependsOnTemplate);
        $this->assertStringContainsString('d.remarks = coalesce(row.remarks', $dependsOnTemplate);
        $this->assertStringContainsString(':Instance', $dependsOnTemplate);
        $this->assertStringContainsString(':Dependency', $dependsOnTemplate);
    }

    public function test_bindings_cypher_sets_edge_metadata(): void
    {
        $writer = new ContainerGraphWriter(new UnusedContainerGraphConnection);
        $bindingsTemplate = $writer->cypherTemplates()['bindings'];

        $this->assertStringContainsString('r.source = row.source', $bindingsTemplate);
        $this->assertStringContainsString('r.confidence = row.confidence', $bindingsTemplate);
        $this->assertStringContainsString('r.provenance = row.provenance', $bindingsTemplate);
        $this->assertStringContainsString('r.remarks = coalesce(row.remarks', $bindingsTemplate);
    }

    public function test_identified_as_cypher_links_dependency_to_abstract(): void
    {
        $writer = new ContainerGraphWriter(new UnusedContainerGraphConnection);
        $template = $writer->cypherTemplates()['identified_as'];

        $this->assertStringContainsString('IDENTIFIED_AS', $template);
        $this->assertStringContainsString('dep.access = row.access', $template);
        $this->assertStringContainsString('MERGE (id:Abstract {name: row.identifier})', $template);
        $this->assertStringContainsString(':Dependency', $template);
        $this->assertStringNotContainsString(':Identifier', $template);
        $this->assertStringNotContainsString('MERGE (:Interface:Abstract', $template);
    }

    public function test_abstract_resolves_to_cypher_sets_lifetime(): void
    {
        $writer = new ContainerGraphWriter(new UnusedContainerGraphConnection);
        $template = $writer->cypherTemplates()['abstract_resolves_to'];

        $this->assertStringContainsString('RESOLVES_TO', $template);
        $this->assertStringContainsString('r.lifetime = row.lifetime', $template);
        $this->assertStringContainsString(':Abstract', $template);
        $this->assertStringContainsString(':Instance', $template);
        $this->assertStringNotContainsString(':Identifier', $template);
    }

    public function test_routes_cypher_uses_handled_by(): void
    {
        $writer = new ContainerGraphWriter(new UnusedContainerGraphConnection);
        $template = $writer->cypherTemplates()['routes'];

        $this->assertStringContainsString(':Route', $template);
        $this->assertStringContainsString('HANDLED_BY', $template);
        $this->assertStringContainsString(':Abstract', $template);
        $this->assertStringContainsString('REMOVE r.route_name', $template);
        $this->assertStringNotContainsString('r.route_name = row.route_name', $template);
        $this->assertStringNotContainsString(':Identifier', $template);
    }

    public function test_route_middleware_cypher_uses_middleware_and_identified_as(): void
    {
        $writer = new ContainerGraphWriter(new UnusedContainerGraphConnection);
        $template = $writer->cypherTemplates()['route_middleware'];

        $this->assertStringContainsString(':Route', $template);
        $this->assertStringContainsString(':Middleware', $template);
        $this->assertStringContainsString('USES_MIDDLEWARE', $template);
        $this->assertStringContainsString('IDENTIFIED_AS', $template);
        $this->assertStringContainsString(':Abstract', $template);
        $this->assertStringContainsString('m.name = row.middleware_key', $template);
        $this->assertStringNotContainsString(':Identifier', $template);
        $this->assertStringContainsString('u.parameters = coalesce(row.parameters', $template);
        $this->assertStringContainsString('order: row.order', $template);
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
        $ability = new GateAbilityNode(['key' => 'publish-post']);

        $this->assertStringContainsString('HANDLED_BY', $policy->handledBy()->toSql());
        $this->assertStringContainsString(':Abstract', $policy->handledBy()->toSql());
        $this->assertStringContainsString('FOR_MODEL', $policy->forModel()->toSql());
        $this->assertStringContainsString('HANDLED_BY', $ability->handledBy()->toSql());
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
        $channel = new NotificationChannelNode(['key' => 'mail']);

        $this->assertStringContainsString('HANDLED_BY', $notification->handledBy()->toSql());
        $this->assertStringContainsString('USES_CHANNEL', $notification->usesChannel()->toSql());
        $this->assertStringContainsString(':NotificationChannel', $notification->usesChannel()->toSql());
        $this->assertStringContainsString('IDENTIFIED_AS', $channel->identifiedAs()->toSql());
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

        $this->assertStringContainsString('USES_MODEL', (new AuthProviderNode(['key' => 'users']))->usesModel()->toSql());
        $this->assertStringContainsString('USES_PROVIDER', (new AuthGuardNode(['key' => 'web']))->usesProvider()->toSql());
        $this->assertStringContainsString('USES_PROVIDER', (new PasswordBrokerNode(['key' => 'users']))->usesProvider()->toSql());
        $this->assertStringContainsString('HANDLED_BY', $mailable->handledBy()->toSql());
        $this->assertStringContainsString('USES_MAILER', $mailable->usesMailer()->toSql());
        $this->assertStringContainsString(':QueueConnection', $mailable->usesConnection()->toSql());
        $this->assertStringContainsString('HANDLED_BY', (new BroadcastChannelNode(['key' => 'orders']))->handledBy()->toSql());
    }

    public function test_auth_guard_and_broker_are_recreated_and_linked_only_to_current_provider(): void
    {
        $this->recordEloquentCypher();
        $guard = static fn (string $provider): array => ['key' => 'web', 'driver' => 'session', 'provider' => $provider, 'is_default' => true];
        $broker = static fn (string $provider): array => ['key' => 'users', 'provider' => $provider, 'table' => 'password_reset_tokens', 'expire' => 60, 'throttle' => 60, 'is_default' => true];

        $this->writeRows([11 => [$guard('admins')], 12 => [$broker('admins')]]);
        $recorded = $this->takeEloquentCypher();

        $delete = $this->statementsMatching($recorded, [':AuthGuard', 'DETACH DELETE'], ['web']);
        $link = $this->statementsMatching($recorded, [':AuthGuard', 'USES_PROVIDER'], ['web', 'admins']);
        $this->assertCount(1, $delete);
        $this->assertCount(1, $link);
        $this->assertLessThan(array_search($link[0], $recorded, true), array_search($delete[0], $recorded, true));
        $this->assertCount(1, $this->statementsMatching($recorded, [':PasswordBroker', 'DETACH DELETE'], ['users']));
        $this->assertCount(1, $this->statementsMatching($recorded, [':PasswordBroker', 'USES_PROVIDER'], ['users', 'admins']));
        $this->assertCount(1, $this->statementsMatching($recorded, ['CREATE (n0:PasswordBroker'], [60, true]));

        $this->writeRows([11 => [$guard('')], 12 => [$broker('')]]);
        $recorded = $this->takeEloquentCypher();

        $this->assertCount(1, $this->statementsMatching($recorded, [':AuthGuard', 'DETACH DELETE'], ['web']));
        $this->assertCount(0, $this->statementsMatching($recorded, ['USES_PROVIDER']));
    }

    public function test_auth_provider_is_recreated_with_current_model(): void
    {
        $this->recordEloquentCypher();

        $this->writeRows([10 => [['key' => 'users', 'driver' => 'eloquent', 'model' => 'App\\Models\\Admin', 'model_kind' => 'Class', 'table' => '']]]);
        $recorded = $this->takeEloquentCypher();

        $this->assertCount(1, $this->statementsMatching($recorded, [':AuthProvider', 'DETACH DELETE'], ['users']));
        $this->assertCount(1, $this->statementsMatching($recorded, ['USES_MODEL'], ['users', 'App\\Models\\Admin']));

        $this->writeRows([10 => [['key' => 'users', 'driver' => 'database', 'model' => '', 'model_kind' => '', 'table' => 'users']]]);

        $this->assertCount(0, $this->statementsMatching($this->takeEloquentCypher(), ['USES_MODEL']));
    }

    public function test_mailable_is_recreated_with_current_mailer_and_connection(): void
    {
        $this->recordEloquentCypher();
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

        $this->writeRows([19 => [$mailable('ses', 'redis')]]);
        $recorded = $this->takeEloquentCypher();

        $this->assertCount(1, $this->statementsMatching($recorded, [':Mailable', 'DETACH DELETE'], ['App\\Mail\\WelcomeMailable']));
        $this->assertCount(1, $this->statementsMatching($recorded, ['CREATE (n0:Mailable'], [true, false]));
        $this->assertCount(1, $this->statementsMatching($recorded, ['HANDLED_BY'], ['App\\Mail\\WelcomeMailable@build']));
        $this->assertCount(1, $this->statementsMatching($recorded, ['USES_MAILER'], ['App\\Mail\\WelcomeMailable', 'ses']));
        $this->assertCount(1, $this->statementsMatching($recorded, ['USES_CONNECTION', ':QueueConnection'], ['App\\Mail\\WelcomeMailable', 'redis']));

        $this->writeRows([19 => [$mailable('', '')]]);
        $recorded = $this->takeEloquentCypher();

        $this->assertCount(1, $this->statementsMatching($recorded, [':Mailable', 'DETACH DELETE']));
        $this->assertCount(0, $this->statementsMatching($recorded, ['USES_MAILER']));
        $this->assertCount(0, $this->statementsMatching($recorded, ['USES_CONNECTION']));
    }

    public function test_mailers_and_broadcast_connections_are_updated_in_place(): void
    {
        $this->recordEloquentCypher();

        $this->writeRows([
            18 => [['key' => 'smtp', 'transport' => 'smtp', 'nested_mailers' => '', 'is_default' => true]],
            20 => [['key' => 'pusher', 'driver' => 'pusher', 'is_default' => false]],
        ]);
        $recorded = $this->takeEloquentCypher();

        $this->assertCount(0, $this->statementsMatching($recorded, ['DETACH DELETE']));
        $this->assertCount(1, $this->statementsMatching($recorded, ['CREATE (n0:Mailer'], ['smtp', true]));
        $this->assertCount(1, $this->statementsMatching($recorded, ['CREATE (n0:BroadcastConnection'], ['pusher', false]));
    }

    public function test_broadcast_channel_rows_sharing_a_key_collapse_to_one_node(): void
    {
        $this->recordEloquentCypher();
        $row = static fn (string $identifier, string $action): array => ['key' => 'orders.{id}', 'name' => 'orders.{id}', 'guards' => 'web', 'action' => $action, 'identifier' => $identifier, 'identifier_kind' => $identifier === '' ? '' : 'Class'];

        $this->writeRows([21 => [
            $row('App\\Broadcasting\\OrderChannel', 'App\\Broadcasting\\OrderChannel@join'),
            $row('App\\Broadcasting\\AdminChannel', 'App\\Broadcasting\\AdminChannel@join'),
            $row('App\\Broadcasting\\OrderChannel', 'App\\Broadcasting\\OrderChannel@authorize'),
            $row('', ''),
        ]]);
        $recorded = $this->takeEloquentCypher();

        $this->assertCount(1, $this->statementsMatching($recorded, [':BroadcastChannel', 'DETACH DELETE'], ['orders.{id}']));
        $this->assertCount(1, $this->statementsMatching($recorded, ['CREATE (n0:BroadcastChannel']));
        $this->assertCount(2, $this->statementsMatching($recorded, ['HANDLED_BY']));
        $this->assertCount(1, $this->statementsMatching($recorded, ['HANDLED_BY'], ['App\\Broadcasting\\OrderChannel@authorize']));
        $this->assertCount(0, $this->statementsMatching($recorded, ['HANDLED_BY'], ['App\\Broadcasting\\OrderChannel@join']));
    }

    public function test_runtime_surface_models_use_package_connection_labels_and_keys(): void
    {
        foreach ([[new EventNode, 'Event'], [new JobNode, 'Job'], [new ScheduledTaskNode, 'ScheduledTask']] as [$model, $label]) {
            $this->assertSame(Neo4jBoltClient::ELOQUENT_CONNECTION, $model->getConnectionName());
            $this->assertSame($label, $model->getLabel());
            $this->assertSame('key', $model->getKeyName());
            $this->assertFalse($model->usesTimestamps());
        }

        $job = new JobNode(['key' => 'App\\Jobs\\ExampleJob']);
        $this->assertStringContainsString('HANDLED_BY', (new EventNode(['key' => 'App\\Events\\OrderShipped']))->handledBy()->toSql());
        $this->assertStringContainsString('HANDLED_BY', $job->handledBy()->toSql());
        $this->assertStringContainsString(':QueueConnection', $job->usesConnection()->toSql());
        $this->assertStringContainsString('HANDLED_BY', (new ScheduledTaskNode(['key' => 'task']))->handledBy()->toSql());
    }

    public function test_event_listeners_are_replaced_on_rerun(): void
    {
        $this->recordEloquentCypher();
        $listener = static fn (string $identifier): array => [
            'key' => 'App\\Events\\OrderShipped',
            'name' => 'OrderShipped',
            'action' => $identifier.'@handle',
            'identifier' => $identifier,
            'identifier_kind' => 'Class',
        ];

        $this->writeRows([6 => [$listener('App\\Listeners\\SendEmail'), $listener('App\\Listeners\\NotifySlack'), $listener('App\\Listeners\\SendEmail')]]);
        $recorded = $this->takeEloquentCypher();

        $this->assertCount(1, $this->statementsMatching($recorded, [':Event', 'DETACH DELETE'], ['App\\Events\\OrderShipped']));
        $this->assertCount(1, $this->statementsMatching($recorded, ['CREATE (n0:Event']));
        $this->assertCount(2, $this->statementsMatching($recorded, ['HANDLED_BY']));

        $this->writeRows([6 => [$listener('App\\Listeners\\WriteAuditLog')]]);
        $recorded = $this->takeEloquentCypher();

        $this->assertCount(1, $this->statementsMatching($recorded, [':Event', 'DETACH DELETE']));
        $this->assertCount(1, $this->statementsMatching($recorded, ['HANDLED_BY']));
        $this->assertCount(1, $this->statementsMatching($recorded, ['HANDLED_BY'], ['App\\Events\\OrderShipped', 'App\\Listeners\\WriteAuditLog']));
    }

    public function test_job_is_recreated_with_current_connection_and_queue_connections_update_in_place(): void
    {
        $this->recordEloquentCypher();
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

        $this->writeRows([7 => [$job('sqs')], 8 => $queueRows]);
        $recorded = $this->takeEloquentCypher();

        $this->assertCount(0, $this->statementsMatching($recorded, [':QueueConnection', 'DETACH DELETE']));
        $this->assertCount(1, $this->statementsMatching($recorded, ['CREATE (n0:QueueConnection', 'driver'], ['sqs', false]));
        $this->assertCount(1, $this->statementsMatching($recorded, [':Job', 'DETACH DELETE'], ['App\\Jobs\\ExampleJob']));
        $this->assertCount(1, $this->statementsMatching($recorded, ['CREATE (n0:Job'], [true, false]));
        $this->assertCount(1, $this->statementsMatching($recorded, ['USES_CONNECTION'], ['App\\Jobs\\ExampleJob', 'sqs']));

        $this->writeRows([7 => [$job('')], 8 => $queueRows]);

        $this->assertCount(0, $this->statementsMatching($this->takeEloquentCypher(), ['USES_CONNECTION']));
    }

    public function test_scheduled_task_is_recreated_with_current_handler(): void
    {
        $this->recordEloquentCypher();
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

        $this->writeRows([9 => [$task('App\\Console\\Commands\\Inspire')]]);
        $recorded = $this->takeEloquentCypher();

        $this->assertCount(1, $this->statementsMatching($recorded, [':ScheduledTask', 'DETACH DELETE'], ['schedule:inspire']));
        $this->assertCount(1, $this->statementsMatching($recorded, ['CREATE (n0:ScheduledTask'], ['0 * * * *', true, false]));
        $this->assertCount(1, $this->statementsMatching($recorded, ['HANDLED_BY'], ['schedule:inspire', 'App\\Console\\Commands\\Inspire']));

        $this->writeRows([9 => [$task('')]]);
        $recorded = $this->takeEloquentCypher();

        $this->assertCount(1, $this->statementsMatching($recorded, [':ScheduledTask', 'DETACH DELETE']));
        $this->assertCount(0, $this->statementsMatching($recorded, ['HANDLED_BY']));
    }

    public function test_write_strips_legacy_abstract_secondary_labels(): void
    {
        $connection = new TrackingContainerGraphConnection;
        $writer = new ContainerGraphWriter($connection);

        $writer->write([], [], []);

        $this->assertTrue($connection->ranStatementMatching('REMOVE a:Interface, a:Class, a:AbstractType'));
        $this->assertTrue($connection->ranStatementMatching('MATCH (a:Abstract)'));
    }

    public function test_contextual_binds_cypher_sets_needs_and_give_metadata(): void
    {
        $writer = new ContainerGraphWriter(new UnusedContainerGraphConnection);
        $contextualTemplate = $writer->cypherTemplates()['contextual_binds'];

        $this->assertStringContainsString('CONTEXTUAL_BINDS', $contextualTemplate);
        $this->assertStringContainsString('r.needs = row.needs', $contextualTemplate);
        $this->assertStringContainsString('r.needs_kind = row.needs_kind', $contextualTemplate);
        $this->assertStringContainsString(':Instance', $contextualTemplate);
        $this->assertStringContainsString(':Abstract', $contextualTemplate);
        $this->assertStringNotContainsString(':Identifier', $contextualTemplate);
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
        $arguments = array_fill(0, 22, []);
        foreach ($rowsByPosition as $position => $rows) {
            $arguments[$position] = $rows;
        }

        (new ContainerGraphWriter(new TrackingContainerGraphConnection))->write(...$arguments);
    }
}
