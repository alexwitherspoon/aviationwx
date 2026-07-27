#!/bin/bash
# Enable TLS in ProFTPD when Let's Encrypt certificates are available.
# Uses wildcard certificate (*.aviationwx.org) which covers upload.aviationwx.org

set -euo pipefail

DOMAIN="aviationwx.org"
CERT_DIR="/etc/letsencrypt/live/$DOMAIN"
PROFTPD_PID_FILE="/var/run/proftpd.pid"

log_message() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"
}

if [ ! -d "$CERT_DIR" ] || [ ! -f "$CERT_DIR/fullchain.pem" ] || [ ! -f "$CERT_DIR/privkey.pem" ]; then
    log_message "Certificates not found at $CERT_DIR"
    log_message "TLS will remain disabled until certificates are available"
    exit 0
fi

if [ ! -r "$CERT_DIR/fullchain.pem" ] || [ ! -r "$CERT_DIR/privkey.pem" ]; then
    log_message "ERROR: Certificate files exist but are not readable"
    exit 1
fi

if ! openssl x509 -in "$CERT_DIR/fullchain.pem" -noout -text >/dev/null 2>&1; then
    log_message "ERROR: SSL certificate file appears invalid"
    exit 1
fi

KEY_VALID=false
if openssl rsa -in "$CERT_DIR/privkey.pem" -check -noout >/dev/null 2>&1; then
    KEY_VALID=true
elif openssl rsa -in "$CERT_DIR/privkey.pem" -noout >/dev/null 2>&1; then
    KEY_VALID=true
elif openssl pkey -in "$CERT_DIR/privkey.pem" -noout >/dev/null 2>&1; then
    KEY_VALID=true
fi

if [ "$KEY_VALID" = false ]; then
    log_message "ERROR: SSL private key file appears invalid"
    exit 1
fi

if [ -x /usr/local/bin/php ]; then
    APP_PHP=/usr/local/bin/php
else
    APP_PHP="${APP_PHP:-php}"
fi

REFRESH_SCRIPT="/var/www/html/scripts/refresh-upload-endpoints.php"
if [ ! -f "$REFRESH_SCRIPT" ]; then
    log_message "ERROR: refresh-upload-endpoints.php not found"
    exit 1
fi

if ! CONFIG_PATH="${CONFIG_PATH:-/var/www/html/config/airports.json}" "$APP_PHP" "$REFRESH_SCRIPT" --no-reload; then
    log_message "ERROR: Failed to sync ProFTPD TLS configuration from upload capabilities"
    exit 1
fi

log_message "ProFTPD TLS configuration synced from upload capabilities"

if [ -f "$PROFTPD_PID_FILE" ]; then
    pid="$(tr -d '[:space:]' <"$PROFTPD_PID_FILE")"
    if [[ "$pid" =~ ^[0-9]+$ ]] && kill -HUP "$pid" 2>/dev/null; then
        log_message "ProFTPD reloaded to apply TLS configuration"
        exit 0
    fi
fi

log_message "ProFTPD not running; TLS config will apply on next container start"
exit 0
