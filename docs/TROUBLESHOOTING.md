# Troubleshooting Neo4j Boost

Step-by-step fixes for common installation and configuration issues. Start with [`php artisan neo4j-boost:doctor`](#run-the-doctor-first) when something looks wrong, then jump to the section that matches your setup.

Related setup guides: [Installation](../README.md#installation), [Transport Modes](../README.md#transport-modes), [Docker Compose (HTTP mode)](../README.md#using-docker-compose-http-mode). MCP client setup lives in the README [5-minute Quick Start](../README.md#5-minute-quick-start).

---

## Table of contents

- [Run the doctor first](#run-the-doctor-first)
- [Environment configuration](#environment-configuration)
- [Docker setup](#docker-setup)
- [Neo4j Aura connectivity](#neo4j-aura-connectivity)
- [MCP configuration](#mcp-configuration)
- [Authentication issues](#authentication-issues)
- [Common error messages and resolutions](#common-error-messages-and-resolutions)
- [Still stuck?](#still-stuck)

---

## Run the doctor first

```bash
php artisan neo4j-boost:doctor
```

This reports transport mode, whether `NEO4J_PASSWORD` is set, binary readiness (STDIO), and overall connectivity. Fix anything marked missing or failed before digging deeper.

After any `.env` change:

```bash
php artisan config:clear
```

---

## Environment configuration

### Required variables by transport

| Transport | Typical `.env` values |
| --- | --- |
| `driver` (default) | `NEO4J_URI`, `NEO4J_USERNAME`, `NEO4J_PASSWORD` |
| `stdio` | Same Neo4j credentials, plus `NEO4J_MCP_TRANSPORT=stdio` and an installed `neo4j-mcp` binary |
| `http` | `NEO4J_MCP_TRANSPORT=http`, `NEO4J_MCP_URL=http://localhost:8080/mcp`, plus Neo4j credentials for this package |

Minimal driver example:

```env
NEO4J_MCP_TRANSPORT=driver
NEO4J_URI=bolt://localhost:7687
NEO4J_USERNAME=neo4j
NEO4J_PASSWORD=your-password
```

### Step-by-step: fix a misconfigured environment

1. Open your Laravel app `.env` (not the package directory).
2. Confirm `NEO4J_MCP_TRANSPORT` is one of `driver`, `stdio`, or `http` (or omit it to keep the `driver` default).
3. Set `NEO4J_URI` / `NEO4J_USERNAME` / `NEO4J_PASSWORD` to match the database you can reach from this machine.
4. For HTTP mode, set `NEO4J_MCP_URL` so it ends with `/mcp`.
5. Run `php artisan config:clear`.
6. Run `php artisan neo4j-boost:doctor` and confirm password and transport look correct.
7. Optional: publish config with `php artisan vendor:publish --tag=neo4j-boost-config` if you need to inspect `config/neo4j-boost.php`.

### Cached config traps

If values in `.env` change but tools still use old settings:

1. Run `php artisan config:clear`.
2. If you deploy with `config:cache`, rebuild the cache after updating env vars.
3. Restart the MCP client (or reload MCP servers) so long-lived processes pick up the new environment.

### Optional DSN fallback

If `NEO4J_URI` is empty, some paths (including container graph export) can use `NEO4J_DEFAULT_CONNECTION_DSN`, for example:

```env
NEO4J_DEFAULT_CONNECTION_DSN=neo4j://neo4j:password@neo4j-core1:7687
```

Prefer explicit `NEO4J_URI` + username/password for local clarity.

---

## Docker setup

### Local Neo4j via Artisan

```bash
php artisan neo4j-boost:start-neo4j
```

Expected endpoints:

- Bolt: `bolt://localhost:7687`
- Browser: `http://localhost:7474`

If APOC settings are missing on an existing container:

```bash
php artisan neo4j-boost:start-neo4j --recreate
```

### Docker Compose (Neo4j + MCP HTTP)

Use the compose example in the [README Docker section](../README.md#using-docker-compose-http-mode). Then point Laravel at it:

```env
NEO4J_MCP_TRANSPORT=http
NEO4J_MCP_URL=http://localhost:8080/mcp
NEO4J_URI=bolt://localhost:7687
NEO4J_USERNAME=neo4j
NEO4J_PASSWORD=your-password
```

### Step-by-step: Docker connection failures

1. Confirm Docker is running: `docker info`.
2. List containers: `docker ps` and verify Neo4j (and `neo4j-mcp` if using HTTP) are up.
3. From the host, open `http://localhost:7474` (Neo4j Browser) or check Bolt on port `7687`.
4. Match passwords: `NEO4J_AUTH` / compose password must equal `NEO4J_PASSWORD` in Laravel.
5. Choose the correct hostname for `NEO4J_URI`:
   - Laravel on the host talking to published ports: `bolt://localhost:7687`
   - Laravel inside the same Compose network: use the service name, e.g. `bolt://neo4j:7687`
6. Clear config: `php artisan config:clear`.
7. Re-test with `php artisan neo4j-boost:doctor`.

### Plugins (APOC / GDS)

Schema tools prefer APOC (`apoc.meta.schema`). GDS is only required for `list-gds-procedures`.

In Compose, include:

```yaml
NEO4J_PLUGINS: '["apoc", "graph-data-science"]'
NEO4J_dbms_security_procedures_unrestricted: 'apoc.*,gds.*'
NEO4J_dbms_security_procedures_allowlist: 'apoc.*,gds.*'
```

Recreate the Neo4j container after changing plugin env vars so they take effect.

---

## Neo4j Aura connectivity

Aura is a managed Neo4j database. Neo4j Boost connects to it the same way it connects to any remote Bolt endpoint (usually with `driver` transport).

### Step-by-step: connect Laravel Boost to Aura

1. Create or open an Aura instance at [Neo4j AuraDB](https://neo4j.com/product/auradb/).
2. Copy the connection URI from the Aura console (often `neo4j+s://….databases.neo4j.io`).
3. Set Laravel `.env`:

```env
NEO4J_MCP_TRANSPORT=driver
NEO4J_URI=neo4j+s://YOUR-INSTANCE.databases.neo4j.io
NEO4J_USERNAME=neo4j
NEO4J_PASSWORD=your-aura-password
NEO4J_DATABASE=neo4j
```

4. Run `php artisan config:clear`.
5. Run `php artisan neo4j-boost:doctor`.
6. From your MCP client, ask for the schema (`get-schema`) to confirm end-to-end access.

### Aura-specific checks

- Use the URI scheme Aura gives you (`neo4j+s://` or `bolt+s://`). Do not force plain `bolt://` against Aura.
- Confirm the instance is running (paused instances refuse connections).
- Confirm your network allows outbound TLS to Aura (corporate proxies can block this).
- Keep the Aura password in `NEO4J_PASSWORD`; rotate it in Aura if auth keeps failing after a reset.
- For HTTP MCP mode against a separate MCP server, that MCP server must be able to reach Aura; Laravel credentials alone are not enough if the MCP process runs elsewhere without Aura access.

### When tools say no Neo4j server was found

Health checks may suggest creating a free Aura instance when nothing is reachable locally. That message means connectivity failed, not that Aura is mandatory. Either start local Neo4j / Docker or point `NEO4J_URI` at Aura (or another reachable database), then reconnect the MCP server.

---

## MCP configuration

### Recommended: single Boost MCP server

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

Generate or merge Cursor config:

```bash
php artisan neo4j-boost:cursor-config
```

### Step-by-step: MCP client not loading Neo4j tools

1. Open the **Laravel application folder** as the workspace (where `artisan` lives), not the `neo4j-boost` package repo.
2. Confirm `.cursor/mcp.json` (or your client config) points at `php artisan boost:mcp`.
3. Ensure `"APP_ENV": "local"` is present so Laravel Boost registers commands.
4. Reload MCP servers / restart the client.
5. Run `php artisan neo4j-boost:doctor` in the same project.
6. If you intentionally use HTTP MCP directly, set `NEO4J_MCP_URL` to a URL ending in `/mcp` and keep `NEO4J_TRANSPORT_MODE=http` on the MCP server.

### STDIO transport extras

1. Install the binary: `php artisan neo4j-boost:install-mcp`.
2. Set `NEO4J_MCP_TRANSPORT=stdio`.
3. Ensure `NEO4J_PASSWORD` is non-empty.
4. Clear config and run `php artisan neo4j-boost:test-stdio` for a verbose handshake check.

---

## Authentication issues

### Neo4j database auth (Bolt / driver / STDIO)

Symptoms: auth failures, empty password errors, or doctor reporting password missing.

1. Set a real password: `NEO4J_PASSWORD=...` (not blank).
2. Match username: Aura and local defaults are usually `neo4j` via `NEO4J_USERNAME`.
3. Clear config: `php artisan config:clear`.
4. Verify you can log into Neo4j Browser or `cypher-shell` with the same credentials.
5. For Docker, ensure `NEO4J_AUTH=neo4j/your-password` matches Laravel.

### MCP HTTP auth

If the MCP HTTP endpoint itself requires basic auth:

```env
NEO4J_MCP_USERNAME=neo4j
NEO4J_MCP_PASSWORD=your-mcp-password
```

Error text often looks like: `Neo4j MCP authentication failed. Check NEO4J_MCP_USERNAME / NEO4J_MCP_PASSWORD.`

In HTTP mode this package sends Neo4j credentials with requests. Do **not** also hard-code Neo4j username/password on the MCP server container unless you intentionally dual-configure auth.

### Step-by-step: authentication checklist

1. Decide which credential failed: Neo4j Bolt vs MCP HTTP gateway.
2. Update the matching env vars only.
3. `php artisan config:clear`.
4. `php artisan neo4j-boost:doctor`.
5. Retry a read-only tool (`get-schema` or `read-cypher` with `RETURN 1 AS ok`).

---

## Common error messages and resolutions

| Error / symptom | Likely cause | Recommended fix |
| --- | --- | --- |
| `Could not open input file: artisan` | MCP cwd is not the Laravel app | Open the Laravel project folder as the workspace; keep `args: ["artisan", "boost:mcp"]` relative to that root |
| `There are no commands defined in the 'boost' namespace` | Boost disabled outside local env | Add `"env": { "APP_ENV": "local" }` to the MCP server entry |
| `Neo4j password is required for STDIO mode` | Empty `NEO4J_PASSWORD` | Set `NEO4J_PASSWORD` in `.env`, then `php artisan config:clear` |
| `Neo4j driver transport requires NEO4J_URI (and NEO4J_USERNAME / NEO4J_PASSWORD)` | Missing Bolt settings for `driver` | Set URI/user/password, or switch transport deliberately |
| `Neo4j MCP authentication failed. Check NEO4J_MCP_USERNAME / NEO4J_MCP_PASSWORD.` | Bad MCP HTTP basic auth | Fix `NEO4J_MCP_USERNAME` / `NEO4J_MCP_PASSWORD` |
| `No Neo4j server was found` / Aura suggestion | Nothing reachable at configured URI/URL | Start Docker Neo4j, run `neo4j-boost:setup`, or point `NEO4J_URI` at Aura/local Neo4j |
| `cannot connect to bolt://localhost:7687` | Wrong host/port or container down | Start Neo4j; use service hostname inside Compose; clear config cache |
| HTTP `404` `This server only handles requests to /mcp` | Client hit wrong path or used GET/SSE against MCP HTTP | Prefer Boost (`boost:mcp`); otherwise ensure URL ends with `/mcp` and server uses HTTP transport |
| APOC / `apoc.meta.schema` errors | APOC missing or restricted | Recreate with `neo4j-boost:start-neo4j --recreate` or enable APOC in Compose |
| `Unknown function 'gds.version'` / GDS list failures | Graph Data Science plugin missing | Install GDS plugins env on Neo4j; schema/Cypher tools still work without GDS |
| `get-schema requires APOC ... or catalog procedures` | Schema helpers unavailable | Install APOC or ensure `db.labels` / `db.relationshipTypes` work on your Neo4j edition |
| Neo4j MCP STDIO process failed / binary missing | `neo4j-mcp` not installed or not on `PATH` | `php artisan neo4j-boost:install-mcp` then `neo4j-boost:setup` |
| MCP tools stuck on "Loading tools" | Workspace or config mismatch | Use app root workspace, regenerate Cursor config, confirm Neo4j/MCP reachability |

### Quick resolutions for the most common messages

#### `Could not open input file: artisan`

1. Close the package checkout if that is your Cursor root.
2. Open the Laravel app where you ran `composer require neo4j/laravel-boost`.
3. Reload MCP servers.

#### `Neo4j password is required`

1. Add `NEO4J_PASSWORD=...` to `.env`.
2. Run `php artisan config:clear`.
3. For STDIO, re-run `php artisan neo4j-boost:test-stdio`.

#### Docker cannot connect on `localhost:7687`

1. `docker ps` and confirm the Bolt port mapping `7687:7687`.
2. If Laravel runs in another container, change `NEO4J_URI` to the Neo4j service DNS name.
3. `php artisan config:clear` and retry.

#### HTTP 404 on `/mcp`

1. Prefer the Boost STDIO entry (`php artisan boost:mcp`) so the IDE never talks to Neo4j MCP HTTP directly.
2. If you must use HTTP, set `NEO4J_MCP_URL=http://host:8080/mcp` (path required).
3. Ensure the MCP container/process was started with HTTP transport enabled.

---

## Still stuck?

1. Collect output from `php artisan neo4j-boost:doctor`.
2. Note transport (`driver` / `stdio` / `http`), whether Docker or Aura is in use, and the exact error text.
3. Re-check the matching section above and the [README troubleshooting summary](../README.md#common-issues--troubleshooting).
