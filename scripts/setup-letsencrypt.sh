#!/bin/bash
# Let's Encrypt Certificate Setup for upload.aviationwx.org
# Sets up SSL certificate for FTPS

set -e

DOMAIN="upload.aviationwx.org"
EMAIL="${LETSENCRYPT_EMAIL:-admin@aviationwx.org}"
CERT_DIR="/etc/letsencrypt/live/$DOMAIN"
WEBROOT="/var/www/html"

log_message() {
    local message="$@"
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $message"
}

# Check if certbot is installed
if ! command -v certbot &> /dev/null; then
    log_message "ERROR: certbot is not installed"
    exit 1
fi

# Check if certificate already exists
if [ -d "$CERT_DIR" ] && [ -f "$CERT_DIR/fullchain.pem" ] && [ -f "$CERT_DIR/privkey.pem" ]; then
    log_message "Certificate already exists for $DOMAIN"
    
    # Check certificate expiry
    if certbot certificates 2>/dev/null | grep -q "$DOMAIN"; then
        log_message "Certificate found, checking expiry..."
        certbot certificates 2>/dev/null | grep -A 5 "$DOMAIN"
    fi
    
    log_message "To renew, run: certbot renew"
    exit 0
fi

# Create webroot directory if it doesn't exist
mkdir -p "$WEBROOT/.well-known/acme-challenge"

# Request certificate
log_message "Requesting Let's Encrypt certificate for $DOMAIN..."

certbot certonly \
    --webroot \
    --webroot-path="$WEBROOT" \
    --email "$EMAIL" \
    --agree-tos \
    --no-eff-email \
    --non-interactive \
    --domains "$DOMAIN" \
    --cert-path /etc/letsencrypt/live/$DOMAIN/cert.pem \
    --key-path /etc/letsencrypt/live/$DOMAIN/privkey.pem \
    --fullchain-path /etc/letsencrypt/live/$DOMAIN/fullchain.pem \
    --chain-path /etc/letsencrypt/live/$DOMAIN/chain.pem

if [ $? -eq 0 ]; then
    log_message "Certificate obtained successfully for $DOMAIN"
    log_message "Certificate location: $CERT_DIR"
    
    # Set proper permissions
    chmod 644 "$CERT_DIR/fullchain.pem"
    chmod 600 "$CERT_DIR/privkey.pem"
    
    log_message "Certificate setup complete"
else
    log_message "ERROR: Failed to obtain certificate"
    exit 1
fi

