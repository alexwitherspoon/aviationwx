#!/bin/bash
# Certbot Deploy Hook for AviationWX
#
# This script runs after certbot successfully renews the SSL certificate.
# It copies the renewed certificate to the deployment directory and reloads services.
#
# Installation (run on production host):
#   sudo cp scripts/certbot-deploy-hook.sh /etc/letsencrypt/renewal-hooks/deploy/
#   sudo chmod +x /etc/letsencrypt/renewal-hooks/deploy/certbot-deploy-hook.sh
#
# The host's systemd certbot.timer handles automatic renewal twice daily.
# This hook ensures services pick up the renewed certificate.

set -euo pipefail

DOMAIN="aviationwx.org"
LETSENCRYPT_CERT_DIR="/etc/letsencrypt/live/$DOMAIN"
DEPLOYMENT_SSL_DIR="/home/aviationwx/aviationwx/ssl"
DEPLOYMENT_USER="aviationwx"

log_message() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] certbot-deploy-hook: $*"
}

log_message "Certificate renewed for $DOMAIN - deploying to services..."

# Verify source certificates exist
if [ ! -f "$LETSENCRYPT_CERT_DIR/fullchain.pem" ] || [ ! -f "$LETSENCRYPT_CERT_DIR/privkey.pem" ]; then
    log_message "ERROR: Source certificates not found at $LETSENCRYPT_CERT_DIR"
    exit 1
fi

# Create deployment directory if it doesn't exist
mkdir -p "$DEPLOYMENT_SSL_DIR"

# Copy certificates to deployment directory
log_message "Copying certificates to $DEPLOYMENT_SSL_DIR..."
cp "$LETSENCRYPT_CERT_DIR/fullchain.pem" "$DEPLOYMENT_SSL_DIR/"
cp "$LETSENCRYPT_CERT_DIR/privkey.pem" "$DEPLOYMENT_SSL_DIR/"

# Set correct ownership and permissions
chown "$DEPLOYMENT_USER:$DEPLOYMENT_USER" "$DEPLOYMENT_SSL_DIR/fullchain.pem"
chown "$DEPLOYMENT_USER:$DEPLOYMENT_USER" "$DEPLOYMENT_SSL_DIR/privkey.pem"
chmod 644 "$DEPLOYMENT_SSL_DIR/fullchain.pem"
chmod 600 "$DEPLOYMENT_SSL_DIR/privkey.pem"

log_message "✓ Certificates copied successfully"

# Reload nginx to pick up new certificates
if docker ps --format '{{.Names}}' | grep -q '^aviationwx-nginx$'; then
    log_message "Reloading nginx..."
    if docker exec aviationwx-nginx nginx -s reload 2>/dev/null; then
        log_message "✓ nginx reloaded successfully"
    else
        log_message "⚠️  Failed to reload nginx (may need container restart)"
    fi
else
    log_message "⚠️  nginx container not running - skipping reload"
fi

# Signal vsftpd to reload certificates (SIGHUP)
# Note: vsftpd may not support hot-reload of certs; container restart may be needed
if docker ps --format '{{.Names}}' | grep -q '^aviationwx-web$'; then
    log_message "Signaling vsftpd to reload..."
    if docker exec aviationwx-web pkill -HUP vsftpd 2>/dev/null; then
        log_message "✓ vsftpd signaled successfully"
    else
        log_message "⚠️  Failed to signal vsftpd (may not support hot-reload)"
    fi
else
    log_message "⚠️  web container not running - skipping vsftpd signal"
fi

log_message "✓ Certificate deployment complete"

# Show certificate details
log_message "New certificate details:"
openssl x509 -in "$DEPLOYMENT_SSL_DIR/fullchain.pem" -noout -subject -dates 2>/dev/null || true

exit 0
