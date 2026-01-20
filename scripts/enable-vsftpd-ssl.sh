#!/bin/bash
# Enable SSL in vsftpd when Let's Encrypt certificates are available
# This script should be run after certificates are obtained
# Uses wildcard certificate (*.aviationwx.org) which covers upload.aviationwx.org

set -euo pipefail

DOMAIN="aviationwx.org"
CERT_DIR="/etc/letsencrypt/live/$DOMAIN"
VSFTPD_CONF="/etc/vsftpd/vsftpd.conf"
VSFTPD_CONF_BACKUP="/etc/vsftpd/vsftpd.conf.backup"

log_message() {
    local message="$@"
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $message"
}

# Check if certificates exist and are valid
if [ ! -d "$CERT_DIR" ] || [ ! -f "$CERT_DIR/fullchain.pem" ] || [ ! -f "$CERT_DIR/privkey.pem" ]; then
    log_message "Certificates not found at $CERT_DIR"
    log_message "SSL will remain disabled until certificates are available"
    exit 0
fi

# Validate certificates before enabling SSL
log_message "Validating SSL certificates..."
if [ ! -r "$CERT_DIR/fullchain.pem" ] || [ ! -r "$CERT_DIR/privkey.pem" ]; then
    log_message "ERROR: Certificate files exist but are not readable"
    log_message "Certificate file: $CERT_DIR/fullchain.pem"
    log_message "Key file: $CERT_DIR/privkey.pem"
    exit 1
fi

if ! openssl x509 -in "$CERT_DIR/fullchain.pem" -noout -text >/dev/null 2>&1; then
    log_message "ERROR: SSL certificate file appears to be invalid or corrupted"
    log_message "Certificate file: $CERT_DIR/fullchain.pem"
    exit 1
fi

# Validate private key
KEY_VALID=false
if openssl rsa -in "$CERT_DIR/privkey.pem" -check -noout >/dev/null 2>&1; then
    KEY_VALID=true
elif openssl rsa -in "$CERT_DIR/privkey.pem" -noout >/dev/null 2>&1; then
    KEY_VALID=true
elif openssl pkey -in "$CERT_DIR/privkey.pem" -noout >/dev/null 2>&1; then
    KEY_VALID=true
fi

if [ "$KEY_VALID" = false ]; then
    log_message "ERROR: SSL private key file appears to be invalid or corrupted"
    log_message "Key file: $CERT_DIR/privkey.pem"
    exit 1
fi

log_message "✓ SSL certificates validated successfully"

# Check if config file exists
if [ ! -f "$VSFTPD_CONF" ]; then
    log_message "ERROR: vsftpd config not found at $VSFTPD_CONF"
    exit 1
fi

enable_ssl_in_config() {
    local config_file="$1"
    
    # Enable SSL
    sed -i 's/^ssl_enable=NO/ssl_enable=YES/' "$config_file"
    sed -i 's/^# ssl_enable=YES/ssl_enable=YES/' "$config_file"
    
    # Allow both FTP and FTPS (optional encryption)
    # Ensure these are explicitly set to NO for compatibility with various clients
    sed -i 's/^# force_local_data_ssl=YES/force_local_data_ssl=NO/' "$config_file"
    sed -i 's/^force_local_data_ssl=YES/force_local_data_ssl=NO/' "$config_file"
    sed -i 's/^# force_local_logins_ssl=YES/force_local_logins_ssl=NO/' "$config_file"
    sed -i 's/^force_local_logins_ssl=YES/force_local_logins_ssl=NO/' "$config_file"
    
    # Ensure these settings exist (add if missing)
    if ! grep -q "^force_local_data_ssl=" "$config_file" 2>/dev/null; then
        echo "force_local_data_ssl=NO" >> "$config_file"
    fi
    if ! grep -q "^force_local_logins_ssl=" "$config_file" 2>/dev/null; then
        echo "force_local_logins_ssl=NO" >> "$config_file"
    fi
    
    # Enable TLS versions for camera compatibility
    sed -i 's/^# ssl_tlsv1=YES/ssl_tlsv1=YES/' "$config_file"
    sed -i 's/^ssl_tlsv1=NO/ssl_tlsv1=YES/' "$config_file"
    sed -i '/^ssl_tlsv1_1=/d' "$config_file" 2>/dev/null || true
    sed -i '/^ssl_tlsv1_2=/d' "$config_file" 2>/dev/null || true
    
    # Disable insecure SSL versions (security requirement)
    sed -i 's/^# ssl_sslv2=NO/ssl_sslv2=NO/' "$config_file"
    sed -i 's/^ssl_sslv2=YES/ssl_sslv2=NO/' "$config_file"
    sed -i 's/^# ssl_sslv3=NO/ssl_sslv3=NO/' "$config_file"
    sed -i 's/^ssl_sslv3=YES/ssl_sslv3=NO/' "$config_file"
    
    # Ensure these are explicitly set (add if missing)
    if ! grep -q "^ssl_sslv2=" "$config_file" 2>/dev/null; then
        echo "ssl_sslv2=NO" >> "$config_file"
    fi
    if ! grep -q "^ssl_sslv3=" "$config_file" 2>/dev/null; then
        echo "ssl_sslv3=NO" >> "$config_file"
    fi
    
    # SSL/TLS settings
    # Disable SSL session reuse requirement for Go client compatibility
    # Go's FTP libraries don't properly implement TLS session reuse, causing
    # data connection failures when vsftpd enforces require_ssl_reuse=YES
    sed -i 's/^# require_ssl_reuse=NO/require_ssl_reuse=NO/' "$config_file"
    sed -i 's/^require_ssl_reuse=YES/require_ssl_reuse=NO/' "$config_file"
    
    # Ensure require_ssl_reuse=NO is set (critical for Go/Bridge FTPS clients)
    if ! grep -q "^require_ssl_reuse=" "$config_file" 2>/dev/null; then
        echo "require_ssl_reuse=NO" >> "$config_file"
    fi
    
    sed -i 's/^# ssl_ciphers=HIGH/ssl_ciphers=HIGH/' "$config_file"
    sed -i "s|^# rsa_cert_file=.*|rsa_cert_file=$CERT_DIR/fullchain.pem|" "$config_file"
    sed -i "s|^# rsa_private_key_file=.*|rsa_private_key_file=$CERT_DIR/privkey.pem|" "$config_file"
    
    # Ensure ssl_tlsv1 is set
    if ! grep -q "^ssl_tlsv1=" "$config_file" 2>/dev/null; then
        echo "ssl_tlsv1=YES" >> "$config_file"
    fi
}

# Check if SSL is already enabled
SSL_ALREADY_ENABLED=false
if grep -q "^ssl_enable=YES" "$VSFTPD_CONF" 2>/dev/null; then
    SSL_ALREADY_ENABLED=true
    log_message "SSL is already enabled in vsftpd configuration"
    log_message "Updating TLS version settings for broad camera compatibility..."
fi

if [ "$SSL_ALREADY_ENABLED" = false ]; then
    log_message "Enabling SSL in vsftpd configuration..."
    cp "$VSFTPD_CONF" "$VSFTPD_CONF_BACKUP"
fi

# Enable SSL in config
enable_ssl_in_config "$VSFTPD_CONF"

if [ "$SSL_ALREADY_ENABLED" = false ]; then
    log_message "SSL configuration updated successfully"
else
    log_message "TLS version settings updated successfully"
fi

# Restart vsftpd to apply changes
if pgrep -x vsftpd > /dev/null 2>&1; then
    log_message "Restarting vsftpd to apply configuration changes..."
    
    # Kill existing vsftpd process
    pkill -x vsftpd 2>/dev/null || true
    sleep 2
    
    # Start vsftpd
    if vsftpd "$VSFTPD_CONF" >/dev/null 2>&1 & then
        sleep 1
        if pgrep -x vsftpd > /dev/null 2>&1; then
            if [ "$SSL_ALREADY_ENABLED" = false ]; then
                log_message "✓ vsftpd restarted successfully with SSL enabled"
            else
                log_message "✓ vsftpd restarted successfully with updated TLS versions"
            fi
            log_message "Both FTP and FTPS are now available on port 2121"
        else
            log_message "WARNING: Failed to restart vsftpd"
            log_message "SSL configuration has been updated but may require container restart"
            if [ -f "$VSFTPD_CONF_BACKUP" ]; then
                log_message "Rolling back SSL configuration changes..."
                cp "$VSFTPD_CONF_BACKUP" "$VSFTPD_CONF"
            fi
        fi
    else
        log_message "WARNING: Failed to start vsftpd"
        if [ -f "$VSFTPD_CONF_BACKUP" ]; then
            log_message "Rolling back SSL configuration changes..."
            cp "$VSFTPD_CONF_BACKUP" "$VSFTPD_CONF"
        fi
    fi
else
    if [ "$SSL_ALREADY_ENABLED" = false ]; then
        log_message "vsftpd is not running yet - SSL configuration will be applied when vsftpd starts"
    else
        log_message "vsftpd is not running yet - TLS version updates will be applied when vsftpd starts"
    fi
    log_message "Both FTP and FTPS will be available on port 2121 once vsftpd is started"
fi

log_message "Configuration update complete"
