## Neo4j Boost

This package integrates the official [Neo4j MCP](https://github.com/neo4j/mcp/releases) server into Laravel so you can use Neo4j tools from MCP clients (Cursor, Claude, etc.). It requires [Laravel Boost](https://github.com/laravel/boost).

### Transport

Set how this package reaches Neo4j MCP tools with `NEO4J_MCP_TRANSPORT`:

- **`stdio` (default):** run the local official `neo4j-mcp` binary. Install with `php artisan neo4j-boost:setup` or `php artisan neo4j-boost:install-mcp`. Requires `NEO4J_URI`, `NEO4J_USERNAME`, and `NEO4J_PASSWORD`.
- **`http`:** call a remote Neo4j MCP server. Set `NEO4J_MCP_URL=http://localhost:8080/mcp` (optional `NEO4J_MCP_USERNAME` / `NEO4J_MCP_PASSWORD`).
- **`driver`:** run tools in-process over Bolt via `laudis/neo4j-php-client` (no MCP binary). Set `NEO4J_URI` / credentials or `NEO4J_DEFAULT_CONNECTION_DSN`.

Local helpers: `php artisan neo4j-boost:start-neo4j` (Docker + APOC), `php artisan neo4j-boost:doctor`, `php artisan neo4j-boost:test-stdio`.

**Note:** `NEO4J_TRANSPORT_MODE` configures the official neo4j-mcp process/container itself. Laravel apps select transport with `NEO4J_MCP_TRANSPORT`.

### Cursor config

```bash
php artisan neo4j-boost:cursor-config
```

This creates or updates `.cursor/mcp.json` with a **`laravel-boost`** entry that runs `php artisan boost:mcp` (merged with existing servers). Prefer one MCP server for Boost + Neo4j tools.

### Run the MCP server

Use a single MCP server: `php artisan boost:mcp`. This package adds Neo4j tools to Boost automatically:

- Proxied official tools: **get-schema**, **read-cypher**, **write-cypher**, **list-gds-procedures** (via `NEO4J_MCP_TRANSPORT`)
- Package-native: **get-class-dependency-graph** (reads Neo4j directly using `config/neo4j-boost.container_graph` after `php artisan container:graph`)

Set `NEO4J_URI`, `NEO4J_USERNAME`, and `NEO4J_PASSWORD` for STDIO/driver (and wherever the Neo4j MCP server runs for HTTP).

**GDS (list-gds-procedures):** Install the Graph Data Science plugin in Neo4j. With Docker, set `NEO4J_PLUGINS: '["apoc", "graph-data-science"]'`, `NEO4J_dbms_security_procedures_unrestricted: 'apoc.*,gds.*'`, and `NEO4J_dbms_security_procedures_allowlist: 'apoc.*,gds.*'`. Local `neo4j-boost:start-neo4j` enables APOC only.

### Config

Publish with `php artisan vendor:publish --tag=neo4j-boost-config`. Options in `config/neo4j-boost.php` include `neo4j_mcp.transport`, `transport.stdio`, `transport.http`, `bolt`, and `container_graph`.

### Container graph POC

Export Laravel container wiring into Neo4j for dependency debugging:

```bash
php artisan container:graph
php artisan container:graph --dry-run
php artisan container:graph --print-cypher
```

Env vars for direct Neo4j connection: set `NEO4J_URI` (and user/password), or set only `NEO4J_DEFAULT_CONNECTION_DSN` (e.g. `neo4j://user:pass@neo4j-core1:7687` in Docker) so the same DSN as the app can be reused. Binding keys and discovered project classes use `:Abstract` plus `:Interface` or `:Class`; explore with `MATCH p=(a:Abstract)-[:BINDS_TO|DEPENDS_ON*1..10]->(n) RETURN p LIMIT 200` or undirected `-[r:BINDS_TO|DEPENDS_ON]-` in Neo4j Browser.

**get-class-dependency-graph** (MCP tool): pass a fully-qualified class name to get structured DI dependencies/dependents from the exported graph. Prerequisite: run `php artisan container:graph` first. Example argument: `{ "class": "App\\\\Services\\\\FooService", "direction": "outbound", "page": 1, "per_page": 100 }`.

```env
NEO4J_URI=bolt://localhost:7687
NEO4J_USER=neo4j
NEO4J_PASSWORD=password
```

### Cursor: "Loading tools" stuck or HTTP 404

- Open your **Laravel app folder** (the project where you ran `composer require neo4j/laravel-boost`) as the Cursor workspace, not the neo4j-boost package folder.
- If `.cursor/mcp.json` is missing, run `php artisan neo4j-boost:cursor-config` to create it.
- Ensure Laravel Boost can register commands (`APP_ENV=local` or `APP_DEBUG=true`).
- For HTTP transport, ensure the Neo4j MCP server is running at `NEO4J_MCP_URL` with HTTP transport.
- If you see **404 "This server only handles requests to /mcp"**: Cursor may send GET requests (e.g. for SSE); the Neo4j MCP server only accepts POST on `/mcp`. Using **Boost** (one server: `boost:mcp`) avoids Cursor talking to Neo4j MCP HTTP directly. Default STDIO transport runs the local binary instead of HTTP.
