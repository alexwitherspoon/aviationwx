#!/bin/bash
# Restore SSL certificates to deployment location if they are missing
# This script is used after rsync to ensure certificates are present
# even if rsync --delete removed them

set -e

DOMAIN="aviationwx.org"
LETSENCRYPT_CERT_DIR="/etc/letsencrypt/live/$DOMAIN"

log_message() {
    local message="$@"
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $message"
}

# Deployment SSL directory (relative to current working directory)
# Script should be run from ~/aviationwx directory
DEPLOYMENT_SSL_DIR="./ssl"

log_message "Checking SSL certificates in deployment location..."

# Check if certificates already exist in deployment location
if [ -f "$DEPLOYMENT_SSL_DIR/fullchain.pem" ] && [ -f "$DEPLOYMENT_SSL_DIR/privkey.pem" ]; then
    log_message "✓ SSL certificates already present in deployment location"
    exit 0
fi

log_message "SSL certificates missing in deployment location"
log_message "Checking Let's Encrypt certificates..."

# Check if Let's Encrypt certificates exist
if [ ! -f "$LETSENCRYPT_CERT_DIR/fullchain.pem" ] || [ ! -f "$LETSENCRYPT_CERT_DIR/privkey.pem" ]; then
    log_message "❌ ERROR: Let's Encrypt certificates not found at $LETSENCRYPT_CERT_DIR"
    log_message "Cannot restore certificates - Let's Encrypt files not available"
    exit 1
fi

log_message "Let's Encrypt certificates found, copying to deployment location..."

# Create deployment SSL directory if it doesn't exist
mkdir -p "$DEPLOYMENT_SSL_DIR"

# Copy certificates from Let's Encrypt to deployment location
if sudo cp "$LETSENCRYPT_CERT_DIR/fullchain.pem" "$DEPLOYMENT_SSL_DIR/" && \
   sudo cp "$LETSENCRYPT_CERT_DIR/privkey.pem" "$DEPLOYMENT_SSL_DIR/"; then
    log_message "✓ Certificates copied successfully"
else
    log_message "❌ ERROR: Failed to copy certificates"
    exit 1
fi

# Set proper ownership and permissions
CURRENT_USER=$(whoami)
if sudo chown -R "$CURRENT_USER:$CURRENT_USER" "$DEPLOYMENT_SSL_DIR" && \
   sudo chmod 644 "$DEPLOYMENT_SSL_DIR/fullchain.pem" && \
   sudo chmod 600 "$DEPLOYMENT_SSL_DIR/privkey.pem"; then
    log_message "✓ Permissions set correctly"
else
    log_message "⚠️  WARNING: Failed to set permissions (may still work)"
fi

log_message "✓ SSL certificates restored successfully"
exit 0

