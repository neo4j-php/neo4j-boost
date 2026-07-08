# Neo4j Laravel Boost

Laravel integration for the [official Neo4j MCP server](https://github.com/neo4j/mcp/releases). It exposes Neo4j tools — `get-schema`, `read-cypher`, `write-cypher`, `list-gds-procedures`, `get-class-dependency-graph`, `contribute-graph-knowledge` — to MCP clients like Cursor or Claude.

**Requirements:** PHP 8.2+, Laravel 12 or 13, [Laravel Boost](https://github.com/laravel/boost).

Release notes: [CHANGELOG.md](CHANGELOG.md).

---

## Why use it

Your AI coding assistant (Cursor, Claude, etc.) cannot query your database schema or run Cypher directly — it can only read files. This package bridges that gap by wiring the official Neo4j MCP server into Laravel Boost, so your assistant can:

- Inspect your live Neo4j schema
- Run read/write Cypher queries
- Query your Laravel container's dependency graph as a graph

You get **one** MCP server entry in Cursor (`php artisan boost:mcp`) that covers both Laravel Boost tools and Neo4j tools.

---

## Installation

### 1. Install the package

```bash
composer require laravel/boost laravel/mcp neo4j/laravel-boost
```

### 2. Configure your `.env`

By default the package uses **driver transport** — Neo4j tools run in PHP over Bolt, so no `neo4j-mcp` binary is required. Add to your `.env`:

```env
NEO4J_URI=bolt://localhost:7687
NEO4J_USERNAME=neo4j
NEO4J_PASSWORD=your-password
```

`NEO4J_MCP_TRANSPORT` defaults to `driver`. Only set it when switching to STDIO or HTTP (see Notes below).

### 3. Run interactive setup

```bash
php artisan neo4j-boost:setup
```

This validates your connection, writes Cursor MCP config (`.cursor/mcp.json`), and optionally starts a local Neo4j Docker instance and installs the `neo4j-mcp` binary (only needed for STDIO transport).

### 4. Start a local Neo4j instance (if you don't have one)

```bash
php artisan neo4j-boost:start-neo4j
```

Starts a local Docker Neo4j instance on `bolt://localhost:7687` and `http://localhost:7474`, with APOC defaults required by schema tools.

### 5. Reload Cursor

Open your **Laravel application folder** as the Cursor workspace and reload MCP settings. Enable the `laravel-boost` server entry. Neo4j tools will appear alongside Boost tools once connected.

---

## Usage

### Cursor MCP config

The setup command writes this to `.cursor/mcp.json`:

```json
"mcpServers": {
  "laravel-boost": {
    "command": "php",
    "args": ["artisan", "boost:mcp"],
    "env": {
      "APP_ENV": "local"
    }
  }
}
```

You can also regenerate it manually:

```bash
php artisan neo4j-boost:cursor-config
```

### Container dependency graph

Export your Laravel container's runtime wiring into Neo4j so an LLM can query dependency resolution as a graph:

```bash
php artisan container:graph
php artisan container:graph --dry-run
php artisan container:graph --print-cypher
```

> **Large codebases:** The export reflects all PSR-4 classes in the container. On apps with hundreds of services it will take meaningful time and produce a large graph — that's expected.

To also detect hidden dependencies (service-location calls, facade usage, direct `new` instantiation), opt in to static scanning by setting the paths to scan in `.env`:

```env
NEO4J_CONTAINER_GRAPH_STATIC_SCAN_PATHS=/absolute/path/to/app/Services,/absolute/path/to/app/Http
```

Or publish the config and set `container_graph.static_scan_paths`. When unset, no PHP files are scanned and only runtime reflection edges are exported.

After exporting, use the **get-class-dependency-graph** MCP tool to query a specific class:

```json
{ "class": "App\\Services\\FooService", "direction": "outbound", "depth": 4 }
```

### Available Artisan commands

| Command | Description |
|--------|-------------|
| `neo4j-boost:setup` | Interactive setup (connection check, optional Docker Neo4j, Cursor config) |
| `neo4j-boost:start-neo4j` | Start local Neo4j Docker instance |
| `neo4j-boost:cursor-config` | Create or update `.cursor/mcp.json` |
| `neo4j-boost:install-mcp` | Download/install the official `neo4j-mcp` binary (STDIO only) |
| `neo4j-boost:doctor` | Diagnose transport, binary, password, and readiness |
| `neo4j-boost:test-stdio` | Verbose end-to-end STDIO handshake/tool test |
| `container:graph` | Export Laravel container bindings into Neo4j (`--dry-run`, `--print-cypher`) |

---

## Notes

**Transport modes:** The default is `driver` (Bolt in PHP, no binary needed). Two alternatives:

- **STDIO** — spawns the official `neo4j-mcp` binary as a subprocess. Install it with `php artisan neo4j-boost:install-mcp`, then set `NEO4J_MCP_TRANSPORT=stdio` in `.env`.
- **HTTP** — connects to a remote or containerised MCP server. Set `NEO4J_MCP_TRANSPORT=http` and `NEO4J_MCP_URL=http://localhost:8080/mcp`. In HTTP mode the package sends Neo4j credentials per-request; do **not** set `NEO4J_USERNAME`/`NEO4J_PASSWORD` on the MCP server container itself.

After editing `.env`, always run `php artisan config:clear` so Laravel picks up the change.

**Docker Compose (HTTP mode — Neo4j + MCP server):**

```yaml
services:
  neo4j:
    image: neo4j:5-community
    environment:
      NEO4J_AUTH: neo4j/your-password
      NEO4J_PLUGINS: '["apoc", "graph-data-science"]'
      NEO4J_dbms_security_procedures_unrestricted: 'apoc.*,gds.*'
      NEO4J_dbms_security_procedures_allowlist: 'apoc.*,gds.*'
    ports:
      - "7474:7474"
      - "7687:7687"
    healthcheck:
      test: ["CMD-SHELL", "wget -q -O /dev/null http://localhost:7474 || exit 1"]
      interval: 10s
      timeout: 5s
      retries: 5
      start_period: 15s

  # Official Neo4j MCP server (HTTP mode)
  neo4j-mcp:
    image: mcp/neo4j
    environment:
      NEO4J_URI: bolt://neo4j:7687
      NEO4J_DATABASE: neo4j
      NEO4J_READ_ONLY: "false"
      NEO4J_TELEMETRY: "false"
      NEO4J_TRANSPORT_MODE: http
      NEO4J_MCP_HTTP_HOST: 0.0.0.0
      NEO4J_MCP_HTTP_PORT: "8080"
    ports:
      - "8080:8080"
    depends_on:
      neo4j:
        condition: service_healthy
```

Then in your Laravel `.env`:

```env
NEO4J_MCP_TRANSPORT=http
NEO4J_MCP_URL=http://localhost:8080/mcp
NEO4J_URI=bolt://localhost:7687
NEO4J_USERNAME=neo4j
NEO4J_PASSWORD=your-password
```

**GDS plugin (`list-gds-procedures`):** Requires the [Graph Data Science](https://neo4j.com/docs/graph-data-science/current/) plugin. Without it that tool errors; `get-schema`, `read-cypher`, and `write-cypher` still work. For Docker, add `NEO4J_PLUGINS: '["apoc", "graph-data-science"]'` and the appropriate `NEO4J_dbms_security_procedures_*` env vars to your Neo4j service.

**Automate setup after deploys:** Add to your app's `composer.json`:

```json
{
  "scripts": {
    "post-update-cmd": [
      "@php artisan neo4j-boost:setup --no-interaction"
    ]
  }
}
```

**Publish config** (optional):

```bash
php artisan vendor:publish --tag=neo4j-boost-config
```

Key options in `config/neo4j-boost.php`: `neo4j_mcp.transport` (`driver` / `stdio` / `http`), `bolt.uri` / `bolt.username` / `bolt.password`, `http.url`, `http.username` / `http.password`, `container_graph.uri`.

**Binary platform support** (`neo4j-boost:install-mcp`): Linux (x86_64, arm64, i386), macOS (x86_64, Apple Silicon), Windows (x86_64, arm64, i386). Windows uses `.zip` and requires `ext-zip`; Linux/macOS use `.tar.gz` with no extra extensions. Override auto-detection with `NEO4J_MCP_PLATFORM_ASSET` in `.env`.

**Common issues:**

- *"Could not open input file: artisan"* — Open the Laravel app folder as the Cursor workspace, not the package directory.
- *"There are no commands defined in the 'boost' namespace"* — Add `"env": { "APP_ENV": "local" }` to the server entry in `.cursor/mcp.json`. Laravel Boost only registers commands when `APP_ENV=local` or `APP_DEBUG=true`.
- *STDIO fails with "Neo4j password is required"* — Set `NEO4J_PASSWORD` in `.env` and run `php artisan config:clear`.
- *APOC/meta errors* — Recreate Neo4j with required plugins: `php artisan neo4j-boost:start-neo4j --recreate`.
- *Docker: cannot connect to `bolt://localhost:7687`* — Set `NEO4J_URI` to the Neo4j service hostname on the container network (e.g. `neo4j://neo4j:password@neo4j-core1:7687`). Re-publish config and run `php artisan config:clear` if you use `config:cache`.
- *HTTP 404 "This server only handles requests to /mcp"* — Cursor may send GET requests; the Neo4j MCP server only accepts POST on `/mcp`. Use Laravel Boost (`php artisan boost:mcp`) so Cursor talks to one stdio server and this package calls Neo4j internally. If connecting Cursor directly, ensure the URL ends with `/mcp` and `NEO4J_TRANSPORT_MODE=http` is set on the server.
- *"Unknown function 'gds.version'"* — The GDS plugin is not installed. See GDS note above; `get-schema`, `read-cypher`, and `write-cypher` still work without it.

---

## License

MIT.

---

Maintained by [nagels.tech](https://nagels.tech/).
