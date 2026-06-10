<?php

namespace Neo4j\LaravelBoost\Support\Graph;

/**
 * Agent-facing glossary for container graph relationship types (SOFT-41).
 */
final class GraphRelationshipGlossary
{
    public const MCP_TOOL_DESCRIPTION_SUFFIX = ' Graph model: dependencies use DEPENDS_ON with type constructor_injection (DI via constructor), method_injection (DI via action/handle method), facade (static facade call), global_helper (cache/auth/view helpers), service_location (app/resolve/App::make), instantiation (direct new). Bindings use BINDS_TO with type normal (transient bind) or singleton (shared instance). Legacy graphs without type are inferred as constructor_injection or normal.';
}
