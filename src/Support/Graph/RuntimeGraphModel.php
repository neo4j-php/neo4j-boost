<?php

namespace Neo4j\LaravelBoost\Support\Graph;

/**
 * Canonical runtime dependency graph labels, keys, and relationship types.
 *
 * Route -[:HANDLED_BY]-> Identifier -[:RESOLVES_TO]-> Instance
 *   -[:DEPENDS_ON]-> Dependency -[:IDENTIFIED_AS]-> Identifier
 * Route -[:USES_MIDDLEWARE]-> Middleware -[:IDENTIFIED_AS]-> Identifier
 */
final class RuntimeGraphModel
{
    public const LABEL_ROUTE = 'Route';

    public const LABEL_INSTANCE = 'Instance';

    public const LABEL_DEPENDENCY = 'Dependency';

    public const LABEL_IDENTIFIER = 'Identifier';

    public const LABEL_MIDDLEWARE = 'Middleware';

    public const REL_HANDLED_BY = 'HANDLED_BY';

    public const REL_RESOLVES_TO = 'RESOLVES_TO';

    public const REL_DEPENDS_ON = 'DEPENDS_ON';

    public const REL_IDENTIFIED_AS = 'IDENTIFIED_AS';

    public const REL_USES_MIDDLEWARE = 'USES_MIDDLEWARE';

    /** Unique property on Route nodes (method + URI). */
    public const ROUTE_KEY = 'key';

    /** Unique property on Instance / Identifier nodes. */
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
            'CREATE CONSTRAINT instance_name IF NOT EXISTS FOR (n:Instance) REQUIRE n.name IS UNIQUE',
            'CREATE CONSTRAINT dependency_key IF NOT EXISTS FOR (n:Dependency) REQUIRE n.key IS UNIQUE',
            'CREATE CONSTRAINT identifier_name IF NOT EXISTS FOR (n:Identifier) REQUIRE n.name IS UNIQUE',
            'CREATE CONSTRAINT middleware_key IF NOT EXISTS FOR (n:Middleware) REQUIRE n.key IS UNIQUE',
        ];
    }

    /**
     * Recursive path from a Route through resolved dependency chains and middleware.
     */
    public static function routeTraversalCypher(): string
    {
        return <<<'CYPHER'
MATCH (r:Route {key: $routeKey})-[:HANDLED_BY]->(:Identifier)-[:RESOLVES_TO]->(root:Instance)
OPTIONAL MATCH path = (root)-[:DEPENDS_ON|IDENTIFIED_AS|RESOLVES_TO*0..8]->(n)
OPTIONAL MATCH mwPath = (r)-[:USES_MIDDLEWARE]->(:Middleware)-[:IDENTIFIED_AS]->(:Identifier)
RETURN r AS route, root AS rootInstance, collect(DISTINCT path) AS paths, collect(DISTINCT mwPath) AS middlewarePaths
CYPHER;
    }
}
