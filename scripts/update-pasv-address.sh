#!/bin/bash
#
# update-pasv-address.sh - Check and update vsftpd pasv_address for dynamic DNS
#
# This script is called by the scheduler when dynamic_dns_refresh_seconds is enabled.
# It resolves the upload hostname, compares with current pasv_address, and restarts
# vsftpd if the IP has changed.
#
# Usage: update-pasv-address.sh [--force]
#   --force: Update even if IP hasn't changed (useful for testing)
#
# Exit codes:
#   0 - Success (no change needed or update successful)
#   1 - Error (could not resolve DNS or update failed)
#   2 - vsftpd not running (skipped)

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
VSFTPD_CONF="/etc/vsftpd/vsftpd.conf"
CONFIG_FILE="${CONFIG_PATH:-/var/www/html/config/airports.json}"
LOG_PREFIX="[dynamic-dns]"

# Parse arguments
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

# Check if vsftpd is running
if ! pgrep -x vsftpd > /dev/null 2>&1; then
    log_warning "vsftpd is not running - skipping PASV address update"
    exit 2
fi

# Check if config file exists
if [[ ! -f "$CONFIG_FILE" ]]; then
    log_error "Config file not found: $CONFIG_FILE"
    exit 1
fi

# Get configuration values using PHP
get_config_value() {
    local key="$1"
    php -r "
        require_once '/var/www/html/lib/config.php';
        echo $key() ?? '';
    " 2>/dev/null || echo ""
}

# Check if dynamic DNS is enabled (returns 0 if public_ip is set)
PUBLIC_IP=$(get_config_value "getPublicIP")
if [[ -n "$PUBLIC_IP" ]]; then
    log_info "Static public_ip is configured ($PUBLIC_IP) - dynamic DNS refresh not needed"
    exit 0
fi

# Get the upload hostname to resolve
UPLOAD_HOSTNAME=$(get_config_value "getUploadHostname")
if [[ -z "$UPLOAD_HOSTNAME" ]]; then
    log_error "Could not determine upload hostname from config"
    exit 1
fi

# Get current pasv_address from vsftpd.conf
CURRENT_PASV=""
if [[ -f "$VSFTPD_CONF" ]]; then
    CURRENT_PASV=$(grep -E "^pasv_address=" "$VSFTPD_CONF" 2>/dev/null | cut -d= -f2 || echo "")
fi

# Resolve the hostname to get the new IP
NEW_IP=""
if [[ -f "/usr/local/bin/resolve-upload-ip.sh" ]]; then
    NEW_IP=$(/usr/local/bin/resolve-upload-ip.sh "$UPLOAD_HOSTNAME" "ipv4" 2>&1 | grep -E '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$' | head -1 || echo "")
else
    # Fallback to dig if resolve script not available
    if command -v dig > /dev/null 2>&1; then
        NEW_IP=$(dig +short "$UPLOAD_HOSTNAME" A 2>/dev/null | grep -E '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$' | head -1 || echo "")
    elif command -v host > /dev/null 2>&1; then
        NEW_IP=$(host -t A "$UPLOAD_HOSTNAME" 2>/dev/null | grep -oE '[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+' | head -1 || echo "")
    elif command -v getent > /dev/null 2>&1; then
        NEW_IP=$(getent ahosts "$UPLOAD_HOSTNAME" 2>/dev/null | grep -E '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+' | awk '{print $1}' | head -1 || echo "")
    fi
fi

if [[ -z "$NEW_IP" ]]; then
    log_error "Failed to resolve hostname: $UPLOAD_HOSTNAME"
    exit 1
fi

# Compare IPs
if [[ "$CURRENT_PASV" == "$NEW_IP" ]] && [[ "$FORCE_UPDATE" != "true" ]]; then
    log_info "PASV address unchanged ($NEW_IP) - no update needed"
    exit 0
fi

# IP has changed - update vsftpd.conf
log_info "PASV address change detected: $CURRENT_PASV -> $NEW_IP"

if [[ ! -f "$VSFTPD_CONF" ]]; then
    log_error "vsftpd config not found: $VSFTPD_CONF"
    exit 1
fi

# Update the pasv_address in vsftpd.conf
if grep -q "^pasv_address=" "$VSFTPD_CONF"; then
    sed -i "s|^pasv_address=.*|pasv_address=$NEW_IP|" "$VSFTPD_CONF"
else
    echo "pasv_address=$NEW_IP" >> "$VSFTPD_CONF"
fi

log_info "Updated vsftpd.conf with new pasv_address: $NEW_IP"

# Restart vsftpd to pick up the new config
# vsftpd doesn't support config reload via SIGHUP, so we need to restart
log_info "Restarting vsftpd to apply new pasv_address..."

# Get vsftpd PID and restart it
VSFTPD_PID=$(pgrep -x vsftpd || echo "")
if [[ -n "$VSFTPD_PID" ]]; then
    # Kill existing vsftpd
    kill "$VSFTPD_PID" 2>/dev/null || true
    sleep 1
    
    # Wait for it to stop (max 5 seconds)
    for i in {1..5}; do
        if ! pgrep -x vsftpd > /dev/null 2>&1; then
            break
        fi
        sleep 1
    done
    
    # Force kill if still running
    if pgrep -x vsftpd > /dev/null 2>&1; then
        pkill -9 -x vsftpd 2>/dev/null || true
        sleep 1
    fi
fi

# Start vsftpd
/usr/sbin/vsftpd /etc/vsftpd/vsftpd.conf &
sleep 2

# Verify vsftpd is running
if pgrep -x vsftpd > /dev/null 2>&1; then
    log_info "vsftpd restarted successfully with new pasv_address: $NEW_IP"
    
    # Log the change for monitoring
    if [[ -n "$CURRENT_PASV" ]]; then
        php -r "
            require_once '/var/www/html/lib/logger.php';
            aviationwx_log('info', 'Dynamic DNS: PASV address updated', [
                'old_ip' => '$CURRENT_PASV',
                'new_ip' => '$NEW_IP',
                'hostname' => '$UPLOAD_HOSTNAME'
            ], 'app');
        " 2>/dev/null || true
    fi
    
    exit 0
else
    log_error "Failed to restart vsftpd after PASV address update"
    exit 1
fi
