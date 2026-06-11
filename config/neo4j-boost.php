<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Transport
    |--------------------------------------------------------------------------
    |
    | How this package talks to the Neo4j MCP server:
    |
    | - http  : Connect to a remote MCP server over HTTP (e.g. a separate
    |           neo4j-mcp process or container). Set NEO4J_MCP_TRANSPORT=http
    |           and configure the "http" section below.
    |
    | - stdio  : Run the MCP server as a subprocess and talk over stdin/stdout.
    |           Set NEO4J_MCP_TRANSPORT=stdio and configure the "stdio" section.
    |           The subprocess receives NEO4J_URI, NEO4J_USERNAME, NEO4J_PASSWORD
    |           from transport.stdio.env so it can connect to Neo4j.
    |
    | - driver : Run MCP tools in PHP via laudis/neo4j-php-client (Bolt). No
    |           neo4j-mcp binary required. Set NEO4J_MCP_TRANSPORT=driver and
    |           NEO4J_URI / NEO4J_USERNAME / NEO4J_PASSWORD (or DSN).
    |
    */
    'transport' => [
        'driver' => env('NEO4J_MCP_TRANSPORT', 'stdio'),
        'stdio' => [
            'command' => env('NEO4J_MCP_STDIO_COMMAND', 'neo4j-mcp'),
            'env' => [
                'NEO4J_URI' => env('NEO4J_URI', 'bolt://localhost:7687'),
                'NEO4J_USERNAME' => env('NEO4J_USERNAME', 'neo4j'),
                'NEO4J_PASSWORD' => env('NEO4J_PASSWORD', ''),
            ],
        ],

        'http' => [
            'url' => env('NEO4J_MCP_URL', 'http://localhost:8080/mcp'),
            'username' => env('NEO4J_MCP_USERNAME', env('NEO4J_USERNAME')),
            'password' => env('NEO4J_MCP_PASSWORD', env('NEO4J_PASSWORD')),
        ],
    ],

    'neo4j_mcp' => [
        'transport' => env('NEO4J_MCP_TRANSPORT', 'stdio'),
        'version' => env('NEO4J_MCP_VERSION', 'v1.4.0'),
        'binary_path' => env('NEO4J_MCP_BINARY_PATH'),
        'platform_asset' => env('NEO4J_MCP_PLATFORM_ASSET'),
        'auto_install' => env('NEO4J_MCP_AUTO_INSTALL', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Bolt / driver transport (when NEO4J_MCP_TRANSPORT=driver)
    |--------------------------------------------------------------------------
    |
    | Direct Bolt connection used by Neo4jDriverClient via Neo4jBoltClient.
    | Reuses NEO4J_URI, NEO4J_USERNAME, and NEO4J_PASSWORD (or DSN fallback).
    |
    | get-schema uses apoc.meta.schema when APOC is installed; otherwise a
    | simpler catalog fallback (db.labels / db.relationshipTypes) is returned.
    |
    */
    'bolt' => [
        'uri' => env('NEO4J_URI', 'bolt://localhost:7687'),
        'username' => env('NEO4J_USERNAME', 'neo4j'),
        'password' => env('NEO4J_PASSWORD', ''),
        'database' => env('NEO4J_DATABASE', 'neo4j'),
        'schema_sample_size' => (int) env('NEO4J_SCHEMA_SAMPLE_SIZE', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | container:graph
    |--------------------------------------------------------------------------
    |
    | `php artisan container:graph` connects directly to Neo4j. Prefer NEO4J_URI
    | (e.g. neo4j:// or bolt://) plus NEO4J_USER / NEO4J_PASSWORD. If NEO4J_URI is
    | not set, NEO4J_DEFAULT_CONNECTION_DSN (full URL, optionally with user:pass@)
    | is used so the same DSN as Docker or database config can drive this command.
    |
    */
    'container_graph' => [
        'uri' => env('NEO4J_URI', ''),
        'default_connection_dsn' => env('NEO4J_DEFAULT_CONNECTION_DSN', ''),
        'username' => env('NEO4J_USER', env('NEO4J_USERNAME', 'neo4j')),
        'password' => env('NEO4J_PASSWORD', ''),
        /*
         * Absolute paths scanned for hidden DEPENDS_ON edges (SOFT-43 POC).
         * Example: [base_path('app/Services')] — package tests set a fixture path.
         */
        'static_scan_paths' => array_values(array_filter(array_map(
            static fn (string $path): string => trim($path),
            explode(',', (string) env('NEO4J_CONTAINER_GRAPH_STATIC_SCAN_PATHS', '')),
        ))),
    ],
];
