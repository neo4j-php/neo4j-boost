# Neo4j Boost content plan (SOFT-67)

## Purpose

This plan turns existing Neo4j Boost documentation and recorded demos into a practical tutorial and blog roadmap for **SOFT-67** (“Publish Tutorials and Blog Posts”).

It is a planning document only. It does **not** replace the [README](../README.md) as the command/config reference, and it does **not** invent features beyond what the current package implements.

**Working-tree note (branch `SOFT-67`):**

- Present: [docs/tutorials/what-is-neo4j-boost.md](tutorials/what-is-neo4j-boost.md) (linked from the README)
- Present: [docs/billing-driver-transport-e2e.md](billing-driver-transport-e2e.md) (internal E2E notes)
- **Not present:** `docs/media/` / demo GIFs (those files exist on branch `SOFT-66` and are planned to be brought over later—not as part of writing this plan)

---

## Inventory snapshot

| Asset | Location on `SOFT-67` | Role |
|-------|------------------------|------|
| What is Neo4j Boost? | `docs/tutorials/what-is-neo4j-boost.md` | Beginner tutorial (done) |
| Package README | `README.md` | Primary docs index + reference |
| Changelog | `CHANGELOG.md` | Release notes |
| Driver transport E2E notes | `docs/billing-driver-transport-e2e.md` | Internal verification record |
| Demo GIFs + media README | **Absent** (on `SOFT-66` under `docs/media/demos/` and `docs/media/README.md`) | Visual demos for README/tutorials once brought over |
| MCP tools (source) | `src/Boost/Tools/*` | `get-schema`, `read-cypher`, `write-cypher`, `list-gds-procedures`, `get-class-dependency-graph` |
| Artisan commands (source) | `src/Console/*` | `neo4j-boost:setup`, `install-mcp`, `start-neo4j`, `cursor-config`, `doctor`, `test-stdio`, `container:graph` |

Demo GIF filenames on `SOFT-66` (for planning only—**not available on this branch yet**):

| File (on `SOFT-66`) | Maps to |
|---------------------|---------|
| `01-interactive-setup.gif` | `neo4j-boost:setup` |
| `02-readiness-doctor.gif` | `neo4j-boost:doctor` |
| `03-cursor-mcp-tools.gif` | Cursor + `boost:mcp` tool list |
| `04-get-schema-in-cursor.gif` | `get-schema` |
| `05-read-cypher.gif` | `read-cypher` |
| `06-write-cypher.gif` | `write-cypher` |
| `07-container-dependency-tool.gif` | `container:graph` + `get-class-dependency-graph` |
| `08-container-graph-browser.gif` | Neo4j Browser + container-graph Cypher |

Those demos still match commands and tools registered in the current source. They should be embedded after they are brought onto this branch—not treated as present today.

---

## Grouped tutorial roadmap

Several short demos belong in one tutorial so readers get a coherent workflow instead of one page per GIF.

| Content | Existing Asset | Type | Feature | Target Audience | Practical Example | Priority | Status |
| ------- | -------------- | ---- | ------- | --------------- | ----------------- | -------- | ------ |
| What is Neo4j Boost? | [docs/tutorials/what-is-neo4j-boost.md](tutorials/what-is-neo4j-boost.md); README tutorial link | Tutorial | Package overview, MCP, transports at a high level, when to use | Laravel/PHP developers new to Neo4j Boost | Intro concepts + light Cursor / DI examples already in the tutorial | P0 | **Done** |
| Getting Started: Setup + Readiness Doctor | README Installation / Troubleshooting; commands `neo4j-boost:setup`, `neo4j-boost:start-neo4j`, `neo4j-boost:doctor`; planned GIFs `01` + `02` on `SOFT-66` | Tutorial (+ GIF embeds when available) | STDIO-first onboarding and readiness diagnostics | First-time installers | Run setup, then confirm healthy `doctor` output (stdio / binary / password) | P0 | Planned |
| Using Neo4j MCP Tools in Cursor | README “Using with Cursor” / “Single MCP server”; `neo4j-boost:cursor-config`, `boost:mcp`; tools `get-schema`, `read-cypher`, `write-cypher`; planned GIFs `03`–`06` on `SOFT-66` | Tutorial (+ GIF embeds when available) | One `laravel-boost` MCP server and Neo4j Cypher tools | Developers using Cursor with Laravel Boost | Connect MCP → call `get-schema` → `read-cypher` → disposable `write-cypher` | P0 | Planned |
| Debug Laravel DI with the Container Graph | README “Container Graph POC”; `container:graph`; tool `get-class-dependency-graph`; planned GIFs `07` + `08` on `SOFT-66` | Tutorial (+ GIF embeds when available) | Export Laravel container wiring to Neo4j and explore it | Intermediate / advanced Laravel developers | `container:graph` → MCP dependency query → Neo4j Browser path | P1 | Planned |

### Suggested future filenames (not created by this plan)

| Tutorial | Suggested path |
|----------|----------------|
| Getting Started | `docs/tutorials/getting-started.md` |
| Using Neo4j MCP Tools in Cursor | `docs/tutorials/cursor-mcp-tools.md` |
| Container Graph | `docs/tutorials/container-graph.md` |

These align with the placeholders already listed under “What's Next?” in [what-is-neo4j-boost.md](tutorials/what-is-neo4j-boost.md) (installation / setup / MCP / usage), without requiring one tutorial per placeholder.

---

## Documentation / reference (keep in the README)

Do **not** duplicate these as separate tutorials. Tutorials should link out and teach workflows; the README remains the source of truth for:

| Content | Existing Asset | Type | Feature | Target Audience | Practical Example | Priority | Status |
| ------- | -------------- | ---- | ------- | --------------- | ----------------- | -------- | ------ |
| Installation & transports | README → Installation / Configuration | Documentation/reference | `NEO4J_MCP_TRANSPORT` (`stdio` / `http` / `driver`), env vars, Docker MCP example | All users | Copy env blocks and commands from README | P0 | **Exists** |
| Artisan command list | README → Artisan commands | Documentation/reference | Full command table including setup, doctor, test-stdio, container:graph | All users | `php artisan …` reference | P0 | **Exists** |
| Cursor MCP entry shape | README → Single MCP server / Using with Cursor | Documentation/reference | `laravel-boost` → `php artisan boost:mcp` | Cursor users | JSON snippet in README | P0 | **Exists** |
| Container graph model & Cypher | README → Container Graph POC | Documentation/reference | Labels/relationships and example queries | Users exploring DI graphs | `MATCH (a:Abstract)-[:BINDS_TO\|DEPENDS_ON*…]` examples | P0 | **Exists** |
| Troubleshooting | README → Troubleshooting | Documentation/reference | APP_ENV / MCP JSON / APOC / GDS / Docker host pitfalls | Users hitting errors | Error → fix bullets | P0 | **Exists** |
| Release notes | `CHANGELOG.md` | Documentation/reference | Version history | All users | Link from README | P0 | **Exists** |

`docs/billing-driver-transport-e2e.md` stays an **internal** verification note (machine-specific paths). Do not promote it as a public tutorial.

---

## Role of demo GIFs (`SOFT-66`)

| Content | Existing Asset | Type | Feature | Target Audience | Practical Example | Priority | Status |
| ------- | -------------- | ---- | ------- | --------------- | ----------------- | -------- | ------ |
| Demo GIFs for README / tutorials | Eight GIFs + recording notes on branch `SOFT-66` (`docs/media/demos/`, `docs/media/README.md`) | Demo/GIF only | Visual proof of setup, doctor, Cursor tools, Cypher, container graph | Skimmers and README readers | Embed beside matching tutorial/README steps **after** assets exist on this branch | P1 | On `SOFT-66` only; **planned bring-over** |

Rules for this branch:

- Do **not** claim GIFs are available under `docs/media/` on `SOFT-67` today.
- Do **not** invent new demo flows beyond what the package commands/tools support.
- Prefer embedding GIFs inside the **grouped** tutorials (and optionally README sections) rather than publishing eight standalone tutorial pages.

---

## Potential future blog posts

Write blogs **after** the corresponding tutorials exist so they can link to accurate walkthroughs.

| Content | Existing Asset | Type | Feature | Target Audience | Practical Example | Priority | Status |
| ------- | -------------- | ---- | ------- | --------------- | ----------------- | -------- | ------ |
| Neo4j MCP in Laravel with Boost | Getting Started + Cursor MCP tutorials; demos `01`–`06` (when on branch) | Blog post | Narrative: install → Cursor → schema/Cypher in chat | Broader Laravel / AI-assisted audience | Short path from `composer require neo4j/laravel-boost` to `get-schema` in Cursor | P2 | Idea |
| See your Laravel container as a graph | Container Graph tutorial; demos `07`–`08` (when on branch) | Blog post | DI debugging story using Neo4j | Architects / larger Laravel apps | `container:graph` + Browser visualization | P2 | Idea |

---

## Optional / lower priority

| Content | Existing Asset | Type | Feature | Target Audience | Practical Example | Priority | Status |
| ------- | -------------- | ---- | ------- | --------------- | ----------------- | -------- | ------ |
| GDS procedures note or short add-on | README “Enable GDS”; MCP tool `list-gds-procedures`; **no** dedicated GIF | Docs note or short tutorial section | Requires Graph Data Science plugin on Neo4j | Users who already run GDS | Call `list-gds-procedures` after enabling GDS | P3 | Optional |
| Driver transport public guide | `docs/billing-driver-transport-e2e.md`; config `NEO4J_MCP_TRANSPORT=driver`; `Neo4jDriverClient` | Internal/reference first; public docs only if needed later | In-process Bolt tools without `neo4j-mcp` binary | Maintainers / advanced ops | Set `driver` transport and call the same tool names via Boost MCP | P3 | Internal only for now |

---

## Recommended publishing order

1. **What is Neo4j Boost?** — done and linked from the README.
2. **Getting Started: Setup + Readiness Doctor** — fill the first guided install path.
3. Bring **`docs/media/` demos from `SOFT-66`** onto this branch (separate task; not part of this plan file).
4. **Using Neo4j MCP Tools in Cursor** — one tutorial covering connect + schema + read + write (embed GIFs `03`–`06` when present).
5. **Debug Laravel DI with the Container Graph** — advanced tutorial (embed GIFs `07`–`08` when present).
6. Update README / “What's Next?” links to point at the new tutorials (without duplicating README reference sections).
7. **Blog posts** once tutorials are stable.
8. Optional GDS / driver-transport public content only if needed.

---

## Mapping to SOFT-67 acceptance criteria

SOFT-67 needs educational content that is accurate, discoverable, and built from real package capabilities. This plan maps as follows:

| # | Acceptance criterion | How this plan satisfies it |
|---|----------------------|----------------------------|
| 1 | **Inventory before inventing** — base tutorials/blogs on real repo assets and current commands/tools | Inventory lists README, CHANGELOG, the completed intro tutorial, source-registered MCP tools/Artisan commands, and demos that exist on `SOFT-66` but not on this branch |
| 2 | **Grouped practical roadmap** — demos become coherent tutorials/blogs, not one page per GIF | Four tutorial slots: intro (done), Getting Started (`01`+`02`), Cursor MCP tools (`03`–`06`), Container Graph (`07`+`08`); blogs deferred to P2 |
| 3 | **Ship the intro tutorial and keep README as reference** | What is Neo4j Boost? is marked **Done** and already linked from the README; reference material stays in README rather than being rewritten as tutorials |
| 4 | **Honest media status** — do not claim demos that are not on the branch | GIFs explicitly marked as **on `SOFT-66` / planned bring-over**; this plan does not copy or recreate those assets |

Content types used deliberately:

- **Tutorial** — guided workflows
- **Blog post** — narrative pieces after tutorials exist
- **Documentation/reference** — README / CHANGELOG
- **Demo/GIF only** — visual embeds once media is on this branch
