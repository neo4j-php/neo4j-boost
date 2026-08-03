# Debug Laravel DI with the Container Graph

## Introduction

Laravel’s service container records how abstractions bind to implementations and how classes receive constructor dependencies. That wiring is usually scattered across service providers and type-hints—hard to see as a whole when debugging “what injects into X?” or “what does this binding resolve to?”

Neo4j Boost can **export** that container wiring into Neo4j as a graph, then let you explore it in Neo4j Browser or via the MCP tool `get-class-dependency-graph`.

This is an **advanced** workflow. Complete [Getting Started](getting-started.md) (and ideally [Using Neo4j MCP Tools in Cursor](cursor-mcp-tools.md)) first so Neo4j is reachable and Cursor MCP works if you want the AI path.

## Prerequisites

You need:

- A working Neo4j Boost install — see [Getting Started](getting-started.md)
- A reachable Neo4j instance for the **container graph Bolt connection** (separate from MCP transport selection)
- Credentials configured for that connection (see below)

Optional but useful:

- Neo4j Browser (`http://localhost:7474` when using `neo4j-boost:start-neo4j`)
- Cursor with `laravel-boost` connected, if you will call `get-class-dependency-graph` from the agent

### Connection env for `container:graph`

The export writes over Bolt using the package’s Neo4j client (not the MCP `stdio`/`http` hop). Typical local `.env`:

```env
NEO4J_URI=bolt://localhost:7687
NEO4J_USER=neo4j
NEO4J_PASSWORD=your-password
```

`NEO4J_USERNAME` is accepted as a fallback for `NEO4J_USER`. If `NEO4J_URI` is empty, `NEO4J_DEFAULT_CONNECTION_DSN` may be used (full URL, optionally with embedded user/password). Inside Docker, prefer the Neo4j service hostname—not `localhost` from another container.

Details: [README – Container Graph POC](../../README.md#container-graph-poc-llm-debugging).

## What the Container Graph Represents

`php artisan container:graph` snapshots:

1. **Container bindings** from `app()->getBindings()` (abstract → concrete, plus `shared`)
2. **Constructor dependencies** for concrete classes (typed constructor parameters)
3. **Project classes** discovered from production PSR-4 autoload paths in `composer.json` (not `autoload-dev`)

### Node labels

| Labels | Meaning |
|--------|---------|
| `:Abstract` | Common entry label for binding keys and related nodes — start Browser queries with `MATCH (a:Abstract) …` |
| `:Interface:Abstract` | Interface (or interface-like) binding key / dependency |
| `:Class:Abstract` | Class node (binding key, concrete target, or discovered app class) |
| `:AbstractType:Abstract` | Non-interface/non-class binding endpoints (for example closure/object descriptors); may include a `kind` property |
| `:UnresolvedDependency:Abstract` | Placeholder for unresolved dependency names (`name`, `reason`) when such rows are written |

### Relationships

| Type | Meaning | Properties |
|------|---------|------------|
| `BINDS_TO` | Container binding: abstract → concrete | `shared` (bool) |
| `DEPENDS_ON` | Constructor dependency: class → dependency (class, interface, or unresolved node) | (none on the edge in the writer) |

Patterns from the implementation / README:

- `(:Interface:Abstract)-[:BINDS_TO {shared}]->(:Class:Abstract)` when the binding key is an interface
- `(:Class:Abstract)-[:BINDS_TO {shared}]->(:Class:Abstract)` when the binding key is a class
- `(:Class:Abstract)-[:DEPENDS_ON]->(:Class:Abstract|:Interface:Abstract|:UnresolvedDependency:Abstract)`

## Export the Laravel Container Graph

```bash
php artisan container:graph
```

### What it does

1. Extracts binding rows and concrete class names from the Laravel container.
2. Scans production PSR-4 paths for additional project classes.
3. Reflects constructors to build `DEPENDS_ON` edges (and unresolved rows when applicable).
4. Prints a summary (binding count, classes inspected, dependency edges, unresolved count).
5. Unless `--dry-run`, connects to Neo4j and runs `MERGE`-based Cypher writes.

On success you see:

```text
Container graph written to Neo4j successfully.
```

### Options

| Option | Behavior |
|--------|----------|
| `--dry-run` | Extract and summarize only; **no** Neo4j write |
| `--print-cypher` | Print the writer’s Cypher templates and a small sample of params before writing (or with dry-run) |

```bash
php artisan container:graph --dry-run
php artisan container:graph --print-cypher
```

### Idempotency (not a full wipe)

Writes use **`MERGE`**. Re-running the command does not duplicate the same nodes/relationships for the same names. The command does **not** delete obsolete nodes from an earlier export if bindings were removed in PHP—treat re-runs as upserts of the current snapshot, not a guaranteed clean replace of the whole graph.

## Explore the Graph with Neo4j Browser

![Exploring the exported Laravel container graph in Neo4j Browser](../media/demos/08-container-graph-browser.gif)

Open Neo4j Browser (for local Docker Neo4j from setup: `http://localhost:7474`), sign in with your Neo4j user/password, then run queries from the [README examples](../../README.md#example-cypher-queries).

**Expand outward from binding keys:**

```cypher
MATCH p = (a:Abstract)-[:BINDS_TO|DEPENDS_ON*1..10]->(n)
RETURN p
LIMIT 200;
```

**Undirected neighborhood (no reverse edges in the model):**

```cypher
MATCH p = (a:Abstract)-[:BINDS_TO|DEPENDS_ON*1..6]-(n)
RETURN p
LIMIT 200;
```

**List interface → class bindings:**

```cypher
MATCH (i:Interface:Abstract)-[:BINDS_TO]->(c:Class:Abstract)
RETURN i.name, c.name
LIMIT 25;
```

**Walk dependencies of one class** (replace the name with a class that exists in *your* export):

```cypher
MATCH p = (:Class:Abstract {name: 'App\\Services\\FooService'})-[:DEPENDS_ON*1..4]->(d)
RETURN p
LIMIT 10;
```

**Unresolved constructor dependencies** (if any were exported):

```cypher
MATCH (c:Class:Abstract)-[:DEPENDS_ON]->(u:UnresolvedDependency:Abstract)
RETURN c.name, u.name, u.reason
LIMIT 25;
```

Tips:

- Prefer `:Abstract` as the starting label when browsing bindings.
- Switch Browser to graph view to see `BINDS_TO` / `DEPENDS_ON` paths visually.
- You can also run the same Cypher via MCP `read-cypher` (see [cursor-mcp-tools.md](cursor-mcp-tools.md)); for “what does class X depend on?” prefer `get-class-dependency-graph`.

## Query the Dependency Graph with MCP

![Exporting container:graph and querying get-class-dependency-graph from Cursor](../media/demos/07-container-dependency-tool.gif)

Tool name: **`get-class-dependency-graph`**

It is registered on `php artisan boost:mcp` with Neo4j Boost’s other tools. Unlike `get-schema` / `read-cypher` / `write-cypher`, it reads Neo4j **directly** through the container-graph Bolt connection (`ClassDependencyGraphReader` → `ContainerGraphConnection`). It does **not** go through the official `neo4j-mcp` binary.

### Parameters

| Parameter | Required | Default | Description |
|-----------|----------|---------|-------------|
| `class` | Yes | — | Fully-qualified PHP class name (for example `App\Services\FooService`) |
| `depth` | No | `4` | Max `DEPENDS_ON` hops (1–10) |
| `direction` | No | `outbound` | `outbound` (dependencies), `inbound` (dependents), or `both` |
| `include_bindings` | No | `true` | Include `BINDS_TO` when the class is a binding key or resolved target |
| `page` | No | `1` | Page for paginated dependency/dependent lists |
| `per_page` | No | `100` | Page size (max 500) |

### Return shape (high level)

When the class exists in the exported graph:

- `found: true`, `graph_export_required: false`
- optional `binding` (`abstract`, `concrete`, `shared`)
- `dependencies` and/or `dependents` (depending on `direction`)
- `dependencies_pagination` / `dependents_pagination`

When missing:

- `found: false`, `graph_export_required: true`
- message telling you to run `php artisan container:graph`

### Example Cursor prompt

After exporting:

> Use `get-class-dependency-graph` for `App\Services\FooService` with `direction` `both` and `depth` `4`. Summarize what it binds to (if anything) and what it depends on.

Equivalent arguments:

```json
{
  "class": "App\\Services\\FooService",
  "direction": "both",
  "depth": 4,
  "include_bindings": true,
  "page": 1,
  "per_page": 100
}
```

Replace `App\Services\FooService` with a real FQCN from your application (or from your export summary).

## Practical Debugging Example

Suppose your app binds an interface to a concrete class, and a service depends on that interface:

```text
App\Contracts\PaymentGateway          (:Interface:Abstract)
        │ BINDS_TO {shared: true/false}
        ▼
App\Services\StripePaymentGateway     (:Class:Abstract)

App\Services\CheckoutService          (:Class:Abstract)
        │ DEPENDS_ON
        ▼
App\Contracts\PaymentGateway          (:Interface:Abstract)
```

How to investigate with this package:

1. Run `php artisan container:graph` so those nodes/edges exist in Neo4j.
2. In Browser, confirm the binding:

   ```cypher
   MATCH (i:Interface:Abstract)-[:BINDS_TO]->(c:Class:Abstract)
   WHERE i.name CONTAINS 'Payment'
   RETURN i.name, c.name, c
   LIMIT 25;
   ```

3. Ask Cursor (or call the tool) for the service:

   > Use `get-class-dependency-graph` for `App\Services\CheckoutService`, `direction` `outbound`, `include_bindings` true.

4. Read the structured `dependencies` (constructor chain) and any `binding` info. Follow `BINDS_TO` from the interface to see which implementation the container uses.

The graph makes the indirection visible: **service → interface dependency → bound implementation**, instead of hunting only through providers and type-hints.

(Use your real class names; the `PaymentGateway` / `CheckoutService` names above are illustrative of the **label and relationship pattern**, not fixtures shipped in the package.)

## Cursor / AI-Assisted Workflow

Supported path today:

```text
Cursor
  → laravel-boost (`php artisan boost:mcp`)
    → get-class-dependency-graph
      → ClassDependencyGraphReader (Bolt / container_graph connection)
        → Neo4j container graph data
```

Typical loop:

1. Change bindings or constructors in Laravel.
2. Re-run `php artisan container:graph`.
3. Ask Cursor to call `get-class-dependency-graph` for the FQCN you care about.
4. Optionally open Neo4j Browser for a visual neighborhood around `:Abstract` nodes.

Prerequisite: export must have run successfully for that class; otherwise the tool returns `graph_export_required: true`.

## Troubleshooting

| Symptom | What to do |
|---------|------------|
| `Failed to write container graph: …` / cannot connect | Check `NEO4J_URI` (or `NEO4J_DEFAULT_CONNECTION_DSN`), user, and password. In Docker, avoid `localhost` for the Neo4j host. See [README – Troubleshooting](../../README.md#troubleshooting) |
| `get-class-dependency-graph` → `graph_export_required` / not found | Run `php artisan container:graph` in the same app; confirm the FQCN matches an exported `:Abstract {name}` |
| Empty or thin graph | Confirm the app has container bindings and PSR-4 production classes; try `--dry-run` to inspect counts before writing |
| Stale edges after refactor | Re-run `container:graph` (MERGE upserts). Old removed types may still remain until you clean the DB manually—there is no built-in “delete entire previous export” flag |
| Wrong database / credentials after `.env` change | `php artisan config:clear` if you use config caching; re-publish config if you maintain a published `neo4j-boost.php` |

More reference: [README – Container Graph POC](../../README.md#container-graph-poc-llm-debugging).

## What's Next?

Tutorial series:

1. [What is Neo4j Boost?](what-is-neo4j-boost.md) — overview
2. [Getting Started](getting-started.md) — install, setup, doctor
3. [Using Neo4j MCP Tools in Cursor](cursor-mcp-tools.md) — schema and Cypher tools
4. **Debug Laravel DI with the Container Graph** (this page)

Reference: [README – Container Graph POC](../../README.md#container-graph-poc-llm-debugging), [README – Using with Cursor](../../README.md#using-with-cursor)
