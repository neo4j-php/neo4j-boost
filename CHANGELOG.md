# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-07-06

### Added

- **Three-node container dependency model** for `container:graph`: `Instance → DEPENDS_ON → Dependency → RESOLVES_TO → Identifier`, replacing direct class-to-class dependency edges.
- **Static scan** (opt-in via `NEO4J_CONTAINER_GRAPH_STATIC_SCAN_PATHS`) for hidden dependencies:
  - Service location (`app()`, `resolve()`, `App::make()` with literal class arguments; extended locators and dynamic-call handling).
  - Facade static calls (`Cache::put`, custom `Facade::method`, real-time `\Facades\...` references), resolved via the resolution catalog with `catalog_source` metadata (`laravel_facade`, `auto_discovered_facade`).
  - Global helper usage (`cache`, `auth`, `view`, `response`, `redirect`, `route`, `event`, `dispatch`, `logger`, `session`, `config`, `env`).
  - Direct class instantiation (`new ClassName()`), skipping PHP internal classes, anonymous classes, and dynamic expressions.
- **Method injection detection** for controllers, jobs, commands, listeners, and middleware. Exports `DEPENDS_ON` edges with `type: method_injection`, `method`, and `parameter` (includes Form Request types on controller actions).
- **Contextual bindings** export: Laravel `when()->needs()->give()` overrides as `Instance-[:CONTEXTUAL_BINDS]->Identifier` edges.
- **Facade resolution catalog** export and runtime resolution (first-party Laravel facades, custom app facades via `getFacadeAccessor()`, and real-time facades).
- **Edge metadata** on every `DEPENDS_ON` and `BINDS_TO` relationship: `source`, `confidence`, `provenance`, and `remarks`.
- **Enhanced `get-class-dependency-graph` MCP tool**: `declared_dependencies` and `hidden_dependencies` buckets, per-dependency `visibility`, and a richer `graph_completeness` block (`coverage`, declared/hidden counts, active/pending detectors).
- **`contribute-graph-knowledge` MCP tool** to add dependency or binding edges when static analysis cannot infer them (with user confirmation for medium/low confidence).
- Neo4j PHP driver User-Agent set to `Neo4j/Laravel-Boost`.

### Changed

- `container:graph` summary now reports method injection, static scan, contextual binding, and facade catalog counts.
- MCP tool descriptions and Boost guidelines updated for the new graph model and metadata fields.

## [0.1.0] - 2026-04-29

### Added

- Neo4j MCP tools for Laravel Boost: `get-schema`, `read-cypher`, `write-cypher`, `list-gds-procedures`.
- HTTP and STDIO transport to the official Neo4j MCP server; `neo4j-boost:cursor-config` for `.cursor/mcp.json`.
- Laravel 12 and 13 support (PHP 8.2+).
- GitHub Actions: Pint, PHPStan with Larastan, PHPUnit on PHP 8.2–8.5.
- PHPUnit suite and package dev tooling (Pint, Larastan, Orchestra Testbench).

### Changed

- First public semver release under `neo4j/laravel-boost` (previously `1.0.0` placeholder in `composer.json`).

[1.0.0]: https://github.com/neo4j-php/neo4j-boost/releases/tag/v1.0.0
[0.1.0]: https://github.com/neo4j-php/neo4j-boost/releases/tag/v0.1.0
