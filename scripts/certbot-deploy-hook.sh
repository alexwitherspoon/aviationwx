#!/bin/bash
# Certbot Deploy Hook for AviationWX
#
# This script runs after certbot successfully renews any SSL certificate.
# It copies the renewed certificate to the deployment directory and reloads services.
#
# Certbot sets $RENEWED_LINEAGE to the path of the renewed certificate
# (e.g., /etc/letsencrypt/live/aviationwx.org)
#
# Installation (run on production host):
#   sudo cp scripts/certbot-deploy-hook.sh /etc/letsencrypt/renewal-hooks/deploy/aviationwx-deploy-hook.sh
#   sudo chmod +x /etc/letsencrypt/renewal-hooks/deploy/aviationwx-deploy-hook.sh
#
# The host's systemd certbot.timer handles automatic renewal twice daily.
# This hook ensures services pick up the renewed certificate.

set -euo pipefail

# Certbot provides RENEWED_LINEAGE as the path to the renewed cert directory
# e.g., /etc/letsencrypt/live/aviationwx.org or /etc/letsencrypt/live/upload.aviationwx.org
CERT_DIR="${RENEWED_LINEAGE:-}"
DEPLOYMENT_SSL_DIR="/home/aviationwx/aviationwx/ssl"
DEPLOYMENT_USER="aviationwx"

log_message() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] certbot-deploy-hook: $*"
}

# Extract domain name from the certificate path
if [ -n "$CERT_DIR" ]; then
    DOMAIN=$(basename "$CERT_DIR")
else
    # Fallback for manual testing - default to main domain
    DOMAIN="aviationwx.org"
    CERT_DIR="/etc/letsencrypt/live/$DOMAIN"
    log_message "Warning: RENEWED_LINEAGE not set, using default: $CERT_DIR"
fi

log_message "Certificate renewed for $DOMAIN - deploying to services..."

# Verify source certificates exist
if [ ! -f "$CERT_DIR/fullchain.pem" ] || [ ! -f "$CERT_DIR/privkey.pem" ]; then
    log_message "ERROR: Source certificates not found at $CERT_DIR"
    exit 1
fi

# Only copy to deployment directory for the main domain (aviationwx.org)
# The wildcard cert covers *.aviationwx.org including upload subdomain for FTPS
# upload.aviationwx.org has its own cert but services use the main wildcard
if [ "$DOMAIN" = "aviationwx.org" ]; then
    # Create deployment directory if it doesn't exist
    mkdir -p "$DEPLOYMENT_SSL_DIR"

    # Copy certificates to deployment directory
    log_message "Copying certificates to $DEPLOYMENT_SSL_DIR..."
    cp "$CERT_DIR/fullchain.pem" "$DEPLOYMENT_SSL_DIR/"
    cp "$CERT_DIR/privkey.pem" "$DEPLOYMENT_SSL_DIR/"

    # Set correct ownership and permissions
    chown "$DEPLOYMENT_USER:$DEPLOYMENT_USER" "$DEPLOYMENT_SSL_DIR/fullchain.pem"
    chown "$DEPLOYMENT_USER:$DEPLOYMENT_USER" "$DEPLOYMENT_SSL_DIR/privkey.pem"
    chmod 644 "$DEPLOYMENT_SSL_DIR/fullchain.pem"
    chmod 600 "$DEPLOYMENT_SSL_DIR/privkey.pem"

    log_message "✓ Certificates copied successfully"
else
    log_message "Skipping certificate copy for $DOMAIN (only aviationwx.org is deployed to services)"
fi

# Reload nginx to pick up new certificates (for any domain renewal)
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

log_message "✓ Certificate deployment complete for $DOMAIN"

# Show certificate details (from the renewed cert, not deployment dir)
log_message "New certificate details:"
openssl x509 -in "$CERT_DIR/fullchain.pem" -noout -subject -dates 2>/dev/null || true

exit 0
