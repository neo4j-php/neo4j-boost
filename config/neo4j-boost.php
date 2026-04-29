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
    */
    'transport' => [
        'driver' => env('NEO4J_MCP_TRANSPORT', 'http'),
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

    /*
    |--------------------------------------------------------------------------
    | HTTP (when transport.driver = http)
    |--------------------------------------------------------------------------
    |
    | MCP server endpoint and optional Basic Auth. Used when the Neo4j MCP
    | server is already running elsewhere (e.g. Docker on port 8080).
    |
    */
    'neo4j_mcp' => [
        'version' => 'v1.4.0',
        'binary_path' => null, // null = use storage_path('app/neo4j-mcp/neo4j-mcp'); set to absolute path to override.
        'platform_asset' => null, // null = auto-detect (Linux_x86_64, Linux_arm64, Darwin_*, Windows_*). Override e.g. 'Linux_x86_64'.
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
    ],
];
