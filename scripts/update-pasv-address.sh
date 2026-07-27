#!/bin/bash
#
# update-pasv-address.sh - Check and update ProFTPD MasqueradeAddress for dynamic DNS
#
# Invoked by maybe-run-update-pasv-address.sh (root cron) when dynamic_dns_refresh_seconds is enabled.
# Config and structured logging use PHP as www-data (runuser) so root never executes app-tree PHP.
# Root is used only for runtime.conf edits and ProFTPD SIGHUP reload.
#
# Usage: update-pasv-address.sh [--force]
#   --force: Update even if IP hasn't changed (useful for testing)
#
# Exit codes:
#   0 - Success (no change needed or update successful)
#   1 - Error (could not resolve DNS or update failed)
#   2 - ProFTPD not running (skipped)

set -euo pipefail

PROFTPD_RUNTIME_CONF="/etc/proftpd/conf.d/runtime.conf"
CONFIG_FILE="${CONFIG_PATH:-/var/www/html/config/airports.json}"
LOG_PREFIX="[dynamic-dns]"

if [ -x /usr/local/bin/php ]; then
    APP_PHP=/usr/local/bin/php
else
    APP_PHP="${APP_PHP:-php}"
fi

php_as_www_data() {
    local -a cmd
    if command -v runuser >/dev/null 2>&1 && [ "$(id -u)" -eq 0 ]; then
        cmd=(runuser -u www-data -- env "CONFIG_PATH=${CONFIG_PATH:-/var/www/html/config/airports.json}" "$APP_PHP" "$@")
    else
        cmd=(env "CONFIG_PATH=${CONFIG_PATH:-/var/www/html/config/airports.json}" "$APP_PHP" "$@")
    fi
    "${cmd[@]}"
}

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
    log_warning "ProFTPD is not running - skipping PASV address update"
    exit 2
fi

if [[ ! -f "$CONFIG_FILE" ]]; then
    log_error "Config file not found: $CONFIG_FILE"
    exit 1
fi

read_public_ip_from_config() {
    php_as_www_data -r 'require_once "/var/www/html/lib/config.php"; echo (string) (getPublicIP() ?? "");' 2>/dev/null || echo ""
}

read_upload_hostname_from_config() {
    php_as_www_data -r 'require_once "/var/www/html/lib/config.php"; echo (string) (getUploadHostname() ?? "");' 2>/dev/null || echo ""
}

log_pasv_address_change_event() {
    local old_ip="$1" new_ip="$2" host="$3"
    local php_code='require_once "/var/www/html/lib/logger.php"; aviationwx_log("info", "Dynamic DNS: PASV address updated", ["old_ip" => (string) (getenv("AVWX_OLD_IP") ?: ""), "new_ip" => (string) (getenv("AVWX_NEW_IP") ?: ""), "hostname" => (string) (getenv("AVWX_HOST") ?: "")], "app");'
    if command -v runuser >/dev/null 2>&1 && [ "$(id -u)" -eq 0 ]; then
        runuser -u www-data -- env \
            "CONFIG_PATH=${CONFIG_PATH:-/var/www/html/config/airports.json}" \
            "AVWX_OLD_IP=${old_ip}" \
            "AVWX_NEW_IP=${new_ip}" \
            "AVWX_HOST=${host}" \
            "$APP_PHP" -r "$php_code" 2>/dev/null || true
    else
        env \
            "CONFIG_PATH=${CONFIG_PATH:-/var/www/html/config/airports.json}" \
            "AVWX_OLD_IP=${old_ip}" \
            "AVWX_NEW_IP=${new_ip}" \
            "AVWX_HOST=${host}" \
            "$APP_PHP" -r "$php_code" 2>/dev/null || true
    fi
}

PUBLIC_IP=$(read_public_ip_from_config)
if [[ -n "$PUBLIC_IP" ]]; then
    log_info "Static public_ip is configured ($PUBLIC_IP) - dynamic DNS refresh not needed"
    exit 0
fi

UPLOAD_HOSTNAME=$(read_upload_hostname_from_config)
if [[ -z "$UPLOAD_HOSTNAME" ]]; then
    log_error "Could not determine upload hostname from config"
    exit 1
fi

CURRENT_PASV=""
if [[ -f "$PROFTPD_RUNTIME_CONF" ]]; then
    CURRENT_PASV=$(grep -E "^MasqueradeAddress[[:space:]]+" "$PROFTPD_RUNTIME_CONF" 2>/dev/null | awk '{print $2}' || echo "")
fi

# Hostname MasqueradeAddress resolves at PASV time; no periodic literal-IP refresh needed.
if [[ -n "$CURRENT_PASV" ]] && [[ ! "$CURRENT_PASV" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    log_info "Using MasqueradeAddress hostname ($CURRENT_PASV) - no periodic update needed"
    exit 0
fi

NEW_IP=""
if [[ -f "/usr/local/bin/resolve-upload-ip.sh" ]]; then
    NEW_IP=$(/usr/local/bin/resolve-upload-ip.sh "$UPLOAD_HOSTNAME" "ipv4" 2>&1 | grep -E '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$' | head -1 || echo "")
elif command -v dig > /dev/null 2>&1; then
    NEW_IP=$(dig +short "$UPLOAD_HOSTNAME" A 2>/dev/null | grep -E '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$' | head -1 || echo "")
fi

if [[ -z "$NEW_IP" ]]; then
    log_error "Failed to resolve hostname: $UPLOAD_HOSTNAME"
    exit 1
fi

if [[ "$CURRENT_PASV" == "$NEW_IP" ]] && [[ "$FORCE_UPDATE" != "true" ]]; then
    log_info "PASV address unchanged ($NEW_IP) - no update needed"
    exit 0
fi

log_info "PASV address change detected: $CURRENT_PASV -> $NEW_IP"

if [[ ! -f "$PROFTPD_RUNTIME_CONF" ]]; then
    log_error "ProFTPD runtime config not found: $PROFTPD_RUNTIME_CONF"
    exit 1
fi

if grep -qE "^MasqueradeAddress[[:space:]]+" "$PROFTPD_RUNTIME_CONF"; then
    sed -i "s|^MasqueradeAddress[[:space:]].*|MasqueradeAddress               ${NEW_IP}|" "$PROFTPD_RUNTIME_CONF"
else
    echo "MasqueradeAddress               ${NEW_IP}" >>"$PROFTPD_RUNTIME_CONF"
fi

log_info "Updated runtime.conf with MasqueradeAddress: $NEW_IP"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
COMMON_SH="${SCRIPT_DIR}/upload-daemon-common.sh"
if [ ! -f "$COMMON_SH" ]; then
    COMMON_SH="/usr/local/libexec/aviationwx/upload-daemon-common.sh"
fi
if [[ -f "$COMMON_SH" ]]; then
    # shellcheck source=/dev/null
    source "$COMMON_SH"
    WATCHDOG_LOG_FILE="/var/lib/aviationwx/dynamic-dns-pasv.log"
    if reload_proftpd_daemon "masquerade_address update"; then
        log_info "ProFTPD reloaded with new MasqueradeAddress: $NEW_IP"
        if [[ -n "$CURRENT_PASV" ]]; then
            log_pasv_address_change_event "$CURRENT_PASV" "$NEW_IP" "$UPLOAD_HOSTNAME"
        fi
        exit 0
    fi
    log_error "Failed to reload ProFTPD after MasqueradeAddress update"
    exit 1
fi

log_error "upload-daemon-common.sh not found; cannot reload ProFTPD safely"
exit 1
