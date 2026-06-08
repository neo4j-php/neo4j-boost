# Billing app E2E: `feat/driver-transport`

**App:** `/home/zaeem-ul-huda/dev/bitbucket/billing-management-system`  
**Package:** `neo4j/laravel-boost` @ `feat/driver-transport` (copied into `vendor/neo4j/laravel-boost`)  
**Date:** 2026-06-03  
**Neo4j:** 3-node Enterprise 5.26 cluster (`neo4j-core1:7687`), APOC enabled, no GDS

## Configuration used

```env
NEO4J_MCP_TRANSPORT=driver
NEO4J_DEFAULT_CONNECTION_DSN=neo4j://neo4j:test@neo4j-core1:7687
```

Inside Docker (`php-laravel`), use the DSN above (not host `localhost`). `composer.json` updated to `dev-feat/driver-transport`.

## Results summary

| Test | Result |
|------|--------|
| `Neo4jDriverClient` resolved | PASS |
| `get-schema` (APOC) | PASS |
| `read-cypher` MATCH | PASS |
| `read-cypher` CREATE rejected | PASS (after keyword guard) |
| `write-cypher` CREATE + cleanup | PASS |
| `list-gds-procedures` without GDS | PASS (clear error) |
| Boost tool registration (4 Neo4j tools) | PASS |
| `boost:mcp` `tools/list` | PASS |
| `boost:mcp` `tools/call` get-schema / read-cypher | PASS |
| Missing `NEO4J_URI` / DSN | PASS (actionable error) |

**Script:** `scripts/e2e-driver-transport.php` — 8/8 passed after fixes below.

## Bugs found and fixed during testing

### 1. Critical: `ClientInterface` auto-wired from `neo4j-laravel`

`Neo4jBoltExecutor` had an optional `ClientInterface` constructor parameter. With `neo4j-php/neo4j-laravel` installed, Laravel injected the app's Neo4j client (driver alias `default`), then queries used alias `neo4j-boost-mcp` → **"Cannot find a driver setup with alias"**.

**Fix:** Remove constructor injection; always use `BoltClientFactory::instance()`.

### 2. Medium: Billing uses `NEO4J_DEFAULT_CONNECTION_DSN`, not bare `NEO4J_URI`

Billing `.env` often sets `NEO4J_URI=neo4j://neo4j-core1:7687` without credentials. Driver transport must read `NEO4J_DEFAULT_CONNECTION_DSN=neo4j://neo4j:test@neo4j-core1:7687`.

**Fix:** `BoltClientFactory` falls back to DSN parsing when `NEO4J_URI` is empty.

### 3. Medium: EXPLAIN classifies `CREATE` as read-only (`r`)

On Neo4j 5.26, `EXPLAIN CREATE` returns query type `r`, so read-cypher could accept writes (same class of issue as neo4j/mcp relying on EXPLAIN).

**Fix:** Keyword guard for `CREATE`, `MERGE`, `DELETE`, `SET`, etc. before EXPLAIN round-trip.

## Recommended billing `.env` for driver mode

```env
NEO4J_MCP_TRANSPORT=driver
NEO4J_DEFAULT_CONNECTION_DSN=neo4j://neo4j:test@neo4j-core1:7687
# Optional explicit bolt settings (bolt section in config/neo4j-boost.php):
# NEO4J_URI=neo4j://neo4j-core1:7687
# NEO4J_USERNAME=neo4j
# NEO4J_PASSWORD=test
```

Run `php artisan config:clear` after changing transport (or avoid `config:cache` with wrong values).

## How to re-run

```bash
cd /home/zaeem-ul-huda/dev/bitbucket/billing-management-system
docker cp /home/zaeem-ul-huda/dev/neo4j-boost/. php-laravel:/var/www/html/vendor/neo4j/laravel-boost/
docker exec -e NEO4J_MCP_TRANSPORT=driver \
  -e NEO4J_DEFAULT_CONNECTION_DSN=neo4j://neo4j:test@neo4j-core1:7687 \
  php-laravel php /var/www/html/scripts/e2e-driver-transport.php
```

## Not tested / out of scope

- `NEO4J_MCP_TRANSPORT=http` vs `driver` side-by-side on same query (MCP container available but not compared in this run)
- GDS plugin install
- `neo4j-boost:cursor-config` (unchanged; still points at Boost `php artisan boost:mcp`)
