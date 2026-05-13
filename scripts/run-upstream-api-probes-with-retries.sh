#!/usr/bin/env bash
#
# Run UpstreamApiProbeTest (live HTTPS) via PHPUnit with optional exponential backoff retries.
# Used by make test-external-apis (single attempt by default) and by scheduled GitHub Actions.
#
# Usage:
#   scripts/run-upstream-api-probes-with-retries.sh [CONFIG_JSON_PATH]
#
# Arguments:
#   CONFIG_JSON_PATH  Path to airports-style JSON (default: repo config/airports.json.example).
#
# Environment:
#   UPSTREAM_PROBE_MAX_ATTEMPTS       Default 1 (local). CI sets 5.
#   UPSTREAM_PROBE_INITIAL_BACKOFF_SEC  Seconds to sleep before attempt 2 (default 30).
#   UPSTREAM_PROBE_MAX_BACKOFF_SEC    Cap for sleep between attempts (default 300).
#

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

CONFIG_FILE="${1:-$ROOT/config/airports.json.example}"
if [[ ! -f "$CONFIG_FILE" ]]; then
    echo "error: config file not found: $CONFIG_FILE" >&2
    exit 1
fi

export CONFIG_PATH="$CONFIG_FILE"

MAX_ATTEMPTS="${UPSTREAM_PROBE_MAX_ATTEMPTS:-1}"
INITIAL_BACKOFF="${UPSTREAM_PROBE_INITIAL_BACKOFF_SEC:-30}"
MAX_BACKOFF="${UPSTREAM_PROBE_MAX_BACKOFF_SEC:-300}"

if ! [[ "$MAX_ATTEMPTS" =~ ^[1-9][0-9]*$ ]]; then
    echo "error: UPSTREAM_PROBE_MAX_ATTEMPTS must be a positive integer (got: $MAX_ATTEMPTS)" >&2
    exit 1
fi

if ! [[ "$INITIAL_BACKOFF" =~ ^[0-9]+$ ]] || ! [[ "$MAX_BACKOFF" =~ ^[0-9]+$ ]]; then
    echo "error: backoff seconds must be non-negative integers" >&2
    exit 1
fi

if (( INITIAL_BACKOFF > MAX_BACKOFF )); then
    echo "error: UPSTREAM_PROBE_INITIAL_BACKOFF_SEC must be <= UPSTREAM_PROBE_MAX_BACKOFF_SEC" >&2
    exit 1
fi

sleep_sec="$INITIAL_BACKOFF"
attempt=1

while (( attempt <= MAX_ATTEMPTS )); do
    echo "Upstream API probes: attempt ${attempt}/${MAX_ATTEMPTS} (CONFIG_PATH=${CONFIG_PATH})"
    if vendor/bin/phpunit -c phpunit.external-apis.xml \
        --testsuite UpstreamApiProbes \
        --testdox \
        --no-coverage; then
        echo "Upstream API probes passed on attempt ${attempt}"
        exit 0
    fi

    if (( attempt == MAX_ATTEMPTS )); then
        echo "Upstream API probes failed after ${MAX_ATTEMPTS} attempt(s)" >&2
        exit 1
    fi

    echo "Waiting ${sleep_sec}s before retry (exponential backoff, cap ${MAX_BACKOFF}s)..."
    sleep "$sleep_sec"
    next_sleep=$((sleep_sec * 2))
    if (( next_sleep > MAX_BACKOFF )); then
        sleep_sec="$MAX_BACKOFF"
    else
        sleep_sec="$next_sleep"
    fi
    attempt=$((attempt + 1))
done
