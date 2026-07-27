#!/bin/bash
#
# update-pasv-address.sh - Refresh upload endpoint cache and reload ProFTPD
#
# Invoked by maybe-run-update-pasv-address.sh (root cron) when dynamic DNS refresh is enabled.
# Config and structured logging use PHP as www-data (runuser) so root never executes app-tree PHP.
# Root is used only for ProFTPD SIGHUP reload after endpoint cache update.
#
# Usage: update-pasv-address.sh [--force]
#   --force: Refresh even if endpoints unchanged (useful for testing)
#
# Exit codes:
#   0 - Success (no change needed or update successful)
#   1 - Error (could not resolve DNS or update failed)
#   2 - ProFTPD not running (skipped)

set -euo pipefail

CONFIG_FILE="${CONFIG_PATH:-/var/www/html/config/airports.json}"
LOG_PREFIX="[dynamic-dns]"

if [ -x /usr/local/bin/php ]; then
    APP_PHP=/usr/local/bin/php
else
    APP_PHP="${APP_PHP:-php}"
fi

FORCE_UPDATE=false
if [[ "${1:-}" == "--force" ]]; then
    FORCE_UPDATE=true
fi

log_info() {
    echo "$LOG_PREFIX [INFO] $*"
}

log_warning() {
    echo "$LOG_PREFIX [WARNING] $*" >&2
}

log_error() {
    echo "$LOG_PREFIX [ERROR] $*" >&2
}

if ! pgrep -x proftpd > /dev/null 2>&1; then
    log_warning "ProFTPD is not running - skipping upload endpoint refresh"
    exit 2
fi

if [[ ! -f "$CONFIG_FILE" ]]; then
    log_error "Config file not found: $CONFIG_FILE"
    exit 1
fi

REFRESH_SCRIPT="/var/www/html/scripts/refresh-upload-endpoints.php"
if [ ! -f "$REFRESH_SCRIPT" ]; then
    REFRESH_SCRIPT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/refresh-upload-endpoints.php"
fi

if [ ! -f "$REFRESH_SCRIPT" ]; then
    log_error "refresh-upload-endpoints.php not found"
    exit 1
fi

PREVIOUS_JSON=""
if [[ "$FORCE_UPDATE" != "true" ]] && [ -f /var/lib/aviationwx/upload-endpoints.json ]; then
    PREVIOUS_JSON="$(cat /var/lib/aviationwx/upload-endpoints.json 2>/dev/null || true)"
fi

set +e
OUT="$(CONFIG_PATH="$CONFIG_FILE" "$APP_PHP" "$REFRESH_SCRIPT" 2>&1)"
RC=$?
set -e

if [ "$RC" -ne 0 ]; then
    log_error "Upload endpoint refresh failed"
    printf '%s\n' "$OUT" >&2
    exit 1
fi

CURRENT_JSON=""
if [ -f /var/lib/aviationwx/upload-endpoints.json ]; then
    CURRENT_JSON="$(cat /var/lib/aviationwx/upload-endpoints.json 2>/dev/null || true)"
fi

if [[ "$FORCE_UPDATE" != "true" ]] && [ "$PREVIOUS_JSON" = "$CURRENT_JSON" ] && [ -n "$CURRENT_JSON" ]; then
    log_info "Upload endpoints unchanged - no reload needed"
    exit 0
fi

log_info "Upload endpoints refreshed"
printf '%s\n' "$OUT"

exit 0
