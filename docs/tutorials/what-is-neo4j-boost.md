# What is Neo4j Boost?

## Introduction

**Neo4j Boost** (`neo4j/laravel-boost`) is a Laravel package that adds Neo4j tools to [Laravel Boost](https://github.com/laravel/boost)’s MCP server. AI coding assistants—such as Cursor—can then inspect a Neo4j schema, run Cypher, and explore Laravel dependency wiring while you work in the IDE.

By default it talks to Neo4j over **driver** transport (in-process Bolt via `laudis/neo4j-php-client`, without the MCP binary). You can instead use **stdio** (local [official Neo4j MCP server](https://github.com/neo4j/mcp/releases) binary) or **http** (a remote Neo4j MCP server).

[MCP](#mcp) (Model Context Protocol) is a standard way for AI clients to discover and invoke tools. Neo4j Boost registers its tools on the same MCP session as Laravel Boost (`php artisan boost:mcp`).

**It is intended for:**

- Laravel / PHP developers using Neo4j in their applications
- Teams already using Laravel Boost for AI-assisted development
- Anyone who wants Neo4j schema and Cypher available as MCP tools inside Cursor (or similar MCP clients)

**Requirements:** PHP 8.2+, Laravel 12 or 13, and Laravel Boost.

## Why Neo4j Boost?

Working with Neo4j from a Laravel app often means context-switching: Neo4j Browser for schema and Cypher, your IDE for PHP, and separate docs for Graph Data Science (GDS). Neo4j Boost reduces that friction for AI-assisted workflows.

**Problems it addresses:**

- AI assistants cannot see your Neo4j schema or run Cypher unless you paste results manually
- Setting up the official Neo4j MCP binary, credentials, and Cursor config is easy to get wrong
- Laravel container bindings and constructor dependencies are hard to explore as a graph without custom tooling

**Benefits:**

- Neo4j tools are registered on Laravel Boost’s MCP server (`php artisan boost:mcp`), so you get Boost tools and Neo4j tools from **one** MCP entry
- Interactive setup (`neo4j-boost:setup`), local Neo4j via Docker (`neo4j-boost:start-neo4j`), and diagnostics (`neo4j-boost:doctor`)
- Optional export of Laravel container wiring into Neo4j (`container:graph`) for dependency debugging
- Three transports: **driver** (default, in-process Bolt), **stdio** (local `neo4j-mcp` binary), or **http** (remote MCP server)

## Neo4j + Laravel

Neo4j Boost is a Laravel package. After you install it, the service provider registers Artisan commands and merges Neo4j tools into Laravel Boost’s MCP tool list (`boost.mcp.tools.include`).

Typical flow in a Laravel app:

```bash
composer require neo4j/laravel-boost
php artisan neo4j-boost:setup
php artisan neo4j-boost:start-neo4j   # local Neo4j for STDIO mode (Docker required)
```

Configure Neo4j credentials in `.env` (driver default):

```env
NEO4J_URI=bolt://localhost:7687
NEO4J_USERNAME=neo4j
NEO4J_PASSWORD=your-password
```

`NEO4J_MCP_TRANSPORT` selects how this package runs Neo4j MCP tools (`driver`, `stdio`, or `http`). Defaults to `driver`.

**What this package provides in Laravel:**

| Capability | How |
|------------|-----|
| MCP Neo4j tools | Merged into Laravel Boost’s tool list for `boost:mcp` |
| Cursor MCP config | `php artisan neo4j-boost:cursor-config` writes/updates `.cursor/mcp.json` with a `laravel-boost` entry that runs `php artisan boost:mcp` |
| Local Neo4j | `php artisan neo4j-boost:start-neo4j` (Docker; default Bolt `7687`, HTTP `7474`; enables APOC) |
| Container DI graph | `php artisan container:graph` exports bindings/dependencies into Neo4j |
| Config | Optional publish: `php artisan vendor:publish --tag=neo4j-boost-config` |

It does **not** replace a Neo4j OGM or application Neo4j driver by itself. App code still uses your chosen Neo4j client; Neo4j Boost focuses on MCP tooling, setup helpers, and the container-graph workflow.

For full install steps and transport options, see the [README](../../README.md).

## MCP

**Model Context Protocol (MCP)** is a protocol that lets AI clients list tools, call them with arguments, and receive structured results. Because this package requires Laravel Boost and `laravel/mcp`, Cursor is expected to use **one** stdio server:

```json
"mcpServers": {
  "laravel-boost": {
    "command": "php",
    "args": ["artisan", "boost:mcp"]
  }
}
```

That is what `neo4j-boost:cursor-config` writes when Laravel Boost is present. If `boost:mcp` does not register (for example in this package’s own workbench), add `"env": { "APP_ENV": "local" }` to the server entry, or set `APP_ENV=local` in `.env`—see the [README](../../README.md#cursor).

Neo4j Boost registers these tools on that server:

| Tool | Purpose |
|------|---------|
| `get-schema` | Graph schema (labels, relationship types, property keys; richer output when APOC is available) |
| `read-cypher` | Read-only Cypher (`query`, optional `params`) |
| `write-cypher` | Write Cypher such as `CREATE`, `SET`, `DELETE` (`query`, optional `params`) |
| `list-gds-procedures` | Lists GDS procedures (requires the GDS plugin on Neo4j) |
| `get-class-dependency-graph` | Structured Laravel DI graph for a fully-qualified class name (requires a prior `container:graph` export) |

`get-class-dependency-graph` reads Neo4j directly via the package’s container-graph Bolt connection. The other tools use the configured transport: **stdio** / **http** call the official Neo4j MCP server; **driver** runs the same tool names in-process over Bolt.

## AI-Assisted Development

With Neo4j Boost and Cursor configured against your Laravel app workspace:

1. Open the **Laravel application** folder (not only the package repo) as the Cursor workspace.
2. Ensure `.cursor/mcp.json` has the `laravel-boost` server running `php artisan boost:mcp` (run `neo4j-boost:cursor-config` or `neo4j-boost:setup` if needed).
3. Ask the assistant about your graph or Laravel DI; it can call the Neo4j tools instead of guessing.

**Example: understand a Neo4j-backed domain model**

You might ask Cursor:

> What labels and relationship types exist in our Neo4j database? Then run a small read-only sample query.

A typical tool sequence:

1. Call `get-schema` to learn labels and relationship types.
2. Call `read-cypher` with a query shaped by that schema, for example:

```cypher
MATCH (n)
RETURN labels(n) AS labels, n
LIMIT 10
```

**Example: debug Laravel dependency injection**

```bash
php artisan container:graph
```

Then ask:

> What does `App\Services\FooService` depend on, and what binds to its interfaces?

The assistant can call `get-class-dependency-graph` with:

```json
{
  "class": "App\\Services\\FooService",
  "direction": "outbound",
  "depth": 4
}
```

You still review generated Cypher and code changes; the tools give the assistant live graph and DI context.

## When Should You Use Neo4j Boost?

Use Neo4j Boost when these workflows match your day-to-day work:

1. **Schema discovery in the IDE** — You want Cursor to call `get-schema` before writing Cypher or mapping nodes to PHP.
2. **Safe ad-hoc reads** — You need the assistant to run `read-cypher` against a local or shared Neo4j instance while implementing features.
3. **Controlled writes from an agent** — You deliberately allow `write-cypher` for scaffolding or fixing test data (prefer non-production databases).
4. **Laravel DI debugging** — You export `container:graph` and use `get-class-dependency-graph` to answer “what injects X?” / “what does X depend on?”
5. **Single MCP server with Laravel Boost** — You already use Boost’s tools (`search-docs`, `browser-logs`, `database`, etc.) and want Neo4j tools on the same `boost:mcp` process.
6. **Local Neo4j + MCP setup** — You want `neo4j-boost:setup`, `neo4j-boost:start-neo4j`, and `neo4j-boost:doctor` instead of wiring the official binary and Cursor config by hand.

Skip Neo4j Boost if you only need a Neo4j PHP client with no AI/MCP tooling—use a driver package alone in that case.

## What's Next?

Follow the tutorials in order:

| Step | Tutorial |
|------|----------|
| 1 | [Getting Started](getting-started.md) — install, interactive setup, readiness doctor |
| 2 | [Using Neo4j MCP Tools in Cursor](cursor-mcp-tools.md) — connect `laravel-boost`, schema, read/write Cypher |
| 3 | [Debug Laravel DI with the Container Graph](container-graph.md) — export and explore DI wiring |

Reference (README):

| Topic | Where to go |
|-------|-------------|
| Installation & transports | [README – Installation](../../README.md#installation) / [Transport modes](../../README.md#transport-modes) |
| MCP client setup | [README – 5-minute Quick Start](../../README.md#5-minute-quick-start) / [Cursor](../../README.md#cursor) |
| Artisan commands & container graph | [README – Artisan commands](../../README.md#available-artisan-commands) / [Container graph](../../README.md#exploring-your-container-dependency-graph) |
| Troubleshooting | [README – Common issues](../../README.md#common-issues--troubleshooting) |

Release history: [CHANGELOG.md](../../CHANGELOG.md).
