# Documentation media

This directory contains the recording plan and assets embedded in the project
README. Neo4j Laravel Boost has no package-specific web UI: the recording
surfaces are the terminal, Cursor's MCP/Agent UI, and Neo4j Browser.

The workflows below are based on the registered Artisan commands and MCP tools
in this repository. Do not replace them with mocked output.

## Directory layout

```text
docs/media/
├── README.md
├── prepare-demo.sh       # Check and prepare a disposable local demo
├── publish-demos.sh      # Validate GIFs and uncomment README embeds
├── demos/                # Optimized GIFs embedded from README.md
├── video/                # Optional MP4/WebM recording masters
└── posters/              # Optional static thumbnails / fallbacks
```

## Safety and prerequisites

- Record against the local `neo4j-boost-local` Docker container, not production.
- The preparation script uses the public disposable password
  `password` by default, matching the package's local examples. Override it with
  `NEO4J_DEMO_PASSWORD=...`.
- The script never removes or recreates an existing container unless
  `NEO4J_DEMO_RECREATE=1` is explicitly set.
- Hide notifications and unrelated terminal history before recording.
- Never show real credentials, remote URLs, tokens, or customer graph data.
- Use a terminal width where command output remains readable at README width.

Required locally:

- PHP 8.2+
- Composer dependencies (`vendor/`)
- Docker CLI and a running Docker daemon
- Cursor for demos 03–07
- A browser for demo 08
- Optional: `ffmpeg` and `gifsicle` for GIF optimization

## Preparation order

The first setup demo must be recorded before the full preparation step because
`neo4j-boost:setup` stores first-run state and installs the MCP binary.

From the repository root:

```bash
# 1. Read-only prerequisite/state check
./docs/media/prepare-demo.sh check

# 2. Create/update the ignored local .env only
./docs/media/prepare-demo.sh init-env

# 3. Record demo 01 now
php artisan neo4j-boost:setup

# 4. Prepare deterministic data for demos 02–08
./docs/media/prepare-demo.sh prepare
```

If demo 01 has already been recorded, `prepare` is safe to run repeatedly.
Seed data uses `MERGE`, and `container:graph` is also MERGE-based.

## Required GIFs

| File | Ideal length | README section |
|------|--------------|----------------|
| `01-interactive-setup.gif` | 12–15 seconds | Installation → Run interactive setup |
| `02-readiness-doctor.gif` | 6–8 seconds | Troubleshooting (intro) |
| `03-cursor-mcp-tools.gif` | 8–12 seconds | Using with Cursor |
| `04-get-schema-in-cursor.gif` | 10–15 seconds | Using with Cursor |
| `05-read-cypher.gif` | 8–12 seconds | Single MCP server with Laravel Boost |
| `06-write-cypher.gif` | 8–12 seconds | Single MCP server with Laravel Boost |
| `07-container-dependency-tool.gif` | 12–15 seconds | Container Graph POC → Run |
| `08-container-graph-browser.gif` | 8–12 seconds | Container Graph POC → Example Cypher queries |

## Recording scripts

### 01 — First-run interactive setup

**Goal:** show the default STDIO-first onboarding flow.

**Before recording**

```bash
./docs/media/prepare-demo.sh init-env
./docs/media/prepare-demo.sh check
```

The check should report no setup marker and no installed binary for a true
first-run recording. If either exists, use a fresh consumer Laravel app or a
disposable clone. Do not delete an existing binary or setup marker merely to
record the GIF.

**Record**

```bash
php artisan neo4j-boost:setup
```

1. Start recording with a clean terminal and the command already typed.
2. Press Enter to run it.
3. At `Install the official Neo4j MCP server binary for this project?`, accept
   the default `yes`.
4. Stop after:
   `Neo4j Laravel Boost setup complete. STDIO transport is ready...`

**Expected on screen**

- `Default proxy path: Boost MCP -> STDIO -> neo4j-mcp binary`
- Binary version/target and successful install
- `First-time setup detected. Attempting to start local Neo4j...`
- Started/already-running Neo4j message
- `.cursor/mcp.json` created or updated
- Final STDIO-ready message

**Jump cuts**

- Cut from the binary download task starting to its success result.
- Cut Docker image pull/start time.
- Keep the prompt, first-time setup line, Cursor config result, and final result.

**Ideal length:** 12–15 seconds.

### 02 — Readiness doctor

**Goal:** show the first troubleshooting command and a healthy STDIO setup.

**Before recording**

```bash
./docs/media/prepare-demo.sh prepare
```

**Record**

```bash
php artisan neo4j-boost:doctor --no-interaction
```

Start with the command typed. Stop when the readiness rows are all visible.

**Expected on screen**

- STDIO proxy architecture
- `Transport ... stdio`
- `Neo4j MCP binary ... installed`
- `NEO4J_PASSWORD ... set`
- `STDIO readiness ... ready`

`--no-interaction` is intentional: if local state is unexpectedly incomplete,
the command reports it without opening the install prompt.

**Jump cuts:** none in a healthy environment.

**Ideal length:** 6–8 seconds.

### 03 — Connect Laravel Boost in Cursor

**Goal:** show that one `laravel-boost` MCP server exposes both Boost and Neo4j
tools.

**Before recording**

```bash
php artisan neo4j-boost:cursor-config
```

**Record**

1. Start on `.cursor/mcp.json`.
2. Keep the `laravel-boost` entry visible:

   ```json
   {
     "command": "php",
     "args": ["artisan", "boost:mcp"]
   }
   ```

   This package repository may additionally contain
   `"env": {"APP_ENV": "local"}`.
3. Open Cursor's MCP settings (the exact panel label can vary by Cursor
   version).
4. Enable or reload `laravel-boost`.
5. Expand its tools.
6. Stop when these names are visible:
   `get-schema`, `read-cypher`, `write-cypher`,
   `list-gds-procedures`, `get-class-dependency-graph`.

**Expected on screen:** one connected `laravel-boost` server, not a separate
direct `neo4j-boost` HTTP server.

**Jump cuts:** cut MCP startup/reload waiting; retain the transition from config
to connected tools.

**Ideal length:** 8–12 seconds.

### 04 — Inspect the graph schema from Cursor

**Goal:** show an agent invoking the registered read-only `get-schema` tool.

**Cursor prompt**

```text
Use get-schema and summarize the labels and relationship types in my Neo4j database.
```

Start with the complete prompt typed, then send it. Stop when the tool result
and concise summary are visible.

**Expected on screen**

- A `get-schema` tool call
- Seeded labels `BoostDemoPerson` and `BoostDemoMovie`
- Relationship type `BOOST_DEMO_RELATES_TO`
- The tool may also include container-graph labels after demo preparation

**Jump cuts:** remove agent thinking/tool latency, but keep the visible tool name
and returned schema summary.

**Ideal length:** 10–15 seconds.

### 05 — Read data with Cypher

**Goal:** demonstrate the `read-cypher` tool without modifying the graph.

**Cursor prompt**

```text
Use read-cypher to run MATCH (n) WHERE n:BoostDemoPerson OR n:BoostDemoMovie RETURN labels(n) AS labels, count(*) AS count ORDER BY count DESC.
```

Start with the prompt typed. Stop after the result rows are visible.

**Expected on screen**

- A `read-cypher` tool call
- `BoostDemoPerson` count `2`
- `BoostDemoMovie` count `1`

**Jump cuts:** remove only agent/tool latency.

**Ideal length:** 8–12 seconds.

### 06 — Disposable write round-trip

**Goal:** demonstrate `write-cypher` while leaving no temporary node behind.

**Cursor prompt**

```text
Use write-cypher to run CREATE (n:BoostDemoTemporary {name: 'temporary'}) WITH n DELETE n RETURN 1 AS completed.
```

Start with the prompt typed. Keep any client approval UI if Cursor presents it.
Stop when the result is visible.

**Expected on screen**

- A `write-cypher` tool call
- `completed = 1`
- No persistent `BoostDemoTemporary` node (creation and deletion are one query)

**Jump cuts:** remove tool latency, but do not hide an approval step.

**Ideal length:** 8–12 seconds.

### 07 — Export and query Laravel dependencies

**Goal:** show the package-specific container graph workflow end to end.

**Terminal command**

```bash
php artisan container:graph
```

Expected terminal output:

- Binding, class, dependency, and unresolved-dependency counts
- `Container graph written to Neo4j successfully.`

Then switch to Cursor and use:

```text
Use get-class-dependency-graph for Neo4j\LaravelBoost\ClassDependencyGraphReader with direction both and depth 4.
```

**Expected Cursor result**

- `found: true`
- `graph_export_required: false`
- A dependency on
  `Neo4j\LaravelBoost\Support\ContainerGraphConnection`
- Structured dependencies/dependents and pagination metadata

Start with the terminal command typed. Stop after Cursor shows the structured
result.

**Jump cuts**

- Cut between the command starting and its summary/result.
- Cut the switch from terminal to Cursor.
- Remove agent/tool latency.

**Ideal length:** 12–15 seconds.

### 08 — Visualize the container graph

**Goal:** show the exported Laravel DI graph in Neo4j Browser.

Open `http://localhost:7474`, sign in as `neo4j` with the disposable demo
password, and run:

```cypher
MATCH p = (:Abstract {
  name: 'Neo4j\\LaravelBoost\\ClassDependencyGraphReader'
})-[:DEPENDS_ON*1..3]-(n)
RETURN p
LIMIT 50;
```

Start with the query already pasted and credentials out of frame. Press Run.
Stop when the graph visualization settles.

**Expected on screen**

- `ClassDependencyGraphReader`
- `ContainerGraphConnection`
- `Abstract` / `Class` nodes
- `DEPENDS_ON` relationships

**Jump cuts:** cut browser login and query execution/rendering delay. Do not show
the password.

**Ideal length:** 8–12 seconds.

## Recording checklist

### Environment

- [ ] `./docs/media/prepare-demo.sh check` passes
- [ ] `.env` uses `NEO4J_MCP_TRANSPORT=stdio`
- [ ] Demo password only; no real credentials visible
- [ ] `neo4j-boost-local` is running with APOC
- [ ] Official `neo4j-mcp` binary is installed
- [ ] `.cursor/mcp.json` contains one `laravel-boost` entry
- [ ] `php artisan neo4j-boost:doctor --no-interaction` reports ready
- [ ] Seed labels and relationship exist
- [ ] `php artisan container:graph` succeeds
- [ ] Notifications and unrelated windows are hidden

### Capture

- [ ] 01 `01-interactive-setup.gif`
- [ ] 02 `02-readiness-doctor.gif`
- [ ] 03 `03-cursor-mcp-tools.gif`
- [ ] 04 `04-get-schema-in-cursor.gif`
- [ ] 05 `05-read-cypher.gif`
- [ ] 06 `06-write-cypher.gif`
- [ ] 07 `07-container-dependency-tool.gif`
- [ ] 08 `08-container-graph-browser.gif`

### Quality

- [ ] Each clip is 5–15 seconds
- [ ] Text remains readable at approximately 1200px width
- [ ] Waiting time is removed without hiding meaningful user actions
- [ ] Every clip begins with clear context and ends on the result
- [ ] No secrets, unrelated project names, or customer data appear
- [ ] GIF loops cleanly and is reasonably sized (target under 10 MB)

## Convert video masters to GIF

Example using `ffmpeg`:

```bash
ffmpeg -i docs/media/video/01-interactive-setup.mp4 \
  -vf "fps=12,scale=1200:-1:flags=lanczos,split[s0][s1];[s0]palettegen=max_colors=128[p];[s1][p]paletteuse=dither=bayer" \
  -loop 0 docs/media/demos/01-interactive-setup.gif
```

Optional final optimization:

```bash
gifsicle -O3 --colors 128 \
  docs/media/demos/01-interactive-setup.gif \
  -o docs/media/demos/01-interactive-setup.gif
```

Repeat with the exact filenames listed above.

## Publish the recorded GIFs in README

Until a GIF exists, its Markdown embed remains commented so GitHub does not show
a broken image.

After all eight GIFs are present:

```bash
./docs/media/publish-demos.sh check
./docs/media/publish-demos.sh enable
```

`enable` removes only the eight matching `<!-- Uncomment after recording ...`
wrappers. It refuses to edit README if any required GIF is missing or empty.

The affected README sections are:

1. `Installation` → `2. Run interactive setup` (demo 01)
2. `Troubleshooting` introduction (demo 02)
3. `Using with Cursor` (demos 03 and 04)
4. `Single MCP server with Laravel Boost` (demos 05 and 06)
5. `Container Graph POC` → `Run` (demo 07)
6. `Container Graph POC` → `Example Cypher queries` (demo 08)

After enabling, review README in GitHub's Markdown preview and run:

```bash
./docs/media/publish-demos.sh check
git diff -- README.md docs/media/
```
