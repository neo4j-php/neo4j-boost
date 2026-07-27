# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Interactive setup and local helpers: `neo4j-boost:setup`, `neo4j-boost:install-mcp`, `neo4j-boost:start-neo4j`, `neo4j-boost:doctor`, `neo4j-boost:test-stdio`.
- In-process `driver` transport (`NEO4J_MCP_TRANSPORT=driver`) via Bolt.
- Container graph export (`container:graph`) and MCP tool `get-class-dependency-graph`.
- Documentation media layout under `docs/media/` with README GIF embed placeholders.

### Changed

- Default local path documented as STDIO (`NEO4J_MCP_TRANSPORT=stdio`); clarified vs official binary `NEO4J_TRANSPORT_MODE`.
- `neo4j-boost:cursor-config` documented as writing the `laravel-boost` (`boost:mcp`) entry when Laravel Boost is present.
- Boost agent guidelines updated to match STDIO/HTTP/driver transports.

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
