#!/bin/bash
#
# Gate update-pasv-address.sh on getDynamicDnsRefreshSeconds() (0 = disabled; min 60 when enabled).
# Run from root cron every minute; runs PASV script only after interval elapsed (state file).
#
# Requires CONFIG_PATH (see /etc/cron.d/aviationwx-cron). Runs as root so vsftpd.conf
# edits and vsftpd restart succeed.

set -euo pipefail

cd /var/www/html

export CONFIG_PATH="${CONFIG_PATH:-/var/www/html/config/airports.json}"

INTERVAL="$(
    /usr/local/bin/php -r 'require_once "/var/www/html/lib/config.php"; echo (int) getDynamicDnsRefreshSeconds();' 2>/dev/null || echo 0
)"
INTERVAL="$(printf '%s' "${INTERVAL}" | tr -d '[:space:]')"

if ! [[ "${INTERVAL}" =~ ^[0-9]+$ ]]; then
    INTERVAL=0
fi

if [ "${INTERVAL}" -eq 0 ]; then
    exit 0
fi

STATE=/tmp/aviationwx-pasv-ddns.last
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

LOG=/var/log/aviationwx/dynamic-dns-pasv.log
mkdir -p "$(dirname "${LOG}")" 2>/dev/null || true

set +e
OUT="$(/var/www/html/scripts/update-pasv-address.sh 2>&1)"
RC=$?
set -e

{
    echo "===== $(date -u +"%Y-%m-%dT%H:%M:%SZ") maybe-run-update-pasv-address (interval=${INTERVAL}s) ====="
    printf '%s\n' "${OUT}"
    echo "exit_code=${RC}"
    echo
} >>"${LOG}" 2>&1 || true

echo "${NOW}" >"${STATE}"

exit "${RC}"
