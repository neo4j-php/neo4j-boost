## Neo4j Boost

This package integrates the official [Neo4j MCP](https://github.com/neo4j/mcp/releases) server into Laravel so you can use Neo4j tools from MCP clients (Cursor, Claude, etc.).

### Index

- [Transport modes](#transport-modes)
- [Cursor config](#cursor-config)
- [Run the MCP server](#run-the-mcp-server)
- [Config](#config)
- [Container graph POC](#container-graph-poc)
- [Cursor: "Loading tools" stuck or HTTP 404](#cursor-loading-tools-stuck-or-http-404)
- [Troubleshooting](#troubleshooting)

### Transport modes

The package supports **driver** (default — in-process Bolt via `laudis/neo4j-php-client`, no `neo4j-mcp` binary), **STDIO** (`NEO4J_MCP_TRANSPORT=stdio` — local `neo4j-mcp` binary), and **HTTP** (remote MCP server). For HTTP-only setups, run the Neo4j MCP server elsewhere (e.g. Docker) with HTTP transport, then set in `.env`:

```env
NEO4J_MCP_URL=http://localhost:8080/mcp
NEO4J_MCP_USERNAME=neo4j   # optional
NEO4J_MCP_PASSWORD=...     # optional
```

### Cursor config

To add the Neo4j MCP server to Cursor’s MCP config (using the same HTTP URL):

```bash
php artisan neo4j-boost:cursor-config
```

This creates or updates `.cursor/mcp.json` with the server URL from config (merged with existing servers).

### Run the MCP server

- **With Laravel Boost:** Use a single MCP server: run `php artisan boost:mcp`. This package adds the official Neo4j tools (get-schema, read-cypher, write-cypher, list-gds-procedures, **get-class-dependency-graph**, **contribute-graph-knowledge**) to Boost’s server automatically. By default, tools use **driver** transport (Bolt in PHP). Set `NEO4J_MCP_TRANSPORT=http` or `stdio` to change that. **get-class-dependency-graph** and **contribute-graph-knowledge** always read/write the container graph directly from Neo4j using `config/neo4j-boost.container_graph`.
- **Without Boost:** Add the Neo4j MCP server to Cursor as an HTTP server. Run `php artisan neo4j-boost:cursor-config` so `.cursor/mcp.json` includes the `neo4j-boost` server with the configured URL.

Set `NEO4J_URI`, `NEO4J_USERNAME`, and `NEO4J_PASSWORD` where the Neo4j MCP server runs (and in Laravel if you use the Neo4j driver).

**GDS (list-gds-procedures):** Install the Graph Data Science plugin in Neo4j. With Docker, set `NEO4J_PLUGINS: '["apoc", "graph-data-science"]'`, `NEO4J_dbms_security_procedures_unrestricted: 'apoc.*,gds.*'`, and `NEO4J_dbms_security_procedures_allowlist: 'apoc.*,gds.*'`.

### Config

Publish with `php artisan vendor:publish --tag=neo4j-boost-config`. Options in `config/neo4j-boost.php`: `http.url`, `http.username`, `http.password`.

### Container graph POC

Export Laravel container wiring into Neo4j for dependency debugging:

```bash
php artisan container:graph
php artisan container:graph --dry-run
php artisan container:graph --print-cypher
```

Env vars for direct Neo4j connection: set `NEO4J_URI` (and user/password), or set only `NEO4J_DEFAULT_CONNECTION_DSN` (e.g. `neo4j://user:pass@neo4j-core1:7687` in Docker) so the same DSN as the app can be reused. Runtime resolution uses `(:Route)-[:HANDLED_BY]->(:Identifier)-[:RESOLVES_TO]->(:Instance)-[:DEPENDS_ON]->(:Dependency)-[:IDENTIFIED_AS]->(:Identifier)` and `(:Route)-[:USES_MIDDLEWARE]->(:Middleware)-[:IDENTIFIED_AS]->(:Identifier)`. Bindings still also export `:Abstract`/`BINDS_TO`. Explore with:

```cypher
MATCH (r:Route)-[:HANDLED_BY]->(:Identifier)-[:RESOLVES_TO]->(root:Instance)
OPTIONAL MATCH path = (root)-[:DEPENDS_ON|IDENTIFIED_AS|RESOLVES_TO*0..8]->(n)
OPTIONAL MATCH mw = (r)-[:USES_MIDDLEWARE]->(:Middleware)-[:IDENTIFIED_AS]->(:Identifier)
RETURN r, root, path, mw LIMIT 50;
```

**get-class-dependency-graph** (MCP tool): pass a fully-qualified class name to get structured DI dependencies/dependents from the exported graph. Responses include `dependencies`, `declared_dependencies`, `hidden_dependencies`, per-edge `type` / `source` / `confidence` / `visibility`, and a `graph_completeness` block. Prerequisite: run `php artisan container:graph` first. Example argument: `{ "class": "App\\\\Services\\\\FooService", "direction": "outbound", "page": 1, "per_page": 100 }`.

**contribute-graph-knowledge** (MCP tool): add dependency or binding edges when static analysis cannot infer them. Medium/low confidence returns `confirmation_required` without writing — ask the user, then retry with `confirmed: true` to persist with `source: user`. High confidence persists immediately with `source: agent`. Default contributed `DEPENDS_ON` type is `service_location`.

**Dependency model:** `Route-[:HANDLED_BY]->Identifier-[:RESOLVES_TO {lifetime}]->Instance-[:DEPENDS_ON {file,line,via,type,method,parameter,helper,source,confidence,provenance,remarks,catalog_source}]->Dependency-[:IDENTIFIED_AS]->Identifier` and `Route-[:USES_MIDDLEWARE {order,parameters}]->Middleware-[:IDENTIFIED_AS]->Identifier`. `DEPENDS_ON.type`: `constructor_injection`, `method_injection` (controller actions, `handle()` on jobs/commands/listeners/middleware), `global_helper`, or `instantiation` (direct `new ClassName()`). `DEPENDS_ON.catalog_source` (facade edges): `laravel_facade` for predefined Laravel facades, `auto_discovered_facade` for custom app facades and real-time facades. `Dependency.access`: `di`, `facade`, `global_helper`, `service_location`. `RESOLVES_TO.lifetime`: `singleton` or `bind`. MCP dependency entries expose `access`, `lifetime`, `source`, `confidence`, `provenance`, optional `remarks`, method-injection metadata when present, `helper` for global helpers, `type` for instantiation, and `catalog_source` for facade edges. Responses include `graph_completeness: partial` with known limitations. Bindings: `BINDS_TO` with `normal` or `singleton` plus metadata on `:Abstract` nodes. Contextual `when/needs/give` overrides: `Instance-[:CONTEXTUAL_BINDS {needs,needs_kind,reason}]->Identifier`. Re-run `container:graph` after upgrades.


**Resolution catalog:** `Neo4j\LaravelBoost\ResolutionCatalog\ResolutionCatalog` maps facades to container contracts with `bindsToType` (`singleton` | `normal`) and a `source`. It covers predefined Laravel first-party facades (`laravel_facade`), auto-discovered custom app facades via `getFacadeAccessor()`, and real-time facades (`Facades\<FQCN>`) — the latter two reported as `auto_discovered_facade`. Static facade scanning resolves `Cache::put`-style calls through this catalog.

**Static scan (hidden dependencies):** set `NEO4J_CONTAINER_GRAPH_STATIC_SCAN_PATHS` to comma-separated absolute paths (e.g. `base_path('app/Services')`). The scan adds `DEPENDS_ON` edges with `source: static`, `provenance: static_scan`, and `confidence: high` (or `medium` for literal `config()` / `env()` keys) for **service_location** (`app()`, `resolve()`, `App::make()` with literal class args), **facade** (`Cache::put`, custom `Facade::method`, real-time `\Facades\App\...::method`, resolved via the resolution catalog and tagged with `catalog_source`), **global_helper** (`cache()`, `auth()`, `config('key')`, etc., resolved via the global helper catalog), and **instantiation** (`new ClassName()`, anonymous and dynamic class expressions skipped). Dynamic calls are skipped. Summary lines: `Static service_location edges`, `Static facade edges`, `Static global_helper edges`, and `Static instantiation edges`.

```env
NEO4J_URI=bolt://localhost:7687
NEO4J_USER=neo4j
NEO4J_PASSWORD=password
NEO4J_CONTAINER_GRAPH_STATIC_SCAN_PATHS=/var/www/html/app/Services
```

### Cursor: "Loading tools" stuck or HTTP 404

- Open your **Laravel app folder** (the project where you ran `composer require neo4j/laravel-boost`) as the Cursor workspace, not the neo4j-boost package folder.
- If `.cursor/mcp.json` is missing, run `php artisan neo4j-boost:cursor-config` to create it.
- Ensure the Neo4j MCP server is running at the URL set in `NEO4J_MCP_URL` and that it is started with HTTP transport.
- If you see **404 "This server only handles requests to /mcp"**: Cursor may send GET requests (e.g. for SSE); the Neo4j MCP server only accepts POST on `/mcp`. Using **Boost** (one server: `boost:mcp`) avoids this: Cursor uses stdio to Laravel and this package talks to Neo4j via driver (default), HTTP, or STDIO. Otherwise ensure the URL ends with `/mcp` and the server is running with HTTP transport.

### Troubleshooting

For Docker, Neo4j Aura, MCP client setup, authentication, environment variables, and common error messages with step-by-step fixes, see the package troubleshooting guide: `docs/TROUBLESHOOTING.md` (also linked from the README). Start with `php artisan neo4j-boost:doctor` after changing `.env`, then `php artisan config:clear`.
