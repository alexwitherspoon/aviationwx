#!/usr/bin/env bash
# Host CD wrapper: pause ProcessPool work before container recreate.
# Runs deploy-drain.php inside the live web container (host has no PHP).
# Apache stays serving. Markers live on the shared cache volume.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

# Space-separated compose files (prod default; local may add docker-compose.override.yml).
COMPOSE_FILE="${COMPOSE_FILE:-docker/docker-compose.prod.yml}"
COMPOSE=(docker compose)
# shellcheck disable=SC2086
for _compose_file in ${COMPOSE_FILE}; do
  COMPOSE+=(-f "${_compose_file}")
done
WEB_SERVICE="${WEB_SERVICE:-web}"
# Container path for the shared cache mount (host: AVIATIONWX_CACHE_DIR / /tmp/aviationwx-cache).
CONTAINER_CACHE_DIR="${CONTAINER_CACHE_DIR:-/var/www/html/cache}"

if [ ! -f "${ROOT_DIR}/scripts/deploy-drain.php" ]; then
  echo "⚠️  deploy-drain.php missing - skipping worker drain"
  exit 0
fi

if ! command -v docker >/dev/null 2>&1; then
  echo "⚠️  docker not available - skipping worker drain"
  exit 0
fi

if ! "${COMPOSE[@]}" ps "$WEB_SERVICE" 2>/dev/null | grep -q "Up"; then
  echo "✓ Web container not running - skip worker drain"
  exit 0
fi

run_in_web() {
  "${COMPOSE[@]}" exec -T "$WEB_SERVICE" "$@" < /dev/null
}

MAX_WAIT="$(
  run_in_web php -r 'require "/var/www/html/lib/constants.php"; echo (int) DEPLOY_WORKER_DRAIN_MAX_SECONDS;'
)"
if ! [[ "${MAX_WAIT}" =~ ^[0-9]+$ ]] || [ "${MAX_WAIT}" -lt 1 ]; then
  echo "⚠️  Could not read DEPLOY_WORKER_DRAIN_MAX_SECONDS from container - skipping worker drain"
  exit 0
fi

echo "Requesting scheduler deploy drain via web container (Apache stays up)..."
if ! run_in_web php scripts/deploy-drain.php request --cache-dir="$CONTAINER_CACHE_DIR"; then
  echo "⚠️  Failed to write drain flag - continuing deploy"
  exit 0
fi

echo "Waiting for in-flight ProcessPool workers (max ${MAX_WAIT}s + grace)..."
set +e
run_in_web php scripts/deploy-drain.php wait --cache-dir="$CONTAINER_CACHE_DIR" --max-wait="${MAX_WAIT}"
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
