# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
