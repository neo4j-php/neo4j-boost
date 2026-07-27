#!/usr/bin/env bash

set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ENV_FILE="${ROOT_DIR}/.env"
CONTAINER="${NEO4J_DEMO_CONTAINER:-neo4j-boost-local}"
DEMO_PASSWORD="${NEO4J_DEMO_PASSWORD:-password}"
RECREATE="${NEO4J_DEMO_RECREATE:-0}"

cd "${ROOT_DIR}"

info() {
    printf '[demo] %s\n' "$*"
}

fail() {
    printf '[demo] ERROR: %s\n' "$*" >&2
    exit 1
}

require_command() {
    command -v "$1" >/dev/null 2>&1 || fail "Required command not found: $1"
}

check_prerequisites() {
    local failed=0

    for command in php composer docker; do
        if command -v "${command}" >/dev/null 2>&1; then
            info "${command}: available"
        else
            printf '[demo] ERROR: required command not found: %s\n' "${command}" >&2
            failed=1
        fi
    done

    if [[ -f artisan ]]; then
        info "artisan: available"
    else
        printf '[demo] ERROR: artisan not found at repository root\n' >&2
        failed=1
    fi

    if [[ -f vendor/autoload.php ]]; then
        info "Composer dependencies: installed"
    else
        info "Composer dependencies: missing (prepare will run composer install)"
    fi

    if docker info >/dev/null 2>&1; then
        info "Docker daemon: reachable"
    else
        printf '[demo] ERROR: Docker daemon is not reachable\n' >&2
        failed=1
    fi

    if [[ -f "${ENV_FILE}" ]]; then
        info ".env: present (ignored by git)"
    else
        info ".env: missing (run: $0 init-env)"
    fi

    if [[ -f storage/app/neo4j-mcp/.setup-complete ]]; then
        info "First-run setup marker: present"
    else
        info "First-run setup marker: absent"
    fi

    if [[ -x storage/app/neo4j-mcp/neo4j-mcp ]] ||
        [[ -x storage/app/neo4j-mcp/neo4j-mcp.exe ]]; then
        info "Neo4j MCP binary: installed"
    else
        info "Neo4j MCP binary: not installed"
    fi

    local container_status
    container_status="$(docker ps -a \
        --filter "name=^/${CONTAINER}$" \
        --format '{{.Status}}' 2>/dev/null || true)"
    if [[ -n "${container_status}" ]]; then
        info "Docker container ${CONTAINER}: ${container_status}"
        if container_password_matches; then
            info "Existing container password: matches configured demo password"
        else
            info "Existing container password: does not match configured demo password"
            info "Set NEO4J_DEMO_PASSWORD to the existing password or explicitly use NEO4J_DEMO_RECREATE=1"
        fi
    else
        info "Docker container ${CONTAINER}: absent"
    fi

    [[ "${failed}" -eq 0 ]] || exit 1
}

container_password_matches() {
    local auth

    auth="$(docker inspect --format '{{range .Config.Env}}{{println .}}{{end}}' \
        "${CONTAINER}" 2>/dev/null |
        grep '^NEO4J_AUTH=' || true)"

    [[ "${auth}" == "NEO4J_AUTH=neo4j/${DEMO_PASSWORD}" ]]
}

assert_container_password_compatible() {
    if ! docker inspect "${CONTAINER}" >/dev/null 2>&1; then
        return 0
    fi

    if container_password_matches; then
        return 0
    fi

    if [[ "${RECREATE}" == "1" ]]; then
        return 0
    fi

    fail "Existing ${CONTAINER} uses a different password. Set NEO4J_DEMO_PASSWORD to match it, or explicitly recreate it with NEO4J_DEMO_RECREATE=1."
}

upsert_demo_env() {
    php -r '
        $path = $argv[1];
        $contents = is_file($path) ? (string) file_get_contents($path) : "";

        foreach (array_slice($argv, 2) as $assignment) {
            [$key, $value] = explode("=", $assignment, 2);
            $line = $key."=".$value;
            $pattern = "/^".preg_quote($key, "/")."=.*$/m";

            if (preg_match($pattern, $contents) === 1) {
                $contents = (string) preg_replace($pattern, $line, $contents);
            } else {
                $contents = rtrim($contents).PHP_EOL.$line.PHP_EOL;
            }
        }

        file_put_contents($path, $contents);
    ' "${ENV_FILE}" \
        "APP_ENV=local" \
        "APP_DEBUG=true" \
        "NEO4J_MCP_TRANSPORT=stdio" \
        "NEO4J_URI=bolt://localhost:7687" \
        "NEO4J_USERNAME=neo4j" \
        "NEO4J_USER=neo4j" \
        "NEO4J_PASSWORD=${DEMO_PASSWORD}"
}

init_env() {
    require_command php

    if [[ ! -f "${ENV_FILE}" ]]; then
        cp .env.example "${ENV_FILE}"
        info "Created .env from .env.example"
    elif [[ ! -f "${ENV_FILE}.demo-backup" ]]; then
        cp "${ENV_FILE}" "${ENV_FILE}.demo-backup"
        info "Backed up existing .env to .env.demo-backup"
    fi

    upsert_demo_env
    info "Configured ignored .env for local STDIO demos"
    info "Demo password is intentionally disposable; it was not printed"
}

wait_for_neo4j() {
    local attempt

    info "Waiting for Neo4j to accept Cypher..."
    for attempt in $(seq 1 60); do
        if docker exec "${CONTAINER}" cypher-shell \
            -u neo4j \
            -p "${DEMO_PASSWORD}" \
            'RETURN 1 AS ready;' >/dev/null 2>&1; then
            info "Neo4j is ready"
            return 0
        fi
        sleep 2
    done

    fail "Neo4j did not become ready. If the existing container uses another password, set NEO4J_DEMO_PASSWORD to match it or explicitly recreate with NEO4J_DEMO_RECREATE=1."
}

seed_demo_graph() {
    wait_for_neo4j
    info "Seeding deterministic documentation nodes"

    docker exec -i "${CONTAINER}" cypher-shell \
        -u neo4j \
        -p "${DEMO_PASSWORD}" >/dev/null <<'CYPHER'
MERGE (ada:BoostDemoPerson {id: 'ada'})
SET ada.name = 'Ada'
MERGE (grace:BoostDemoPerson {id: 'grace'})
SET grace.name = 'Grace'
MERGE (movie:BoostDemoMovie {id: 'graph-demo'})
SET movie.title = 'Graph Demo'
MERGE (ada)-[:BOOST_DEMO_RELATES_TO {role: 'viewer'}]->(movie)
MERGE (grace)-[:BOOST_DEMO_RELATES_TO {role: 'viewer'}]->(movie);
CYPHER

    info "Seeded 2 BoostDemoPerson nodes, 1 BoostDemoMovie node, and BOOST_DEMO_RELATES_TO relationships"
}

prepare_demo() {
    require_command php
    require_command composer
    require_command docker

    docker info >/dev/null 2>&1 || fail "Docker daemon is not reachable"
    init_env
    assert_container_password_compatible

    if [[ ! -f vendor/autoload.php ]]; then
        info "Installing Composer dependencies"
        composer install --no-interaction
    fi

    info "Installing/checking the official Neo4j MCP binary"
    php artisan neo4j-boost:install-mcp \
        --no-cursor-config \
        --no-interaction

    local start_args=(php artisan neo4j-boost:start-neo4j --no-interaction)
    if [[ "${RECREATE}" == "1" ]]; then
        info "Explicit recreation enabled for ${CONTAINER}"
        start_args+=(--recreate)
    fi

    "${start_args[@]}"
    seed_demo_graph

    info "Writing the Laravel Boost Cursor MCP entry"
    php artisan neo4j-boost:cursor-config --no-interaction

    info "Exporting the Laravel container graph"
    php artisan container:graph --no-interaction

    info "Final readiness report"
    php artisan neo4j-boost:doctor --no-interaction

    info "Preparation complete for demos 02–08"
}

usage() {
    cat <<'USAGE'
Usage: docs/media/prepare-demo.sh <command>

Commands:
  check       Read-only prerequisite and local-state report
  init-env    Create/update ignored .env with disposable STDIO demo settings
  seed        Wait for the existing local container and seed deterministic data
  prepare     Install/check dependencies and binary, start Neo4j, seed data,
              write Cursor config, export container graph, and run doctor

Environment overrides:
  NEO4J_DEMO_PASSWORD   Disposable local password (default: password)
  NEO4J_DEMO_CONTAINER  Container name (default: neo4j-boost-local)
  NEO4J_DEMO_RECREATE=1 Explicitly recreate an existing container during prepare

Record demo 01 after init-env and before prepare.
USAGE
}

case "${1:-}" in
    check)
        check_prerequisites
        ;;
    init-env)
        init_env
        ;;
    seed)
        require_command docker
        seed_demo_graph
        ;;
    prepare)
        prepare_demo
        ;;
    *)
        usage
        exit 1
        ;;
esac
