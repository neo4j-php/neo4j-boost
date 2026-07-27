# Neo4j Laravel Boost

Laravel integration for the [official Neo4j MCP server](https://github.com/neo4j/mcp/releases). Use Neo4j tools (get-schema, read-cypher, write-cypher, list-gds-procedures, get-class-dependency-graph) from MCP clients like Cursor or Claude.

Release notes: [CHANGELOG.md](CHANGELOG.md).

**Requirements:** PHP 8.2+, Laravel 12 or 13, [Laravel Boost](https://github.com/laravel/boost).

### CI (this repository)

GitHub Actions run on a **PHP × Laravel** matrix compatible with upstream constraints: **Laravel 12** on PHP **8.2** and **8.5**; **Laravel 13** (requires PHP **^8.3**) on PHP **8.3** and **8.5**. Workflows: [Pint](https://github.com/laravel/pint) (`.github/workflows/pint.yml`), [PHPStan](https://phpstan.org/) + [Larastan](https://github.com/larastan/larastan) (`.github/workflows/phpstan.yml`), and PHPUnit (`.github/workflows/phpunit.yml`) — which covers package tests including [Orchestra Testbench](https://packages.tools/testbench.html) via [`Orchestra\Testbench\TestCase`](tests/TestCase.php).

Locally after `composer install`:

```bash
composer run ci
# or: ./vendor/bin/pint --test && ./vendor/bin/phpstan analyse -c phpstan.neon.dist --no-progress && ./vendor/bin/phpunit -c phpunit.xml.dist
```

### Workbench (`composer run serve`)

The [Orchestra Testbench](https://packages.tools/testbench.html) workbench is a small Laravel app inside this repo. **`composer run build`** only runs asset steps so it works **without** the PHP SQLite extension (`pdo_sqlite`). Session/cache/queue defaults are set in `testbench.yaml` (`env:`) so the skeleton does not need a SQL database for a quick `composer run serve`.

**Neo4j** is configured separately via **`NEO4J_*`** (Bolt) and **`NEO4J_MCP_*`** (MCP), not via `DB_*`. Defaults are in `testbench.yaml` under `env:`; override them by copying `workbench/.env.example` to `workbench/.env` and editing.

**Optional SQL (migrations / `DatabaseSeeder`):** install `php-sqlite3` (or configure MySQL in `workbench/.env`), then run `./vendor/bin/testbench workbench:create-sqlite-db` and `./vendor/bin/testbench migrate:fresh` if you need the database.

Demo media for this README lives under [`docs/media/`](docs/media/README.md). Uncomment the GIF embeds in this file after recording the clips listed there.

---

## Installation

### 1. Install the package

```bash
composer require neo4j/laravel-boost
```

This package requires [Laravel Boost](https://github.com/laravel/boost) and [Laravel MCP](https://github.com/laravel/mcp). If they are not already in your app:

```bash
composer require laravel/boost laravel/mcp neo4j/laravel-boost
```

### 2. Run interactive setup

```bash
php artisan neo4j-boost:setup
```

By default, this package uses **STDIO transport** and manages the official `neo4j-mcp` binary for local usage. The setup command:

- Prompts to install/check the binary (`neo4j-boost:install-mcp`)
- On **first interactive run**, attempts to start local Neo4j via `neo4j-boost:start-neo4j` (Docker + APOC)
- Writes Cursor MCP config (`neo4j-boost:cursor-config`) unless `--no-cursor-config`
- Expects `NEO4J_MCP_TRANSPORT=stdio` (the default) and a set `NEO4J_PASSWORD`

Use `php artisan neo4j-boost:setup --no-interaction` to print the manual steps only (for Composer hooks).

![First-run interactive setup](docs/media/demos/01-interactive-setup.gif)

### 3. Start local Neo4j (for STDIO mode)

If first-run setup could not start Docker, or you need to recreate the container:

```bash
php artisan neo4j-boost:start-neo4j
```

This command starts a local Docker Neo4j instance on:

- `bolt://localhost:7687`
- `http://localhost:7474`

It configures **APOC** defaults required by schema tools (not GDS). Pass `--recreate` to rebuild the container with the required plugins.

### Optional: automate setup with a Composer hook

Add this to your app `composer.json` to print setup reminders after `composer update` (non-interactive mode does not download the binary or start Docker):

```json
{
  "scripts": {
    "post-update-cmd": [
      "@php artisan neo4j-boost:setup --no-interaction"
    ]
  }
}
```

### 4. Configure Neo4j connection (for the MCP server)

For STDIO mode, the `neo4j-mcp` binary needs Neo4j credentials. Set these in your Laravel app `.env`:

```env
NEO4J_MCP_TRANSPORT=stdio
NEO4J_URI=bolt://localhost:7687
NEO4J_USERNAME=neo4j
NEO4J_PASSWORD=your-password
```

**Note:** `NEO4J_MCP_TRANSPORT` selects how **this package** talks to Neo4j MCP tools (`stdio`, `http`, or `driver`). The separate env var `NEO4J_TRANSPORT_MODE` is used when configuring the **official neo4j-mcp process/container** itself (for example `-e NEO4J_TRANSPORT_MODE=http` in Docker).

### 5. (Optional) Cursor MCP config

To add/update Cursor MCP config:

```bash
php artisan neo4j-boost:cursor-config
```

With Laravel Boost installed (required by this package), this creates or updates `.cursor/mcp.json` with a **`laravel-boost`** entry that runs `php artisan boost:mcp`, merged with any existing servers. It removes a legacy `neo4j-boost` URL entry if present so you keep one MCP server.

### 6. Advanced / Custom Server (HTTP or Docker)

If you want to run Neo4j MCP as a separate server instead of local STDIO binary mode:

- Set `NEO4J_MCP_TRANSPORT=http`
- Set `NEO4J_MCP_URL=http://localhost:8080/mcp` (or your remote URL)

Run your own Neo4j MCP server (manually, Docker, or remote host), then point this package at that URL.

**Example with Docker (custom server mode):**

```bash
docker run --rm -p 8080:8080 \
  -e NEO4J_URI=bolt://host.docker.internal:7687 \
  -e NEO4J_TRANSPORT_MODE=http \
  docker.io/mcp/neo4j:latest
```

**Optional: in-process driver transport** (no `neo4j-mcp` binary):

```env
NEO4J_MCP_TRANSPORT=driver
NEO4J_URI=bolt://localhost:7687
NEO4J_USERNAME=neo4j
NEO4J_PASSWORD=your-password
# or: NEO4J_DEFAULT_CONNECTION_DSN=neo4j://neo4j:password@neo4j-core1:7687
```

### 7. (Optional) Enable GDS for `list-gds-procedures`

The **list-gds-procedures** tool requires the [Graph Data Science](https://neo4j.com/docs/graph-data-science/current/) (GDS) plugin in Neo4j. Without it, that tool will error; other tools (get-schema, read-cypher, write-cypher) still work. `neo4j-boost:start-neo4j` installs APOC only.

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

1. Install packages and run local setup:

   ```bash
   composer require laravel/boost laravel/mcp neo4j/laravel-boost
   php artisan neo4j-boost:setup
   php artisan neo4j-boost:start-neo4j
   ```

   If you prefer a remote/custom MCP server, set `NEO4J_MCP_TRANSPORT=http` and `NEO4J_MCP_URL=...`. For in-process Bolt tools, set `NEO4J_MCP_TRANSPORT=driver`.

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

3. This package adds its Neo4j tools to Boost's tool list. You get Boost tools (search-docs, browser-logs, database, etc.) **and**:
   - Official Neo4j tools proxied through this package: **get-schema**, **read-cypher**, **write-cypher**, **list-gds-procedures**
   - Package-native tool: **get-class-dependency-graph** (reads the Neo4j container graph after `container:graph`)

   Proxied Neo4j tools use `NEO4J_MCP_TRANSPORT`: **stdio** (default), **http**, or **driver** (in-process Bolt).

![Read data with Cypher](docs/media/demos/05-read-cypher.gif)

![Run a disposable write round-trip](docs/media/demos/06-write-cypher.gif)

---

## Using with Cursor

1. Open your **Laravel application folder** (the project where you ran `composer require`) as the Cursor workspace—not the neo4j-boost package directory.
2. Reload Cursor or open MCP settings so it picks up `.cursor/mcp.json`.
3. Enable **laravel-boost** (one MCP server via `php artisan boost:mcp`). Cursor talks to Boost over stdio. This package then reaches Neo4j according to `NEO4J_MCP_TRANSPORT` (STDIO binary by default, HTTP when configured, or in-process Bolt for `driver`). Tools appear when the server is connected: get-schema, read-cypher, write-cypher, list-gds-procedures, get-class-dependency-graph.

![Connect Laravel Boost in Cursor](docs/media/demos/03-cursor-mcp-tools.gif)

![Inspect the graph schema from chat](docs/media/demos/04-get-schema-in-cursor.gif)

---

## Local development (this repo)

When developing the package and running Artisan from the repo (e.g. e2e testing `boost:mcp`), either:

- **Option A:** In `.cursor/mcp.json`, add `"env": { "APP_ENV": "local" }` to the `laravel-boost` server entry (see config above). Cursor will pass it when starting the process.
- **Option B:** Copy `.env.example` to `.env` in the repo root so that `php artisan boost:mcp` sees `APP_ENV=local` when run from the terminal or by Cursor.

---

## Artisan commands

| Command | Description |
|--------|-------------|
| `php artisan neo4j-boost:setup` | Interactive STDIO-first setup (binary, first-run Neo4j start, Cursor config) |
| `php artisan neo4j-boost:install-mcp` | Download/install the official `neo4j-mcp` binary |
| `php artisan neo4j-boost:start-neo4j` | Start local Neo4j Docker for STDIO mode (APOC) |
| `php artisan neo4j-boost:cursor-config` | Create or update `.cursor/mcp.json` with the `laravel-boost` MCP entry (`php artisan boost:mcp`) |
| `php artisan neo4j-boost:doctor` | Diagnose transport, binary, password, and readiness |
| `php artisan neo4j-boost:test-stdio --tool=get-schema` | Verbose end-to-end STDIO handshake/tool test |
| `php artisan container:graph` | Export Laravel container bindings/dependencies into Neo4j (`--dry-run`, `--print-cypher`) |

Neo4j tools exposed via Laravel Boost MCP (`php artisan boost:mcp`): **get-schema**, **read-cypher**, **write-cypher**, **list-gds-procedures**, and **get-class-dependency-graph** (requires `container:graph` export first).

### Auto-install supported platforms (`neo4j-boost:install-mcp`)

The binary is downloaded from the [official Neo4j MCP GitHub releases](https://github.com/neo4j/mcp/releases) and auto-detected for the current platform. Override with `NEO4J_MCP_PLATFORM_ASSET` in `.env`.

| OS | Architecture | Archive | PHP requirement |
|---|---|---|---|
| Linux | x86\_64 / amd64 | `.tar.gz` | — |
| Linux | arm64 / aarch64 | `.tar.gz` | — |
| Linux | i386 / i686 | `.tar.gz` | — |
| macOS | x86\_64 / amd64 | `.tar.gz` | — |
| macOS | arm64 (Apple Silicon) | `.tar.gz` | — |
| Windows | x86\_64 / amd64 | `.zip` | **ext-zip** required |
| Windows | arm64 | `.zip` | **ext-zip** required |
| Windows | i386 | `.zip` | **ext-zip** required |

> [!NOTE]
> Windows platforms use ZIP archives. The `ext-zip` PHP extension must be enabled (`extension=zip` in `php.ini`). On Linux and macOS, only the built-in `PharData` class is used — no extra extensions needed.

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

![Export and query Laravel dependencies](docs/media/demos/07-container-dependency-tool.gif)

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

![Visualize the container graph](docs/media/demos/08-container-graph-browser.gif)

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

- **`neo4j_mcp.transport`** / **`transport.driver`** – How Neo4j MCP tools run (`stdio` default, `http`, or `driver` for in-process Bolt). Env: `NEO4J_MCP_TRANSPORT`.
- **`transport.stdio.command`** / **`transport.stdio.env`** – Local binary command and Neo4j env passed to the subprocess. Env: `NEO4J_URI`, `NEO4J_USERNAME`, `NEO4J_PASSWORD`.
- **`bolt.uri`** / **`bolt.username`** / **`bolt.password`** – Bolt connection when `NEO4J_MCP_TRANSPORT=driver`. Env: `NEO4J_URI`, `NEO4J_USERNAME`, `NEO4J_PASSWORD` (or `NEO4J_DEFAULT_CONNECTION_DSN`).
- **`neo4j_mcp.binary_path`** / **`neo4j_mcp.version`** – Local binary install path and version.
- **`transport.http.url`** – MCP endpoint (e.g. `http://localhost:8080/mcp`). Env: `NEO4J_MCP_URL`.
- **`transport.http.username`** / **`transport.http.password`** – Optional Basic Auth for the HTTP endpoint. Env: `NEO4J_MCP_USERNAME`, `NEO4J_MCP_PASSWORD` (fallback to `NEO4J_USERNAME` / `NEO4J_PASSWORD`).
- **`container_graph.uri`** / **`container_graph.default_connection_dsn`** – Used by `php artisan container:graph` for the direct Neo4j driver. Env: `NEO4J_URI`, `NEO4J_DEFAULT_CONNECTION_DSN` (DSN is used when `NEO4J_URI` is empty).

---

## Troubleshooting

Run the readiness check first:

```bash
php artisan neo4j-boost:doctor
```

![Run the readiness doctor](docs/media/demos/02-readiness-doctor.gif)

- **"Could not open input file: artisan"** or **"Loading tools" stuck**  
  When using Laravel Boost, Cursor must run the MCP command from your Laravel app directory. Open the **Laravel app folder** as the workspace and ensure `.cursor/mcp.json` exists.

- **"Unexpected token … is not valid JSON"** or **"ERROR … Did you mean this? neo4j-boost"** when Cursor runs `boost:mcp`  
  The MCP client expects only JSON on stdout. That error usually means `boost:mcp` failed to start and Artisan printed a message to stdout (e.g. "There are no commands defined in the 'boost' namespace"). Laravel Boost only registers its commands when **APP_ENV=local** or **APP_DEBUG=true**. Fix: in `.cursor/mcp.json`, add `"env": { "APP_ENV": "local" }` to the `laravel-boost` server entry so Cursor passes it when starting the process. Alternatively, ensure `.env` in the project root has `APP_ENV=local` (or copy `.env.example` to `.env`).

- **Neo4j MCP HTTP errors**  
  Ensure `NEO4J_MCP_TRANSPORT=http`, the Neo4j MCP server is running with HTTP transport, and `NEO4J_MCP_URL` matches. Check the MCP server logs for connection or Neo4j errors.

- **`container:graph` connects to `bolt://localhost:7687` in Docker (or "Cannot connect to any server on alias: container-graph")**  
  Set `NEO4J_URI` to your Neo4j host on the container network, or set `NEO4J_DEFAULT_CONNECTION_DSN` to a full URL (for example `neo4j://neo4j:password@neo4j-core1:7687`). In Docker, `localhost` in the DSN/URI is the app container, not the Neo4j service. Re-publish `neo4j-boost` config after upgrading and run `php artisan config:clear` if you use `config:cache`.

- **STDIO test fails with "Neo4j password is required for STDIO mode"**  
  Set `NEO4J_PASSWORD` in your `.env`, then run `php artisan config:clear`.

- **STDIO test fails with APOC/meta error**  
  Recreate local Neo4j with required plugins:
  `php artisan neo4j-boost:start-neo4j --recreate`

- **HTTP 404: "This server only handles requests to /mcp"**  
  Cursor may try several connection methods (streamable HTTP, SSE) and can send **GET** requests. The official Neo4j MCP server in HTTP mode typically only accepts **POST** on `/mcp`, so those GETs return this 404.  
  **Recommended:** Use **Laravel Boost** so Cursor talks to one MCP server over stdio (`php artisan boost:mcp`). With `NEO4J_MCP_TRANSPORT=http`, this package then calls the Neo4j MCP server over HTTP (POST only) from your app; Cursor never hits the Neo4j HTTP URL directly. With the default STDIO transport, the package runs the local `neo4j-mcp` binary instead.
  If you must connect Cursor directly to the Neo4j MCP URL: ensure the URL ends with `/mcp` and that the Neo4j MCP server is running with `NEO4J_TRANSPORT_MODE=http`. Compatibility depends on the client using POST to the configured URL. Note: with Laravel Boost present, `neo4j-boost:cursor-config` writes the `laravel-boost` stdio entry, not a direct Neo4j HTTP URL.

- **GDS errors**  
  Messages like "Unknown function 'gds.version'" mean Neo4j does not have the GDS plugin. Install it and set procedure allowlists (see **Enable GDS** above). The MCP server still runs and standard Cypher (get-schema, read-cypher, write-cypher) works without GDS.

---

## License

MIT.
