<?php

namespace Neo4j\LaravelBoost\Support\Graph;

/**
 * Canonical runtime dependency graph labels, keys, and relationship types.
 *
 * Route -[:HANDLED_BY]-> Abstract -[:RESOLVES_TO]-> Instance
 *   -[:DEPENDS_ON]-> Dependency -[:IDENTIFIED_AS]-> Abstract
 * Route -[:USES_MIDDLEWARE]-> Middleware -[:IDENTIFIED_AS]-> Abstract
 * Event -[:HANDLED_BY]-> Abstract -[:RESOLVES_TO]-> Instance
 * Job -[:HANDLED_BY]-> Abstract -[:RESOLVES_TO]-> Instance
 * Job -[:USES_CONNECTION]-> QueueConnection
 * ScheduledTask -[:HANDLED_BY]-> Abstract -[:RESOLVES_TO]-> Instance
 * AuthGuard -[:USES_PROVIDER]-> AuthProvider -[:USES_MODEL]-> Abstract
 * PasswordBroker -[:USES_PROVIDER]-> AuthProvider
 * Policy -[:HANDLED_BY]-> Abstract
 * Policy -[:FOR_MODEL]-> Abstract
 * GateAbility -[:HANDLED_BY]-> Abstract
 *
 * Abstract is the container lookup key (same concept as make($abstract) / bind($abstract)).
 * Kind is stored on the node as property `kind` (Class, Interface, or AbstractType).
 */
final class RuntimeGraphModel
{
    public const LABEL_ROUTE = 'Route';

    public const LABEL_EVENT = 'Event';

    public const LABEL_JOB = 'Job';

    public const LABEL_QUEUE_CONNECTION = 'QueueConnection';

    public const LABEL_SCHEDULED_TASK = 'ScheduledTask';

    public const LABEL_AUTH_GUARD = 'AuthGuard';

    public const LABEL_AUTH_PROVIDER = 'AuthProvider';

    public const LABEL_PASSWORD_BROKER = 'PasswordBroker';

    public const LABEL_POLICY = 'Policy';

    public const LABEL_GATE_ABILITY = 'GateAbility';

    public const LABEL_INSTANCE = 'Instance';

    public const LABEL_DEPENDENCY = 'Dependency';

    public const LABEL_ABSTRACT = 'Abstract';

    public const LABEL_MIDDLEWARE = 'Middleware';

    public const REL_HANDLED_BY = 'HANDLED_BY';

    public const REL_RESOLVES_TO = 'RESOLVES_TO';

    public const REL_DEPENDS_ON = 'DEPENDS_ON';

    public const REL_IDENTIFIED_AS = 'IDENTIFIED_AS';

    public const REL_USES_MIDDLEWARE = 'USES_MIDDLEWARE';

    public const REL_USES_CONNECTION = 'USES_CONNECTION';

    public const REL_USES_PROVIDER = 'USES_PROVIDER';

    public const REL_USES_MODEL = 'USES_MODEL';

    public const REL_FOR_MODEL = 'FOR_MODEL';

    /** Unique property on Route nodes (method + URI). */
    public const ROUTE_KEY = 'key';

    /** Unique property on Event nodes (registered event name / FQCN). */
    public const EVENT_KEY = 'key';

    /** Unique property on Job nodes (FQCN). */
    public const JOB_KEY = 'key';

    /** Unique property on QueueConnection nodes (connection name). */
    public const QUEUE_CONNECTION_KEY = 'key';

    /** Unique property on ScheduledTask nodes. */
    public const SCHEDULED_TASK_KEY = 'key';

    /** Unique property on AuthGuard nodes (guard name). */
    public const AUTH_GUARD_KEY = 'key';

    /** Unique property on AuthProvider nodes (provider name). */
    public const AUTH_PROVIDER_KEY = 'key';

    /** Unique property on PasswordBroker nodes (broker name). */
    public const PASSWORD_BROKER_KEY = 'key';

    /** Unique property on Policy nodes (model FQCN). */
    public const POLICY_KEY = 'key';

    /** Unique property on GateAbility nodes (ability name). */
    public const GATE_ABILITY_KEY = 'key';

    /** Unique property on Instance / Abstract nodes. */
    public const NAME_KEY = 'name';

    /** Unique property on Dependency / Middleware nodes. */
    public const DEPENDENCY_KEY = 'key';

    /** Unique property on Middleware nodes. */
    public const MIDDLEWARE_KEY = 'key';

    /**
     * Cypher constraints that keep MERGE identities unique.
     *
     * @return list<string>
     */
    public static function constraintStatements(): array
    {
        return [
            'CREATE CONSTRAINT route_key IF NOT EXISTS FOR (n:Route) REQUIRE n.key IS UNIQUE',
            'CREATE CONSTRAINT event_key IF NOT EXISTS FOR (n:Event) REQUIRE n.key IS UNIQUE',
            'CREATE CONSTRAINT job_key IF NOT EXISTS FOR (n:Job) REQUIRE n.key IS UNIQUE',
            'CREATE CONSTRAINT queue_connection_key IF NOT EXISTS FOR (n:QueueConnection) REQUIRE n.key IS UNIQUE',
            'CREATE CONSTRAINT scheduled_task_key IF NOT EXISTS FOR (n:ScheduledTask) REQUIRE n.key IS UNIQUE',
            'CREATE CONSTRAINT auth_guard_key IF NOT EXISTS FOR (n:AuthGuard) REQUIRE n.key IS UNIQUE',
            'CREATE CONSTRAINT auth_provider_key IF NOT EXISTS FOR (n:AuthProvider) REQUIRE n.key IS UNIQUE',
            'CREATE CONSTRAINT password_broker_key IF NOT EXISTS FOR (n:PasswordBroker) REQUIRE n.key IS UNIQUE',
            'CREATE CONSTRAINT policy_key IF NOT EXISTS FOR (n:Policy) REQUIRE n.key IS UNIQUE',
            'CREATE CONSTRAINT gate_ability_key IF NOT EXISTS FOR (n:GateAbility) REQUIRE n.key IS UNIQUE',
            'CREATE CONSTRAINT instance_name IF NOT EXISTS FOR (n:Instance) REQUIRE n.name IS UNIQUE',
            'CREATE CONSTRAINT dependency_key IF NOT EXISTS FOR (n:Dependency) REQUIRE n.key IS UNIQUE',
            'CREATE CONSTRAINT abstract_name IF NOT EXISTS FOR (n:Abstract) REQUIRE n.name IS UNIQUE',
            'CREATE CONSTRAINT middleware_key IF NOT EXISTS FOR (n:Middleware) REQUIRE n.key IS UNIQUE',
        ];
    }

    /**
     * Recursive path from a Route through resolved dependency chains and middleware.
     */
    public static function routeTraversalCypher(): string
    {
        return <<<'CYPHER'
MATCH (r:Route {key: $routeKey})-[:HANDLED_BY]->(:Abstract)-[:RESOLVES_TO]->(root:Instance)
OPTIONAL MATCH path = (root)-[:DEPENDS_ON|IDENTIFIED_AS|RESOLVES_TO*0..8]->(n)
OPTIONAL MATCH mwPath = (r)-[:USES_MIDDLEWARE]->(:Middleware)-[:IDENTIFIED_AS]->(:Abstract)
RETURN r AS route, root AS rootInstance, collect(DISTINCT path) AS paths, collect(DISTINCT mwPath) AS middlewarePaths
CYPHER;
    }

    /**
     * Recursive path from an Event through resolved listener dependency chains.
     */
    public static function eventTraversalCypher(): string
    {
        return <<<'CYPHER'
MATCH (e:Event {key: $eventKey})-[:HANDLED_BY]->(:Abstract)-[:RESOLVES_TO]->(root:Instance)
OPTIONAL MATCH path = (root)-[:DEPENDS_ON|IDENTIFIED_AS|RESOLVES_TO*0..8]->(n)
RETURN e AS event, root AS rootInstance, collect(DISTINCT path) AS paths
CYPHER;
    }

    /**
     * Recursive path from a Job through resolved handler dependency chains and queue connection.
     */
    public static function jobTraversalCypher(): string
    {
        return <<<'CYPHER'
MATCH (j:Job {key: $jobKey})-[:HANDLED_BY]->(:Abstract)-[:RESOLVES_TO]->(root:Instance)
OPTIONAL MATCH path = (root)-[:DEPENDS_ON|IDENTIFIED_AS|RESOLVES_TO*0..8]->(n)
OPTIONAL MATCH conn = (j)-[:USES_CONNECTION]->(:QueueConnection)
RETURN j AS job, root AS rootInstance, collect(DISTINCT path) AS paths, collect(DISTINCT conn) AS connections
CYPHER;
    }

    /**
     * Recursive path from a ScheduledTask through its handler dependency chain.
     */
    public static function scheduledTaskTraversalCypher(): string
    {
        return <<<'CYPHER'
MATCH (t:ScheduledTask {key: $taskKey})-[:HANDLED_BY]->(:Abstract)-[:RESOLVES_TO]->(root:Instance)
OPTIONAL MATCH path = (root)-[:DEPENDS_ON|IDENTIFIED_AS|RESOLVES_TO*0..8]->(n)
RETURN t AS scheduledTask, root AS rootInstance, collect(DISTINCT path) AS paths
CYPHER;
    }

    /**
     * Auth guard through its provider and optional eloquent user model.
     */
    public static function authGuardTraversalCypher(): string
    {
        return <<<'CYPHER'
MATCH (g:AuthGuard {key: $guardKey})-[:USES_PROVIDER]->(p:AuthProvider)
OPTIONAL MATCH model = (p)-[:USES_MODEL]->(:Abstract)-[:RESOLVES_TO]->(root:Instance)
OPTIONAL MATCH path = (root)-[:DEPENDS_ON|IDENTIFIED_AS|RESOLVES_TO*0..8]->(n)
RETURN g AS authGuard, p AS authProvider, root AS rootInstance, collect(DISTINCT model) AS models, collect(DISTINCT path) AS paths
CYPHER;
    }

    /**
     * Policy through its policy class and subject model.
     */
    public static function policyTraversalCypher(): string
    {
        return <<<'CYPHER'
MATCH (p:Policy {key: $policyKey})-[:HANDLED_BY]->(:Abstract)-[:RESOLVES_TO]->(root:Instance)
OPTIONAL MATCH model = (p)-[:FOR_MODEL]->(:Abstract)
OPTIONAL MATCH path = (root)-[:DEPENDS_ON|IDENTIFIED_AS|RESOLVES_TO*0..8]->(n)
RETURN p AS policy, root AS rootInstance, collect(DISTINCT model) AS models, collect(DISTINCT path) AS paths
CYPHER;
    }

    /**
     * Gate ability through its class handler dependency chain.
     */
    public static function gateAbilityTraversalCypher(): string
    {
        return <<<'CYPHER'
MATCH (a:GateAbility {key: $abilityKey})
OPTIONAL MATCH (a)-[:HANDLED_BY]->(:Abstract)-[:RESOLVES_TO]->(root:Instance)
OPTIONAL MATCH path = (root)-[:DEPENDS_ON|IDENTIFIED_AS|RESOLVES_TO*0..8]->(n)
RETURN a AS gateAbility, root AS rootInstance, collect(DISTINCT path) AS paths
CYPHER;
    }
}
