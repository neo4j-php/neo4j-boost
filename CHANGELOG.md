# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [Unreleased]

### Added

- Method injection detection for controllers, jobs, commands, listeners, and middleware. `container:graph` emits `DEPENDS_ON` edges with `type: method_injection`, `method`, and `parameter` (includes Form Request types on controller actions).
- Static scan for Laravel global helper usage (`cache`, `auth`, `view`, `response`, `redirect`, `route`, `event`, `dispatch`, `logger`, `session`, `config`, `env`). Exports `DEPENDS_ON` edges with `type: global_helper` and `helper`.
- Static scan for direct class instantiation (`new ClassName()`). Exports `DEPENDS_ON` edges with `type: instantiation`, skipping PHP internal classes, anonymous classes, and dynamic class expressions.
- Edge metadata on dependency graph relationships: `source`, `confidence`, `provenance`, and `remarks` on every `DEPENDS_ON` and `BINDS_TO` edge. MCP `get-class-dependency-graph` responses include `graph_completeness: partial` with documented limitations.
- `get-class-dependency-graph` now returns `declared_dependencies` and `hidden_dependencies` buckets, per-dependency `visibility`, and a richer `graph_completeness` block with per-class `coverage`, declared/hidden counts, and active/pending detectors.
- **contribute-graph-knowledge** MCP tool to add dependency or binding edges when static analysis cannot infer them.

## [0.1.0] - 2026-04-29

### Added

- Neo4j MCP tools for Laravel Boost: `get-schema`, `read-cypher`, `write-cypher`, `list-gds-procedures`.
- HTTP and STDIO transport to the official Neo4j MCP server; `neo4j-boost:cursor-config` for `.cursor/mcp.json`.
- Laravel 12 and 13 support (PHP 8.2+).
- GitHub Actions: Pint, PHPStan with Larastan, PHPUnit on PHP 8.2–8.5.
- PHPUnit suite and package dev tooling (Pint, Larastan, Orchestra Testbench).

### Changed

- First public semver release under `neo4j/laravel-boost` (previously `1.0.0` placeholder in `composer.json`).

[0.1.0]: https://github.com/neo4j-php/neo4j-boost/releases/tag/v0.1.0
