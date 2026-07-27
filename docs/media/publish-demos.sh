#!/usr/bin/env bash

set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
README="${ROOT_DIR}/README.md"
DEMO_DIR="${ROOT_DIR}/docs/media/demos"

FILES=(
    "01-interactive-setup.gif"
    "02-readiness-doctor.gif"
    "03-cursor-mcp-tools.gif"
    "04-get-schema-in-cursor.gif"
    "05-read-cypher.gif"
    "06-write-cypher.gif"
    "07-container-dependency-tool.gif"
    "08-container-graph-browser.gif"
)

ALTS=(
    "First-run interactive setup"
    "Run the readiness doctor"
    "Connect Laravel Boost in Cursor"
    "Inspect the graph schema from chat"
    "Read data with Cypher"
    "Run a disposable write round-trip"
    "Export and query Laravel dependencies"
    "Visualize the container graph"
)

info() {
    printf '[media] %s\n' "$*"
}

fail() {
    printf '[media] ERROR: %s\n' "$*" >&2
    exit 1
}

check_gifs() {
    local failed=0
    local index path

    for index in "${!FILES[@]}"; do
        path="${DEMO_DIR}/${FILES[$index]}"

        if [[ ! -s "${path}" ]]; then
            printf '[media] MISSING: docs/media/demos/%s\n' "${FILES[$index]}" >&2
            failed=1
            continue
        fi

        if php -r '
            $header = file_get_contents($argv[1], false, null, 0, 6);
            exit(in_array($header, ["GIF87a", "GIF89a"], true) ? 0 : 1);
        ' "${path}"; then
            info "Valid GIF: docs/media/demos/${FILES[$index]}"
        else
            printf '[media] INVALID GIF: docs/media/demos/%s\n' "${FILES[$index]}" >&2
            failed=1
        fi
    done

    [[ "${failed}" -eq 0 ]] || return 1
}

check_readme_references() {
    local index relative markdown

    for index in "${!FILES[@]}"; do
        relative="docs/media/demos/${FILES[$index]}"
        markdown="![${ALTS[$index]}](${relative})"

        if ! grep -Fq "${markdown}" "${README}"; then
            fail "README is missing expected embed: ${markdown}"
        fi
    done

    info "README contains all eight expected media references"
}

enable_readme_embeds() {
    check_gifs || fail "All eight non-empty GIFs are required before enabling README embeds"
    check_readme_references

    local index relative
    for index in "${!FILES[@]}"; do
        relative="docs/media/demos/${FILES[$index]}"

        php -r '
            $readme = $argv[1];
            $relative = $argv[2];
            $alt = $argv[3];
            $contents = (string) file_get_contents($readme);
            $markdown = "![".$alt."](".$relative.")";
            $wrapped = "<!-- Uncomment after recording ".$relative."\n".$markdown."\n-->";

            if (str_contains($contents, $wrapped)) {
                $contents = str_replace($wrapped, $markdown, $contents);
                file_put_contents($readme, $contents);
                exit(0);
            }

            if (str_contains($contents, $markdown)) {
                exit(0);
            }

            fwrite(STDERR, "Expected README block not found for ".$relative.PHP_EOL);
            exit(1);
        ' "${README}" "${relative}" "${ALTS[$index]}"

        info "Enabled README embed: ${relative}"
    done
}

usage() {
    cat <<'USAGE'
Usage: docs/media/publish-demos.sh <command>

Commands:
  check   Validate all eight GIF files and README references
  enable  Require all GIFs, then uncomment the eight README image embeds

The script does not commit or push changes.
USAGE
}

case "${1:-}" in
    check)
        check_gifs
        check_readme_references
        ;;
    enable)
        enable_readme_embeds
        ;;
    *)
        usage
        exit 1
        ;;
esac
