# Neo4j Laravel Boost

Laravel integration for the [official Neo4j MCP server](https://github.com/neo4j/mcp/releases). Use Neo4j tools (get-schema, read-cypher, write-cypher, etc.) from MCP clients like Cursor or Claude.

**Requirements:** PHP 8.2+, Laravel 12 or 13, [Laravel Boost](https://github.com/laravel/boost).

---

## Installation

### 1. Install the package

```bash
composer require neo4j/laravel-boost
```

### 2. Run the Neo4j MCP server (HTTP)

The package talks to the Neo4j MCP server over **HTTP only**. Run the official [Neo4j MCP server](https://github.com/neo4j/mcp/releases) elsewhere (e.g. Docker) with HTTP transport, then point this package at its URL.

**Example with Docker:** run neo4j-mcp with `--neo4j-transport-mode http` and expose the HTTP port (e.g. 8080). Set in your Laravel app `.env`:

```env
NEO4J_MCP_URL=http://localhost:8080/mcp
# Optional Basic Auth if your MCP server requires it:
NEO4J_MCP_USERNAME=neo4j
NEO4J_MCP_PASSWORD=your-password
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

---

## Configuration

Publish the config file (optional):

```bash
php artisan vendor:publish --tag=neo4j-boost-config
```

Edit `config/neo4j-boost.php`:

- **`http.url`** – MCP endpoint (e.g. `http://localhost:8080/mcp`). Env: `NEO4J_MCP_URL`.
- **`http.username`** / **`http.password`** – Optional Basic Auth for the HTTP endpoint. Env: `NEO4J_MCP_USERNAME`, `NEO4J_MCP_PASSWORD` (fallback to `NEO4J_USERNAME` / `NEO4J_PASSWORD`).

---

## Troubleshooting

- **"Could not open input file: artisan"** or **"Loading tools" stuck**  
  When using Laravel Boost, Cursor must run the MCP command from your Laravel app directory. Open the **Laravel app folder** as the workspace and ensure `.cursor/mcp.json` exists.

- **"Unexpected token … is not valid JSON"** or **"ERROR … Did you mean this? neo4j-boost"** when Cursor runs `boost:mcp`  
  The MCP client expects only JSON on stdout. That error usually means `boost:mcp` failed to start and Artisan printed a message to stdout (e.g. "There are no commands defined in the 'boost' namespace"). Laravel Boost only registers its commands when **APP_ENV=local** or **APP_DEBUG=true**. Fix: in `.cursor/mcp.json`, add `"env": { "APP_ENV": "local" }` to the `laravel-boost` server entry so Cursor passes it when starting the process. Alternatively, ensure `.env` in the project root has `APP_ENV=local` (or copy `.env.example` to `.env`).

- **Neo4j MCP HTTP errors**  
  Ensure the Neo4j MCP server is running with HTTP transport and that `NEO4J_MCP_URL` matches. Check the MCP server logs for connection or Neo4j errors.

- **HTTP 404: "This server only handles requests to /mcp"**  
  Cursor may try several connection methods (streamable HTTP, SSE) and can send **GET** requests. The official Neo4j MCP server in HTTP mode typically only accepts **POST** on `/mcp`, so those GETs return this 404.  
  **Recommended:** Use **Laravel Boost** so Cursor talks to one MCP server over stdio (`php artisan boost:mcp`). This package then calls the Neo4j MCP server over HTTP (POST only) from your app; Cursor never hits the Neo4j HTTP URL directly.  
  If you must connect Cursor directly to the Neo4j MCP URL: ensure the URL in `.cursor/mcp.json` ends with `/mcp` (run `php artisan neo4j-boost:cursor-config` to normalize it) and that the Neo4j MCP server is running with `NEO4J_TRANSPORT_MODE=http`. Compatibility depends on the client using POST to the configured URL.

- **GDS errors**  
  Messages like "Unknown function 'gds.version'" mean Neo4j does not have the GDS plugin. Install it and set procedure allowlists (see **Enable GDS** above). The MCP server still runs and standard Cypher (get-schema, read-cypher, write-cypher) works without GDS.

---

## License

MIT.
