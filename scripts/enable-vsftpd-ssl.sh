#!/bin/bash
# Enable SSL in vsftpd when Let's Encrypt certificates are available
# This script should be run after certificates are obtained

set -euo pipefail

DOMAIN="upload.aviationwx.org"
CERT_DIR="/etc/letsencrypt/live/$DOMAIN"
VSFTPD_CONF="/etc/vsftpd.conf"
VSFTPD_CONF_BACKUP="/etc/vsftpd.conf.backup"

log_message() {
    local message="$@"
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $message"
}

# Check if certificates exist
if [ ! -d "$CERT_DIR" ] || [ ! -f "$CERT_DIR/fullchain.pem" ] || [ ! -f "$CERT_DIR/privkey.pem" ]; then
    log_message "Certificates not found at $CERT_DIR"
    log_message "SSL will remain disabled until certificates are available"
    exit 0
fi

# Check if SSL is already enabled
SSL_ALREADY_ENABLED=false
if grep -q "^ssl_enable=YES" "$VSFTPD_CONF" 2>/dev/null; then
    SSL_ALREADY_ENABLED=true
    log_message "SSL is already enabled in vsftpd configuration"
    log_message "Updating TLS version settings for broad camera compatibility..."
fi

if [ "$SSL_ALREADY_ENABLED" = false ]; then
    log_message "Enabling SSL in vsftpd configuration..."
    # Create backup
    cp "$VSFTPD_CONF" "$VSFTPD_CONF_BACKUP"
    # Enable SSL by replacing the disabled section
    # Note: We allow both FTP and FTPS on the same port (2121)
    # Clients can choose to use SSL/TLS or not
    sed -i 's/^ssl_enable=NO/ssl_enable=YES/' "$VSFTPD_CONF"
    sed -i 's/^# ssl_enable=YES/ssl_enable=YES/' "$VSFTPD_CONF"
else
    # Create backup before updating TLS versions
    cp "$VSFTPD_CONF" "$VSFTPD_CONF_BACKUP"
fi
# Allow both FTP (unencrypted) and FTPS (encrypted) on port 2121
sed -i 's/^# force_local_data_ssl=YES/force_local_data_ssl=NO/' "$VSFTPD_CONF"
sed -i 's/^force_local_data_ssl=YES/force_local_data_ssl=NO/' "$VSFTPD_CONF"
sed -i 's/^# force_local_logins_ssl=YES/force_local_logins_ssl=NO/' "$VSFTPD_CONF"
sed -i 's/^force_local_logins_ssl=YES/force_local_logins_ssl=NO/' "$VSFTPD_CONF"

# Enable multiple TLS versions for broad camera compatibility
# TLSv1.2: Modern and secure (preferred)
# TLSv1.1: Good compatibility with older cameras
# TLSv1.0: Maximum compatibility with legacy cameras
# This protects credentials while maintaining compatibility
sed -i 's/^# ssl_tlsv1=YES/ssl_tlsv1=YES/' "$VSFTPD_CONF"
sed -i 's/^ssl_tlsv1=NO/ssl_tlsv1=YES/' "$VSFTPD_CONF"

# Enable TLSv1.1 and TLSv1.2 for broad camera compatibility
# vsftpd 3.0+ supports these; older versions will ignore unknown options
if ! grep -q "^ssl_tlsv1_1=" "$VSFTPD_CONF" 2>/dev/null; then
    echo "ssl_tlsv1_1=YES" >> "$VSFTPD_CONF"
fi
if ! grep -q "^ssl_tlsv1_2=" "$VSFTPD_CONF" 2>/dev/null; then
    echo "ssl_tlsv1_2=YES" >> "$VSFTPD_CONF"
fi
sed -i 's/^ssl_tlsv1_1=NO/ssl_tlsv1_1=YES/' "$VSFTPD_CONF" 2>/dev/null || true
sed -i 's/^ssl_tlsv1_2=NO/ssl_tlsv1_2=YES/' "$VSFTPD_CONF" 2>/dev/null || true

# Disable insecure SSL versions
sed -i 's/^# ssl_sslv2=NO/ssl_sslv2=NO/' "$VSFTPD_CONF"
sed -i 's/^ssl_sslv2=YES/ssl_sslv2=NO/' "$VSFTPD_CONF"
sed -i 's/^# ssl_sslv3=NO/ssl_sslv3=NO/' "$VSFTPD_CONF"
sed -i 's/^ssl_sslv3=YES/ssl_sslv3=NO/' "$VSFTPD_CONF"

# SSL/TLS settings for compatibility
sed -i 's/^# require_ssl_reuse=NO/require_ssl_reuse=NO/' "$VSFTPD_CONF"
sed -i 's/^require_ssl_reuse=YES/require_ssl_reuse=NO/' "$VSFTPD_CONF"
sed -i 's/^# ssl_ciphers=HIGH/ssl_ciphers=HIGH/' "$VSFTPD_CONF"
sed -i 's|^# rsa_cert_file=.*|rsa_cert_file='"$CERT_DIR"'/fullchain.pem|' "$VSFTPD_CONF"
sed -i 's|^# rsa_private_key_file=.*|rsa_private_key_file='"$CERT_DIR"'/privkey.pem|' "$VSFTPD_CONF"

# Remove commented lines if they exist
sed -i '/^# ssl_enable=YES/d' "$VSFTPD_CONF"
sed -i '/^# force_local_data_ssl=/d' "$VSFTPD_CONF"
sed -i '/^# force_local_logins_ssl=/d' "$VSFTPD_CONF"
sed -i '/^# ssl_tlsv1=YES/d' "$VSFTPD_CONF"
sed -i '/^# ssl_tlsv1_1=/d' "$VSFTPD_CONF"
sed -i '/^# ssl_tlsv1_2=/d' "$VSFTPD_CONF"
sed -i '/^# ssl_sslv2=NO/d' "$VSFTPD_CONF"
sed -i '/^# ssl_sslv3=NO/d' "$VSFTPD_CONF"
sed -i '/^# require_ssl_reuse=NO/d' "$VSFTPD_CONF"
sed -i '/^# ssl_ciphers=HIGH/d' "$VSFTPD_CONF"
sed -i '/^# rsa_cert_file=/d' "$VSFTPD_CONF"
sed -i '/^# rsa_private_key_file=/d' "$VSFTPD_CONF"

# Verify configuration syntax
# Use timeout to prevent hanging, and capture output for debugging
CONFIG_TEST_OUTPUT=$(timeout 5 vsftpd -olisten=NO "$VSFTPD_CONF" 2>&1 || echo "CONFIG_TEST_FAILED")
if ! echo "$CONFIG_TEST_OUTPUT" | grep -q "listening on"; then
    log_message "ERROR: vsftpd configuration test failed"
    log_message "Test output: $CONFIG_TEST_OUTPUT"
    log_message "Restoring backup configuration..."
    cp "$VSFTPD_CONF_BACKUP" "$VSFTPD_CONF"
    exit 1
fi

if [ "$SSL_ALREADY_ENABLED" = false ]; then
    log_message "SSL configuration updated successfully"
else
    log_message "TLS version settings updated successfully"
fi

# Restart vsftpd to apply changes (only if it's already running)
if pgrep -x vsftpd > /dev/null 2>&1; then
    log_message "vsftpd is running, restarting to apply configuration changes..."
    if service vsftpd restart 2>&1; then
        if [ "$SSL_ALREADY_ENABLED" = false ]; then
            log_message "vsftpd restarted successfully with SSL enabled"
        else
            log_message "vsftpd restarted successfully with updated TLS versions"
        fi
        log_message "Both FTP and FTPS are now available on port 2121"
        log_message "Clients can choose to use encryption (FTPS) or not (FTP)"
    else
        log_message "ERROR: Failed to restart vsftpd, restoring backup"
        cp "$VSFTPD_CONF_BACKUP" "$VSFTPD_CONF"
        service vsftpd restart || true
        exit 1
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

