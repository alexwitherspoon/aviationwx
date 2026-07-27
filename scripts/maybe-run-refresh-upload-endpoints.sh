#!/bin/bash
#
# Gate refresh-upload-endpoints.php on getEffectiveUploadEndpointRefreshSeconds() (0 = disabled).
# Run from root cron every minute; refreshes upload endpoint cache after interval elapsed.
#
# Throttle: STATE is written only after refresh exits 0 or 2; exit 1 retries on the next minute.
# STATE and log: /var/lib/aviationwx (mode 700, root-owned in the image).
#
# Requires CONFIG_PATH (see /etc/cron.d/aviationwx-cron).

set -euo pipefail

THIS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

cd /var/www/html

export CONFIG_PATH="${CONFIG_PATH:-/var/www/html/config/airports.json}"

if [ -x /usr/local/bin/php ]; then
    APP_PHP=/usr/local/bin/php
else
    APP_PHP=php
fi

INTERVAL="$(
    runuser -u www-data -- "$APP_PHP" -r 'require_once "/var/www/html/lib/config.php"; echo (int) getEffectiveUploadEndpointRefreshSeconds();' 2>/dev/null || echo 0
)"
INTERVAL="$(printf '%s' "${INTERVAL}" | tr -d '[:space:]')"

if ! [[ "${INTERVAL}" =~ ^[0-9]+$ ]]; then
    INTERVAL=0
fi

if [ "${INTERVAL}" -eq 0 ]; then
    exit 0
fi

STATE=/var/lib/aviationwx/upload-endpoints-refresh.last
LEGACY_STATE=/var/lib/aviationwx/pasv-ddns.last
if [ ! -f "${STATE}" ] && [ -f "${LEGACY_STATE}" ]; then
    cp "${LEGACY_STATE}" "${STATE}" 2>/dev/null || true
fi

NOW="$(date +%s)"
LAST=0
if [ -f "${STATE}" ]; then
    LAST="$(cat "${STATE}" 2>/dev/null || echo 0)"
fi
if ! [[ "${LAST}" =~ ^[0-9]+$ ]]; then
    LAST=0
fi

ELAPSED=$((NOW - LAST))
if [ "${ELAPSED}" -lt "${INTERVAL}" ]; then
    exit 0
fi

if ! pgrep -x proftpd >/dev/null 2>&1; then
    exit 2
fi

REFRESH_SCRIPT="/var/www/html/scripts/refresh-upload-endpoints.php"
if [ ! -f "$REFRESH_SCRIPT" ]; then
    REFRESH_SCRIPT="${THIS_DIR}/refresh-upload-endpoints.php"
fi
if [ ! -f "$REFRESH_SCRIPT" ]; then
    echo "maybe-run-refresh-upload-endpoints: refresh-upload-endpoints.php not found" >&2
    exit 1
fi

LOG=/var/lib/aviationwx/upload-endpoints-refresh.log
mkdir -p "$(dirname "${LOG}")" 2>/dev/null || true

set +e
OUT="$(CONFIG_PATH="$CONFIG_PATH" "$APP_PHP" "$REFRESH_SCRIPT" 2>&1)"
RC=$?
set -e

{
    echo "===== $(date -u +"%Y-%m-%dT%H:%M:%SZ") maybe-run-refresh-upload-endpoints (interval=${INTERVAL}s) ====="
    printf '%s\n' "${OUT}"
    echo "exit_code=${RC}"
    echo
} >>"${LOG}" 2>&1 || true

if [ "${RC}" -eq 0 ] || [ "${RC}" -eq 2 ]; then
    echo "${NOW}" >"${STATE}"
fi

exit "${RC}"
