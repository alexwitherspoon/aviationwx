#!/bin/bash
#
# Repair FTP push upload inbox ownership (ProFTPD DefaultChdir homedirs).
#
# Required layout:
#   FTP_DIR/                      root:root 755
#   FTP_DIR/{airport}/{username}/ ftp:www-data 2775 (setgid)
#
# Invoked from set-cache-permissions.sh (nightly), sync-push-config.php (every run).
# Idempotent. Must run as root.
#
# Usage: repair-ftp-upload-permissions.sh
# Environment: FTP_DIR (default: /var/www/html/cache/ftp when present, else ${PROJECT_ROOT}/cache/ftp)
#

set -uo pipefail

if [ "$(id -u)" -ne 0 ]; then
    echo "repair-ftp-upload: error: must run as root" >&2
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

if [ -d "/var/www/html/cache/ftp" ]; then
    _default_ftp="/var/www/html/cache/ftp"
else
    _default_ftp="${PROJECT_ROOT}/cache/ftp"
fi

FTP_DIR="${FTP_DIR:-${_default_ftp}}"

FTP_UID=$(id -u ftp 2>/dev/null || echo "101")
if command -v getent >/dev/null 2>&1; then
    WWW_DATA_GID=$(getent group www-data | cut -d: -f3 || echo "33")
else
    WWW_DATA_GID=33
fi

failures=0

repair_inbox_dir() {
    local inbox_dir="$1"
    local label="$2"

    if [ ! -d "$inbox_dir" ]; then
        return 0
    fi

    chown "${FTP_UID}:${WWW_DATA_GID}" "$inbox_dir" 2>/dev/null || {
        echo "repair-ftp-upload: warning: could not chown ftp:www-data ${label}" >&2
        return 1
    }
    chmod 2775 "$inbox_dir" 2>/dev/null || true
    echo "repair-ftp-upload: ok ${label} (ftp:www-data 2775)"
    return 0
}

if [ ! -d "$FTP_DIR" ]; then
    echo "repair-ftp-upload: skip missing FTP_DIR ${FTP_DIR}"
    exit 0
fi

chown root:root "$FTP_DIR" 2>/dev/null || {
    echo "repair-ftp-upload: warning: could not chown root:root ${FTP_DIR}" >&2
    failures=$((failures + 1))
}
chmod 755 "$FTP_DIR" 2>/dev/null || true

shopt -s nullglob
for airport_dir in "${FTP_DIR}"/*/; do
    [ -d "$airport_dir" ] || continue
    airport=$(basename "$airport_dir")
    if ! echo "$airport" | grep -qE '^[a-zA-Z0-9_-]{1,64}$'; then
        echo "repair-ftp-upload: skip non-airport dir ${airport}"
        continue
    fi
    chmod 755 "$airport_dir" 2>/dev/null || true
    for inbox_dir in "${airport_dir}"*/; do
        [ -d "$inbox_dir" ] || continue
        username=$(basename "$inbox_dir")
        if ! echo "$username" | grep -qE '^[a-zA-Z0-9]{1,14}$'; then
            echo "repair-ftp-upload: skip non-user dir ${airport}/${username}"
            continue
        fi
        repair_inbox_dir "$inbox_dir" "${airport}/${username}" || failures=$((failures + 1))
    done
done
shopt -u nullglob

if [ "$failures" -gt 0 ]; then
    echo "repair-ftp-upload: finished with ${failures} failure(s)" >&2
    exit 1
fi

echo "repair-ftp-upload: done (${FTP_DIR})"
exit 0
