# Neo4j Laravel Boost

This package provides a seamless Laravel integration for the [official Neo4j MCP server](https://github.com/neo4j/mcp/releases). It makes Neo4j tools (like `get-schema`, `read-cypher`, `write-cypher`, `list-gds-procedures`, `get-class-dependency-graph`, and `contribute-graph-knowledge`) easily accessible to any MCP-compatible client.

**Requirements:** PHP 8.2+, Laravel 12 or 13.

Release notes: [CHANGELOG.md](CHANGELOG.md).

---

## Table of contents

- [Why Use It?](#why-use-it)
- [Installation & Setup](#installation--setup)
  - [1. Install the Package](#1-install-the-package)
  - [2. Update Your Environment Variables](#2-update-your-environment-variables)
  - [3. Run the Interactive Setup](#3-run-the-interactive-setup)
  - [4. Start a Local Neo4j Instance (Optional)](#4-start-a-local-neo4j-instance-optional)
  - [5. Connect Your MCP Client](#5-connect-your-mcp-client)
- [Usage](#usage)
  - [MCP Client Configuration](#mcp-client-configuration)
    - [Cursor](#cursor)
    - [Claude Code](#claude-code)
  - [Exploring Your Container Dependency Graph](#exploring-your-container-dependency-graph)
  - [Available Artisan Commands](#available-artisan-commands)
- [Important Notes & Advanced Configuration](#important-notes--advanced-configuration)
  - [Transport Modes](#transport-modes)
  - [Using Docker Compose (HTTP mode)](#using-docker-compose-http-mode)
  - [Graph Data Science (GDS) Plugin](#graph-data-science-gds-plugin)
  - [Automating Setup After Updates](#automating-setup-after-updates)
  - [Publishing Configuration (Optional)](#publishing-configuration-optional)
  - [Binary Platform Support](#binary-platform-support)
  - [Common Issues & Troubleshooting](#common-issues--troubleshooting)
- [Troubleshooting guide](docs/TROUBLESHOOTING.md)
- [License](#license)

---

## Why Use It?

Out of the box, AI coding assistants can only read your local files, they can't inspect your live database schema or run Cypher queries. This package bridges that gap by connecting the official Neo4j MCP server directly into Laravel Boost. This allows your assistant to:

* Inspect your live Neo4j schema directly.
* Run read and write Cypher queries.
* Query your Laravel container's dependency graph.

The best part? You only need a single MCP server entry (`php artisan boost:mcp`) to handle both your Laravel Boost tools and Neo4j tools and it works with any MCP-compatible client.

---

## Installation & Setup

### 1. Install the Package

First, pull in the package via Composer:

```bash
composer require --dev neo4j/laravel-boost

```

### 2. Update Your Environment Variables

By default, the package uses **driver transport**, which runs Neo4j tools in PHP over Bolt via `laudis/neo4j-php-client`. No `neo4j-mcp` binary is needed. Add your Neo4j connection details to your `.env` file:

```env
NEO4J_URI=bolt://localhost:7687
NEO4J_USERNAME=neo4j
NEO4J_PASSWORD=your-password

```

> **Note:** `NEO4J_MCP_TRANSPORT` defaults to `driver`. You can switch to `stdio` (spawns the `neo4j-mcp` binary as a subprocess) or `http` (remote MCP server). See [Transport Modes](#transport-modes) below. For env and connectivity problems, see [Troubleshooting](docs/TROUBLESHOOTING.md#environment-configuration).

### 3. Run the Interactive Setup

```bash
php artisan neo4j-boost:setup

```

This command checks your connection, optionally installs the `neo4j-mcp` binary, and can spin up a local Neo4j Docker instance.

### 4. Start a Local Neo4j Instance (Optional)

If you don't already have a Neo4j database running, we've got you covered:

```bash
php artisan neo4j-boost:start-neo4j

```

This starts a local Docker Neo4j instance on `bolt://localhost:7687` and `http://localhost:7474`, complete with the default APOC plugins required by the schema tools.

If the container fails to start or Bolt is unreachable, see [Docker setup troubleshooting](docs/TROUBLESHOOTING.md#docker-setup).

### 5. Connect Your MCP Client

Add the following entry to your MCP client's configuration, then open your **Laravel application folder** as the workspace and reload the MCP settings. You'll see the Neo4j tools sitting right alongside your Boost tools.

See the [MCP Client Configuration](#mcp-client-configuration) section below for Cursor and Claude Code examples. If tools do not appear, see [MCP configuration troubleshooting](docs/TROUBLESHOOTING.md#mcp-configuration).

---

## Usage

### MCP Client Configuration

This package works with any MCP-compatible client. The MCP server entry to add is always:

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

#### Cursor

Add the entry above to `.cursor/mcp.json` in your project root, or use the helper command to generate it:

```bash
php artisan neo4j-boost:cursor-config

```

#### Claude Code

Add the entry to your `claude_code_config.json` (or run `claude mcp add` and point it at the same server definition). Make sure to open your Laravel application folder as the workspace so `artisan` is reachable.

Common MCP client issues (wrong workspace, missing `APP_ENV=local`, HTTP `/mcp` 404s) are covered in [MCP configuration troubleshooting](docs/TROUBLESHOOTING.md#mcp-configuration).

### Exploring Your Container Dependency Graph

You can actually export your Laravel container's runtime wiring into Neo4j. This allows an LLM to query how your dependencies are resolved as a graph:

```bash
php artisan container:graph
php artisan container:graph --dry-run
php artisan container:graph --print-cypher

```

> **A heads-up for large codebases:** This export maps out all PSR-4 classes in the container. If your app has hundreds of services, it will take a little while and generate a large graph. This is completely normal!

If you want to detect hidden dependencies (like service-location calls, facade usage, or direct `new` instantiations), you can enable static scanning by pointing it to your app directories:

```env
NEO4J_CONTAINER_GRAPH_STATIC_SCAN_PATHS=/absolute/path/to/app/Services,/absolute/path/to/app/Http

```

Alternatively, you can publish the config file and set `container_graph.static_scan_paths` there. If left unset, the package won't scan your PHP files and will only export runtime reflection edges.

Once exported, you can use the **get-class-dependency-graph** MCP tool to query specific classes like this:

```json
{ "class": "App\\Services\\FooService", "direction": "outbound", "depth": 4 }

```

### Available Artisan Commands

| Command | What it does |
| --- | --- |
| `neo4j-boost:setup` | Runs the interactive setup (checks connection, optionally configures Docker Neo4j, writes Cursor config). |
| `neo4j-boost:start-neo4j` | Boots up a local Neo4j Docker instance. |
| `neo4j-boost:cursor-config` | Creates or updates your `.cursor/mcp.json` file. |
| `neo4j-boost:install-mcp` | Downloads and installs the official `neo4j-mcp` binary (only needed for STDIO). |
| `neo4j-boost:doctor` | Diagnoses your transport, binary, password, and overall readiness. |
| `neo4j-boost:test-stdio` | Runs a verbose end-to-end test for the STDIO handshake and tools. |
| `container:graph` | Exports your Laravel container bindings directly into Neo4j (`--dry-run` and `--print-cypher` available). |

---

## Important Notes & Advanced Configuration

### Transport Modes

The default is `driver`, which runs Neo4j tools in PHP over Bolt via `laudis/neo4j-php-client`. No binary needed. You have two other options:

* **STDIO:** Spawns the official `neo4j-mcp` binary as a subprocess. Install it with `php artisan neo4j-boost:install-mcp`, then set `NEO4J_MCP_TRANSPORT=stdio` in your `.env`.
* **HTTP:** Connects to a remote or containerized MCP server. Set `NEO4J_MCP_TRANSPORT=http` and `NEO4J_MCP_URL=http://localhost:8080/mcp`. In HTTP mode, this package sends your Neo4j credentials with every request, so do not set `NEO4J_USERNAME` or `NEO4J_PASSWORD` on the MCP server container itself.

Remember to run `php artisan config:clear` after editing your `.env` file so Laravel picks up the change. Auth and env mistakes are documented in [Authentication issues](docs/TROUBLESHOOTING.md#authentication-issues) and [Environment configuration](docs/TROUBLESHOOTING.md#environment-configuration).

Connecting to Neo4j Aura? Use the Aura URI from the console (`neo4j+s://...`) with driver transport. See [Neo4j Aura connectivity](docs/TROUBLESHOOTING.md#neo4j-aura-connectivity).

### Using Docker Compose (HTTP mode)

Here is a quick setup guide if you prefer Docker:

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

And update your Laravel `.env` to match:

```env
NEO4J_MCP_TRANSPORT=http
NEO4J_MCP_URL=http://localhost:8080/mcp
NEO4J_URI=bolt://localhost:7687
NEO4J_USERNAME=neo4j
NEO4J_PASSWORD=your-password

```

Docker networking, plugin, and port issues: [Docker setup troubleshooting](docs/TROUBLESHOOTING.md#docker-setup).

### Graph Data Science (GDS) Plugin

The `list-gds-procedures` tool specifically requires the [Graph Data Science](https://neo4j.com/docs/graph-data-science/current/) plugin. If you don't have it installed, that specific tool will throw an error, but don't worry: `get-schema`, `read-cypher`, and `write-cypher` will still work perfectly. For Docker setups, just add `NEO4J_PLUGINS: '["apoc", "graph-data-science"]'` and the necessary `NEO4J_dbms_security_procedures_*` variables to your Neo4j service.

### Automating Setup After Updates

To keep everything running smoothly when you update your dependencies, you can add this script to your `composer.json`:

```json
{
  "scripts": {
    "post-update-cmd": [
      "@php artisan neo4j-boost:setup --no-interaction"
    ]
  }
}

```

### Publishing Configuration (Optional)

If you want to tweak the underlying config directly, publish it using:

```bash
php artisan vendor:publish --tag=neo4j-boost-config

```

This lets you configure options in `config/neo4j-boost.php` like `neo4j_mcp.transport` (`driver`, `stdio`, or `http`), `bolt.uri`, `bolt.username`, `http.url`, and more.

### Binary Platform Support

If you're using `neo4j-boost:install-mcp`, the binary supports Linux (x86_64, arm64, i386), macOS (x86_64, Apple Silicon), and Windows (x86_64, arm64, i386). Windows uses `.zip` archives (requiring `ext-zip`), while Linux and macOS use `.tar.gz`. If auto-detection fails, you can manually override it by setting `NEO4J_MCP_PLATFORM_ASSET` in your `.env`.

### Common Issues & Troubleshooting

Full step-by-step coverage (Docker, Aura, MCP, auth, environment, and error messages) lives in **[docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md)**. Start with `php artisan neo4j-boost:doctor` after any `.env` change (`php artisan config:clear`).

Quick hits:

* **"Could not open input file: artisan"**: Open your Laravel application folder as the workspace, not the package directory. See [MCP configuration](docs/TROUBLESHOOTING.md#mcp-configuration).
* **"There are no commands defined in the 'boost' namespace"**: Include `"env": { "APP_ENV": "local" }` in your MCP server entry.
* **STDIO fails with "Neo4j password is required"**: Set `NEO4J_PASSWORD` in `.env`, then run `php artisan config:clear`. See [Authentication issues](docs/TROUBLESHOOTING.md#authentication-issues).
* **APOC/meta errors**: Recreate local Neo4j with `php artisan neo4j-boost:start-neo4j --recreate`. See [Docker setup](docs/TROUBLESHOOTING.md#docker-setup).
* **Docker: cannot connect to `bolt://localhost:7687`**: Use the Neo4j service hostname on the container network when Laravel is not on the host, then clear config cache.
* **HTTP 404 "This server only handles requests to /mcp"**: Prefer Laravel Boost (`php artisan boost:mcp`). If connecting directly, ensure the URL ends with `/mcp` and HTTP transport is enabled.
* **"Unknown function 'gds.version'"**: Install the GDS plugin. See [GDS Plugin](#graph-data-science-gds-plugin). Schema and Cypher tools still work without it.
* **Aura connection failures**: Use the Aura console URI (`neo4j+s://...`) with driver transport. See [Neo4j Aura connectivity](docs/TROUBLESHOOTING.md#neo4j-aura-connectivity).

---

## License

MIT.

---
