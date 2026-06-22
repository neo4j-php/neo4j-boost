<?php

namespace Neo4j\LaravelBoost\Support\Graph;

/**
 * Agent-facing glossary for container graph relationship types (SOFT-41 / SOFT-58).
 */
final class GraphRelationshipGlossary
{
    public const MCP_TOOL_DESCRIPTION_SUFFIX = ' Graph model (SOFT-58): dependencies follow Instance-[:DEPENDS_ON {file,line,via}]->Dependency-[:RESOLVES_TO {lifetime}]->Identifier. DEPENDS_ON access on Dependency nodes: di (constructor/method DI), facade, global_helper, service_location (app/resolve/App::make). RESOLVES_TO lifetime: singleton or bind. Bindings remain BINDS_TO on Abstract nodes with type normal or singleton. Re-run container:graph after upgrades.';
}
