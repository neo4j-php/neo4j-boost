# Neo4j Laravel Boost

Laravel integration for the [official Neo4j MCP server](https://github.com/neo4j/mcp/releases). Use Neo4j tools (get-schema, read-cypher, write-cypher, etc.) from MCP clients like Cursor or Claude.

Release notes: [CHANGELOG.md](CHANGELOG.md).

**Requirements:** PHP 8.2+, Laravel 12 or 13, [Laravel Boost](https://github.com/laravel/boost).

### CI (this repository)

GitHub Actions include four workflows on a **PHP × Laravel** matrix compatible with upstream constraints: **Laravel 12** on PHP **8.2** and **8.5**; **Laravel 13** (requires PHP **^8.3**) on PHP **8.3** and **8.5**. Workflows: [Pint](https://github.com/laravel/pint) (`.github/workflows/pint.yml`), [PHPStan](https://phpstan.org/) + [Larastan](https://github.com/larastan/larastan) (`.github/workflows/phpstan.yml`), PHPUnit (`.github/workflows/phpunit.yml`), and **[Testbench](https://packages.tools/testbench.html)** (`.github/workflows/testbench.yml`) — which runs `composer run build` then PHPUnit against [`Orchestra\Testbench\TestCase`](tests/TestCase.php).

Locally after `composer install`:

```bash
composer run ci
# or: ./vendor/bin/pint --test && ./vendor/bin/phpstan analyse -c phpstan.neon.dist --no-progress && ./vendor/bin/phpunit -c phpunit.xml.dist
```

### Workbench (`composer run serve`)

The [Orchestra Testbench](https://packages.tools/testbench.html) workbench is a small Laravel app inside this repo. **`composer run build`** only runs asset steps so it works **without** the PHP SQLite extension (`pdo_sqlite`). Session/cache/queue defaults are set in `testbench.yaml` (`env:`) so the skeleton does not need a SQL database for a quick `composer run serve`.

**Neo4j** is configured separately via **`NEO4J_*`** (Bolt) and **`NEO4J_MCP_*`** (MCP HTTP), not via `DB_*`. Defaults are in `testbench.yaml` under `env:`; override them by copying `workbench/.env.example` to `workbench/.env` and editing.

**Optional SQL (migrations / `DatabaseSeeder`):** install `php-sqlite3` (or configure MySQL in `workbench/.env`), then run `./vendor/bin/testbench workbench:create-sqlite-db` and `./vendor/bin/testbench migrate:fresh` if you need the database.

---

## Installation

### 1. Install the package

```bash
composer require neo4j/laravel-boost
```

### 2. Run interactive setup

```bash
php artisan neo4j-boost:setup
```

The setup command walks through installing the Neo4j MCP binary, starting HTTP mode, ensuring `.env` has `NEO4J_MCP_URL`, and writing Cursor MCP config.

### Optional: automate setup with a Composer hook

Add this to your app `composer.json` to run setup automatically after `composer update`:

```json
{
  "scripts": {
    "post-update-cmd": [
      "@php artisan neo4j-boost:setup --no-interaction"
    ]
  }
}
```

### 3. Configure Neo4j connection (for the MCP server)

The Neo4j MCP server itself needs Neo4j credentials. Configure those where the MCP server runs (e.g. its own env). If you use Laravel’s Neo4j driver elsewhere, add to your `.env`:

```env
NEO4J_URI=bolt://localhost:7687
NEO4J_USERNAME=neo4j
NEO4J_PASSWORD=your-password
```

### 4. (Optional) Cursor MCP config

To add the Neo4j MCP server to Cursor’s config (using the same HTTP URL):

```bash
php artisan neo4j-boost:cursor-config
```

This creates or updates `.cursor/mcp.json` with the server URL from `config/neo4j-boost.http.url` (or `NEO4J_MCP_URL`), merged with any existing MCP servers.

### 5. (Optional) Enable GDS for `list-gds-procedures`

The **list-gds-procedures** tool requires the [Graph Data Science](https://neo4j.com/docs/graph-data-science/current/) (GDS) plugin in Neo4j. Without it, that tool will error; other tools (get-schema, read-cypher, write-cypher) still work.

**Docker:** enable the GDS and APOC plugins and allow procedures:

```yaml
# docker-compose.yml (neo4j service)
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
```

**Non-Docker:** install the GDS plugin for your Neo4j version and configure procedure allowlists as in the [Neo4j GDS docs](https://neo4j.com/docs/graph-data-science/current/installation/).

---

## Single MCP server with Laravel Boost

This package requires [Laravel Boost](https://github.com/laravel/boost) and automatically adds Neo4j tools to Boost's MCP server, so you get **both** Boost tools and Neo4j tools from **one** server.

1. Install both packages and run the Neo4j MCP server over HTTP (e.g. Docker):

   ```bash
   composer require laravel/boost laravel/mcp neo4j/laravel-boost
   ```

   Set `NEO4J_MCP_URL` (and optional auth) in `.env`. Run the Neo4j MCP binary/server elsewhere with HTTP.

2. Use **one** Cursor MCP entry that runs Laravel Boost:

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

   **If your workspace is this package repo** (neo4j-boost): the `env` block is required so Laravel Boost registers its commands. In a normal Laravel app with `.env` already set to `APP_ENV=local`, you can omit `env` if you prefer.

3. This package adds its Neo4j tools to Boost's tool list. You get Boost tools (search-docs, browser-logs, database, etc.) **and** the official Neo4j tools (get-schema, read-cypher, write-cypher, list-gds-procedures). Neo4j tools call the HTTP MCP URL configured in `config/neo4j-boost.http`.


---

## Using with Cursor

1. Open your **Laravel application folder** (the project where you ran `composer require`) as the Cursor workspace—not the neo4j-boost package directory.
2. Reload Cursor or open MCP settings so it picks up `.cursor/mcp.json`.
3. Enable **laravel-boost** (one MCP server via `php artisan boost:mcp`). Cursor uses stdio; this package calls Neo4j MCP over HTTP internally. Tools (get-schema, read-cypher, write-cypher, list-gds-procedures) appear when the server is connected.

---

## Local development (this repo)

When developing the package and running Artisan from the repo (e.g. e2e testing `boost:mcp`), either:

- **Option A:** In `.cursor/mcp.json`, add `"env": { "APP_ENV": "local" }` to the `laravel-boost` server entry (see config above). Cursor will pass it when starting the process.
- **Option B:** Copy `.env.example` to `.env` in the repo root so that `php artisan boost:mcp` sees `APP_ENV=local` when run from the terminal or by Cursor.

---

## Artisan commands

| Command | Description |
|--------|-------------|
| `php artisan neo4j-boost:cursor-config` | Create or update `.cursor/mcp.json` with the Neo4j MCP server URL (merge with existing servers) |
| `php artisan container:graph` | Export Laravel container bindings/dependencies into Neo4j graph (`--dry-run`, `--print-cypher`) |

Neo4j tools exposed via Laravel Boost MCP (`php artisan boost:mcp`) include **get-class-dependency-graph**, which returns a structured dependency graph for a fully-qualified class (requires `container:graph` export first). Other tools: get-schema, read-cypher, write-cypher, list-gds-procedures.

---

## Container Graph POC (LLM Debugging)

This spike exports runtime Laravel container wiring into Neo4j so dependency resolution can be queried as a graph.

### Environment variables

**Option A – explicit URI (recommended for local dev):**

```env
NEO4J_URI=bolt://localhost:7687
NEO4J_USER=neo4j
NEO4J_PASSWORD=password
```

`NEO4J_USERNAME` is also supported as a fallback for `NEO4J_USER`.

**Option B – only a DSN (e.g. Docker / Laravel `NEO4J_DEFAULT_CONNECTION_DSN`):**

If `NEO4J_URI` is not set, `container:graph` uses `NEO4J_DEFAULT_CONNECTION_DSN` when it looks like a Neo4j URL (user and password can be embedded: `neo4j://user:pass@host:7687`).

This matches setups that already set the DSN in `docker-compose` and avoids duplicating the host. Inside Docker, use the Neo4j service host name (for example `neo4j-core1:7687`), not `localhost` in the DSN.

`config/neo4j-boost.php` exposes `container_graph.uri` and `container_graph.default_connection_dsn` (both read from the env vars above). Re-publish the config after upgrading the package if you use a published copy:

```bash
php artisan vendor:publish --tag=neo4j-boost-config --force
```

### Run

```bash
php artisan container:graph
php artisan container:graph --dry-run
php artisan container:graph --print-cypher
```

### Graph model

- `(:Interface:Abstract)-[:BINDS_TO {shared}]->(:Class:Abstract)` when the binding key is an interface
- `(:Class:Abstract)-[:BINDS_TO {shared}]->(:Class:Abstract)` when the binding key is a class
- `(:Class:Abstract)` class nodes are also added for discovered project classes (PSR-4 autoloaded classes from the app)
- **`Abstract`** – use as the entry label to start from registered binding keys and walk the graph (`MATCH (a:Abstract) …`).
- `(:Class:Abstract)-[:DEPENDS_ON]->(:Class:Abstract|:Interface:Abstract|:UnresolvedDependency:Abstract)`
- `(:UnresolvedDependency:Abstract {name, reason})`

### Example Cypher queries

For ad-hoc exploration you can still use **read-cypher**. For Laravel DI questions, prefer the **get-class-dependency-graph** MCP tool (after running `container:graph`):

```json
{ "class": "App\\Services\\FooService", "direction": "outbound", "depth": 4, "page": 1, "per_page": 100 }
```

Returns structured JSON with `dependencies`, `dependents`, `binding`, pagination metadata (`dependencies_pagination` / `dependents_pagination`), and `graph_export_required` when data is missing. Default page size is 100 entries.

**Explore from container binding keys outward (graph view in Neo4j Browser):**

```cypher
MATCH p = (a:Abstract)-[:BINDS_TO|DEPENDS_ON*1..10]->(n)
RETURN p
LIMIT 200;
```

**Bidirectional neighborhood (idiomatic; no duplicate reverse edges):**

```cypher
MATCH p = (a:Abstract)-[:BINDS_TO|DEPENDS_ON*1..6]-(n)
RETURN p
LIMIT 200;
```

Cycle-only patterns such as `(x:Abstract)-[*..]->(x)` mostly surface self-binds or trivial paths; prefer outward or undirected expansion above.

```cypher
MATCH (i:Interface:Abstract)-[:BINDS_TO]->(c:Class:Abstract)
RETURN i.name, c.name
LIMIT 25;
```

```cypher
MATCH p = (:Class:Abstract {name: 'App\\Services\\FooService'})-[:DEPENDS_ON*1..4]->(d)
RETURN p
LIMIT 10;
```

```cypher
MATCH (c:Class:Abstract)-[:DEPENDS_ON]->(u:UnresolvedDependency:Abstract)
RETURN c.name, u.name, u.reason
LIMIT 25;
```

Re-running the command is idempotent (`MERGE`-based), so nodes/relationships are not duplicated.

---

## Configuration

Publish the config file (optional):

```bash
php artisan vendor:publish --tag=neo4j-boost-config
```

Edit `config/neo4j-boost.php`:

- **`http.url`** – MCP endpoint (e.g. `http://localhost:8080/mcp`). Env: `NEO4J_MCP_URL`.
- **`http.username`** / **`http.password`** – Optional Basic Auth for the HTTP endpoint. Env: `NEO4J_MCP_USERNAME`, `NEO4J_MCP_PASSWORD` (fallback to `NEO4J_USERNAME` / `NEO4J_PASSWORD`).
- **`container_graph.uri`** / **`container_graph.default_connection_dsn`** – Used by `php artisan container:graph` for the direct Neo4j driver. Env: `NEO4J_URI`, `NEO4J_DEFAULT_CONNECTION_DSN` (DSN is used when `NEO4J_URI` is empty).

---

## Troubleshooting

- **"Could not open input file: artisan"** or **"Loading tools" stuck**  
  When using Laravel Boost, Cursor must run the MCP command from your Laravel app directory. Open the **Laravel app folder** as the workspace and ensure `.cursor/mcp.json` exists.

- **"Unexpected token … is not valid JSON"** or **"ERROR … Did you mean this? neo4j-boost"** when Cursor runs `boost:mcp`  
  The MCP client expects only JSON on stdout. That error usually means `boost:mcp` failed to start and Artisan printed a message to stdout (e.g. "There are no commands defined in the 'boost' namespace"). Laravel Boost only registers its commands when **APP_ENV=local** or **APP_DEBUG=true**. Fix: in `.cursor/mcp.json`, add `"env": { "APP_ENV": "local" }` to the `laravel-boost` server entry so Cursor passes it when starting the process. Alternatively, ensure `.env` in the project root has `APP_ENV=local` (or copy `.env.example` to `.env`).

- **Neo4j MCP HTTP errors**  
  Ensure the Neo4j MCP server is running with HTTP transport and that `NEO4J_MCP_URL` matches. Check the MCP server logs for connection or Neo4j errors.

- **`container:graph` connects to `bolt://localhost:7687` in Docker (or "Cannot connect to any server on alias: container-graph")**  
  Set `NEO4J_URI` to your Neo4j host on the container network, or set `NEO4J_DEFAULT_CONNECTION_DSN` to a full URL (for example `neo4j://neo4j:password@neo4j-core1:7687`). In Docker, `localhost` in the DSN/URI is the app container, not the Neo4j service. Re-publish `neo4j-boost` config after upgrading and run `php artisan config:clear` if you use `config:cache`.

- **HTTP 404: "This server only handles requests to /mcp"**  
  Cursor may try several connection methods (streamable HTTP, SSE) and can send **GET** requests. The official Neo4j MCP server in HTTP mode typically only accepts **POST** on `/mcp`, so those GETs return this 404.  
  **Recommended:** Use **Laravel Boost** so Cursor talks to one MCP server over stdio (`php artisan boost:mcp`). This package then calls the Neo4j MCP server over HTTP (POST only) from your app; Cursor never hits the Neo4j HTTP URL directly.  
  If you must connect Cursor directly to the Neo4j MCP URL: ensure the URL in `.cursor/mcp.json` ends with `/mcp` (run `php artisan neo4j-boost:cursor-config` to normalize it) and that the Neo4j MCP server is running with `NEO4J_TRANSPORT_MODE=http`. Compatibility depends on the client using POST to the configured URL.

- **GDS errors**  
  Messages like "Unknown function 'gds.version'" mean Neo4j does not have the GDS plugin. Install it and set procedure allowlists (see **Enable GDS** above). The MCP server still runs and standard Cypher (get-schema, read-cypher, write-cypher) works without GDS.

---

## License

MIT.
