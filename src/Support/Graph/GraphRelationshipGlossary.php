<?php

namespace Neo4j\LaravelBoost\Support\Graph;

/**
 * Agent-facing glossary for container graph relationship types.
 */
final class GraphRelationshipGlossary
{
    public const MCP_TOOL_DESCRIPTION_SUFFIX = ' Graph model: dependencies follow Instance-[:DEPENDS_ON {file,line,via}]->Dependency-[:RESOLVES_TO {lifetime}]->Identifier. DEPENDS_ON access on Dependency nodes: di (constructor/method DI), facade, global_helper, service_location (app/resolve/App::make). RESOLVES_TO lifetime: singleton or bind. Bindings remain BINDS_TO on Abstract nodes with type normal or singleton. Contextual when/needs/give overrides export as Instance-[:CONTEXTUAL_BINDS {needs,needs_kind,reason}]->Identifier. Re-run container:graph after upgrades.';
}
