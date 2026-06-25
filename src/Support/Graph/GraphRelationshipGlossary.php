<?php

namespace Neo4j\LaravelBoost\Support\Graph;

/**
 * Agent-facing glossary for container graph relationship types.
 */
final class GraphRelationshipGlossary
{
    public const MCP_TOOL_DESCRIPTION_SUFFIX = ' Graph model: dependencies follow Instance-[:DEPENDS_ON {file,line,via,type,method,parameter,helper,source,confidence,provenance,remarks}]->Dependency-[:RESOLVES_TO {lifetime}]->Identifier. BINDS_TO edges include source, confidence, provenance, and remarks. MCP responses include graph_completeness: partial with known limitations. DEPENDS_ON type distinguishes constructor_injection, method_injection, global_helper, and instantiation (direct new ClassName()); access on Dependency nodes: di (constructor/method DI and instantiation), facade, global_helper (cache/auth/view/response/redirect/route/event/dispatch/logger/session/config/env), service_location (app/resolve/App::make). RESOLVES_TO lifetime: singleton or bind. Bindings remain BINDS_TO on Abstract nodes with type normal or singleton. Contextual when/needs/give overrides export as Instance-[:CONTEXTUAL_BINDS {needs,needs_kind,reason}]->Identifier. Re-run container:graph after upgrades.';
}
