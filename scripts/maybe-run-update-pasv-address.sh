#!/bin/bash
#
# Gate update-pasv-address.sh on getDynamicDnsRefreshSeconds() (0 = disabled; min 60 when enabled).
# Run from root cron every minute; runs PASV script only after interval elapsed (state file).
#
# Throttle: STATE is updated only when update-pasv-address.sh exits 0 or 2 so exit 1
# (transient DNS or vsftpd errors) can retry on the next minute instead of waiting the full interval.
# State and wrapper log live under /var/lib/aviationwx (root-only, mode 700 in the image), not /tmp or
# /var/log/aviationwx, so www-data cannot swap the path for a symlink before root appends.
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
OUT="$(/var/www/html/scripts/update-pasv-address.sh 2>&1)"
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
