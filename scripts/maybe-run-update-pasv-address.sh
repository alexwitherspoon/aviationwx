#!/bin/bash
#
# Gate update-pasv-address.sh on getDynamicDnsRefreshSeconds() (0 = disabled; min 60 when enabled).
# Run from root cron every minute; runs PASV script only after interval elapsed (state file).
#
# Throttle: STATE is written only after update-pasv-address.sh exits 0 or 2; exit 1 retries on the next minute.
# STATE and wrapper log: /var/lib/aviationwx (mode 700, root-owned in the image) so unprivileged users cannot pre-create those paths as symlinks.
#
# Requires CONFIG_PATH (see /etc/cron.d/aviationwx-cron). Root edits vsftpd.conf and restarts vsftpd.
# Interval: PHP CLI as user www-data via runuser(8), reading CONFIG_PATH.

set -euo pipefail

THIS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

cd /var/www/html

export CONFIG_PATH="${CONFIG_PATH:-/var/www/html/config/airports.json}"

INTERVAL="$(
    runuser -u www-data -- /usr/local/bin/php -r 'require_once "/var/www/html/lib/config.php"; echo (int) getDynamicDnsRefreshSeconds();' 2>/dev/null || echo 0
)"
INTERVAL="$(printf '%s' "${INTERVAL}" | tr -d '[:space:]')"

if ! [[ "${INTERVAL}" =~ ^[0-9]+$ ]]; then
    INTERVAL=0
fi

if [ "${INTERVAL}" -eq 0 ]; then
    exit 0
fi

STATE=/var/lib/aviationwx/pasv-ddns.last
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

LOG=/var/lib/aviationwx/dynamic-dns-pasv.log
mkdir -p "$(dirname "${LOG}")" 2>/dev/null || true

set +e
OUT="$("${THIS_DIR}/update-pasv-address.sh" 2>&1)"
RC=$?
set -e

{
    echo "===== $(date -u +"%Y-%m-%dT%H:%M:%SZ") maybe-run-update-pasv-address (interval=${INTERVAL}s) ====="
    printf '%s\n' "${OUT}"
    echo "exit_code=${RC}"
    echo
} >>"${LOG}" 2>&1 || true

# Exit 0: ok. Exit 2: vsftpd not running (skip). Exit 1: error; leave STATE unchanged for sooner retry.
if [ "${RC}" -eq 0 ] || [ "${RC}" -eq 2 ]; then
    echo "${NOW}" >"${STATE}"
fi

exit "${RC}"
