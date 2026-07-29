# Copy-paste prompts for Neo4j Boost

Ready-to-use prompts for common Neo4j Boost workflows. Paste them into your MCP-compatible client (Cursor, Claude Code, and others) after [installation](../README.md#installation--setup).

Replace placeholders like `App\Services\FooService` or label names with values from your project.

**Tools these prompts typically exercise:** `get-schema`, `read-cypher`, `write-cypher`, `list-gds-procedures`, `get-class-dependency-graph`, `contribute-graph-knowledge`.

---

## Table of contents

- [Schema exploration](#schema-exploration)
- [Querying data](#querying-data)
- [Writing and updating data](#writing-and-updating-data)
- [Laravel container dependency graph](#laravel-container-dependency-graph)
- [Graph Data Science (GDS)](#graph-data-science-gds)
- [Debugging and diagnostics](#debugging-and-diagnostics)

---

## Schema exploration

### Inspect the live database schema

```text
Use get-schema to inspect my Neo4j database. Summarize the labels, relationship types, and important properties. Call out anything that looks incomplete or surprising.
```

**Expected outcome:** A structured schema summary (labels, relationship types, property keys). If the database is empty, you may see a message that no schema information was returned.

### Explain a specific part of the model

```text
Use get-schema, then explain how nodes labeled :User relate to the rest of the graph. List the relationship types that touch :User and the typical direction of each.
```

**Expected outcome:** A focused model explanation grounded in the live schema (not guessed from code alone).

---

## Querying data

### Count nodes by label

```text
Use read-cypher to count nodes for each label in the database. Return a simple table of label and count, ordered by count descending.
```

**Expected outcome:** Read-only Cypher results, for example:

| label | count |
| --- | --- |
| User | 120 |
| Order | 45 |

### Find a node and its neighborhood

```text
Use read-cypher to find the :User node with email "alice@example.com", then return that node plus its direct neighbors (1 hop) with relationship types. If no match exists, say so clearly.
```

**Expected outcome:** A small neighborhood graph or a clear "not found" result. Uses `read-cypher` only (no writes).

### Draft and run an exploratory query

```text
Based on get-schema, propose a Cypher query that finds the 10 most-connected :User nodes by relationship degree. Run it with read-cypher and summarize what the result implies about the data.
```

**Expected outcome:** A proposed query, executed read results, and a short interpretation.

---

## Writing and updating data

> Prefer `write-cypher` only when you intend to change data. Ask the assistant to show the Cypher first if you want a review step.

### Create a sample subgraph safely

```text
Using write-cypher, create a small sample subgraph for local testing:
- one :User {email: "demo@example.com", name: "Demo User"}
- one :Team {name: "Engineering"}
- a MEMBER_OF relationship from the user to the team
Use MERGE so re-running the prompt is idempotent. Then use read-cypher to verify the nodes and relationship exist.
```

**Expected outcome:** Write confirmation, then a read-back showing the merged nodes and `MEMBER_OF` edge.

### Fix a property based on a query

```text
Use read-cypher to find :Order nodes where status is null. Summarize how many there are. If there are fewer than 20, use write-cypher to set status = "pending" on those nodes, then re-query to confirm.
```

**Expected outcome:** A count first; conditional updates only when the guard condition is met; verification afterward.

---

## Laravel container dependency graph

> Prerequisite: run `php artisan container:graph` in your Laravel app so the container graph exists in Neo4j. For hidden dependency detection, also set `NEO4J_CONTAINER_GRAPH_STATIC_SCAN_PATHS`.

### What does this class depend on?

```text
Use get-class-dependency-graph for App\Services\FooService with direction "outbound" and depth 4. Summarize declared vs hidden dependencies, and flag low-confidence or incomplete coverage from graph_completeness.
```

**Expected outcome:** Structured dependency lists such as `declared_dependencies`, `hidden_dependencies`, per-edge `type` / `source` / `confidence`, plus `graph_completeness`.

**Sample-shaped output (abbreviated):**

```json
{
  "class": "App\\Services\\FooService",
  "declared_dependencies": [
    {
      "class": "App\\Repositories\\FooRepository",
      "type": "constructor_injection",
      "source": "runtime",
      "confidence": "high"
    }
  ],
  "hidden_dependencies": [
    {
      "class": "Illuminate\\Support\\Facades\\Cache",
      "type": "facade",
      "source": "static",
      "confidence": "high"
    }
  ],
  "graph_completeness": {
    "coverage": "partial"
  }
}
```

### Who depends on this class?

```text
Use get-class-dependency-graph for App\Repositories\FooRepository with direction "inbound" and depth 3. List the dependents and group them by dependency type (constructor_injection, method_injection, facade, service_location, instantiation, global_helper).
```

**Expected outcome:** An inbound dependency report useful for impact analysis before a refactor.

### Trace both directions for a refactor

```text
I am about to refactor App\Services\BillingService. Use get-class-dependency-graph with direction "both" and depth 4. Tell me (1) what it depends on, (2) what depends on it, and (3) the highest-risk couples for a breaking change.
```

**Expected outcome:** Outbound and inbound graphs plus a short risk-focused summary.

### Contribute a missing dependency edge

```text
Static analysis missed that App\Http\Controllers\CheckoutController resolves App\Services\PaymentGateway via app()->make() with a dynamic argument. Use contribute-graph-knowledge to propose a DEPENDS_ON edge from App\Http\Controllers\CheckoutController to App\Services\PaymentGateway with depends_on_type "service_location" and confidence "medium". If confirmation is required, show me the proposal and wait before writing.
```

**Expected outcome:** Either an immediate write (`confidence: high`) or a `confirmation_required` proposal. After you confirm, a retry with `confirmed: true` persists the edge with `source: user`.

---

## Graph Data Science (GDS)

> Requires the Neo4j Graph Data Science plugin. Without it, `list-gds-procedures` fails while schema and Cypher tools still work.

### Discover available GDS procedures

```text
Use list-gds-procedures and summarize the available Graph Data Science procedures. Group them into categories a Laravel developer would care about (centrality, community detection, path finding, similarity). Suggest one concrete next experiment on my graph.
```

**Expected outcome:** A categorized list of GDS procedures, or a clear error if the GDS plugin is missing.

---

## Debugging and diagnostics

### Sanity-check connectivity and schema

```text
First use get-schema to confirm we can talk to Neo4j. Then use read-cypher to run RETURN 1 AS ok. Report whether connectivity and basic reads are healthy.
```

**Expected outcome:** Schema tool success (or empty-db note) plus `ok: 1` from a trivial read.

### Investigate an empty or surprising schema

```text
get-schema returned little or no information. Use read-cypher to list labels with CALL db.labels() and relationship types with CALL db.relationshipTypes(). Tell me whether the database is empty, missing plugins, or simply sparse.
```

**Expected outcome:** A diagnosis distinguishing empty data, missing APOC/catalog support, or a valid sparse graph.

### Validate container graph export quality

```text
After container:graph export, use get-class-dependency-graph on App\Providers\AppServiceProvider and App\Http\Controllers\Controller (or the closest equivalents in this app). Summarize whether bindings and dependencies look populated, and what graph_completeness suggests we should improve next (for example static scan paths).
```

**Expected outcome:** A practical checklist for improving export coverage (static scan paths, facade catalog, contributed edges).

---

## Tips

- Prefer `read-cypher` for exploration; use `write-cypher` only when changing data.
- Run `php artisan container:graph` before dependency-graph prompts.
- Keep class names fully qualified (`App\Services\FooService`).
- If a tool fails, see [Troubleshooting](TROUBLESHOOTING.md) and the [README troubleshooting summary](../README.md#common-issues--troubleshooting).
