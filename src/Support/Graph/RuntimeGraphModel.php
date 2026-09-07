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
 *
 * Abstract is the container lookup key (same concept as make($abstract) / bind($abstract)),
 * with secondary labels Interface, Class, or AbstractType.
 */
final class RuntimeGraphModel
{
    public const LABEL_ROUTE = 'Route';

    public const LABEL_EVENT = 'Event';

    public const LABEL_JOB = 'Job';

    public const LABEL_QUEUE_CONNECTION = 'QueueConnection';

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

    /** Unique property on Route nodes (method + URI). */
    public const ROUTE_KEY = 'key';

    /** Unique property on Event nodes (registered event name / FQCN). */
    public const EVENT_KEY = 'key';

    /** Unique property on Job nodes (FQCN). */
    public const JOB_KEY = 'key';

    /** Unique property on QueueConnection nodes (connection name). */
    public const QUEUE_CONNECTION_KEY = 'key';

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
}
