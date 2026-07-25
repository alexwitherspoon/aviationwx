#!/usr/bin/env bash
# Pause new scheduler ProcessPool work and wait before container recreate.
# Apache stays serving. Markers: AVIATIONWX_CACHE_DIR (default /tmp/aviationwx-cache).
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

CACHE_DIR="${AVIATIONWX_CACHE_DIR:-/tmp/aviationwx-cache}"
COMPOSE_FILE="${COMPOSE_FILE:-docker/docker-compose.prod.yml}"
PHP_BIN="${PHP_BIN:-php}"
DRAIN_CLI="${ROOT_DIR}/scripts/deploy-drain.php"
CONSTANTS_PHP="${ROOT_DIR}/lib/constants.php"

if [ ! -f "$DRAIN_CLI" ]; then
  echo "⚠️  deploy-drain.php missing - skipping worker drain"
  exit 0
fi

if ! command -v "$PHP_BIN" >/dev/null 2>&1; then
  echo "⚠️  php not available on host - skipping worker drain"
  exit 0
fi

if [ ! -d "$CACHE_DIR" ]; then
  echo "⚠️  Cache dir missing (${CACHE_DIR}) - skipping worker drain"
  exit 0
fi

if ! docker compose -f "$COMPOSE_FILE" ps web --status running -q 2>/dev/null | grep -q .; then
  echo "✓ Web container not running - skip worker drain"
  exit 0
fi

MAX_WAIT="$("$PHP_BIN" -r "require '${CONSTANTS_PHP}'; echo (int) DEPLOY_WORKER_DRAIN_MAX_SECONDS;")"
if ! [[ "${MAX_WAIT}" =~ ^[0-9]+$ ]] || [ "${MAX_WAIT}" -lt 1 ]; then
  echo "⚠️  Could not read DEPLOY_WORKER_DRAIN_MAX_SECONDS - skipping worker drain"
  exit 0
fi

echo "Requesting scheduler deploy drain (Apache stays up)..."
if ! "$PHP_BIN" "$DRAIN_CLI" request --cache-dir="$CACHE_DIR"; then
  echo "⚠️  Failed to write drain flag - continuing deploy"
  exit 0
fi

echo "Waiting for in-flight ProcessPool workers (max ${MAX_WAIT}s + grace)..."
set +e
"$PHP_BIN" "$DRAIN_CLI" wait --cache-dir="$CACHE_DIR" --max-wait="${MAX_WAIT}"
WAIT_RC=$?
set -e

if [ "$WAIT_RC" -eq 0 ]; then
  echo "✓ Worker drain complete"
elif [ "$WAIT_RC" -eq 2 ]; then
  echo "⚠️  Worker drain wait timed out - proceeding with container recreate"
else
  echo "⚠️  Worker drain wait failed (rc=${WAIT_RC}) - proceeding with container recreate"
fi

exit 0
