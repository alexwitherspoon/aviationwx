#!/bin/bash
# Enable SSL in vsftpd when Let's Encrypt certificates are available
# This script should be run after certificates are obtained

set -e

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
if grep -q "^ssl_enable=YES" "$VSFTPD_CONF" 2>/dev/null; then
    log_message "SSL is already enabled in vsftpd configuration"
    exit 0
fi

log_message "Enabling SSL in vsftpd configuration..."

# Create backup
cp "$VSFTPD_CONF" "$VSFTPD_CONF_BACKUP"

# Enable SSL by replacing the disabled section
# Note: We allow both FTP and FTPS on the same port (2121)
# Clients can choose to use SSL/TLS or not
sed -i 's/^ssl_enable=NO/ssl_enable=YES/' "$VSFTPD_CONF"
sed -i 's/^# ssl_enable=YES/ssl_enable=YES/' "$VSFTPD_CONF"
# Allow both FTP (unencrypted) and FTPS (encrypted) on port 2121
sed -i 's/^# force_local_data_ssl=YES/force_local_data_ssl=NO/' "$VSFTPD_CONF"
sed -i 's/^force_local_data_ssl=YES/force_local_data_ssl=NO/' "$VSFTPD_CONF"
sed -i 's/^# force_local_logins_ssl=YES/force_local_logins_ssl=NO/' "$VSFTPD_CONF"
sed -i 's/^force_local_logins_ssl=YES/force_local_logins_ssl=NO/' "$VSFTPD_CONF"
sed -i 's/^# ssl_tlsv1=YES/ssl_tlsv1=YES/' "$VSFTPD_CONF"
sed -i 's/^# ssl_sslv2=NO/ssl_sslv2=NO/' "$VSFTPD_CONF"
sed -i 's/^# ssl_sslv3=NO/ssl_sslv3=NO/' "$VSFTPD_CONF"
sed -i 's/^# require_ssl_reuse=NO/require_ssl_reuse=NO/' "$VSFTPD_CONF"
sed -i 's/^# ssl_ciphers=HIGH/ssl_ciphers=HIGH/' "$VSFTPD_CONF"
sed -i 's|^# rsa_cert_file=.*|rsa_cert_file='"$CERT_DIR"'/fullchain.pem|' "$VSFTPD_CONF"
sed -i 's|^# rsa_private_key_file=.*|rsa_private_key_file='"$CERT_DIR"'/privkey.pem|' "$VSFTPD_CONF"

# Remove commented lines if they exist
sed -i '/^# ssl_enable=YES/d' "$VSFTPD_CONF"
sed -i '/^# force_local_data_ssl=/d' "$VSFTPD_CONF"
sed -i '/^# force_local_logins_ssl=/d' "$VSFTPD_CONF"
sed -i '/^# ssl_tlsv1=YES/d' "$VSFTPD_CONF"
sed -i '/^# ssl_sslv2=NO/d' "$VSFTPD_CONF"
sed -i '/^# ssl_sslv3=NO/d' "$VSFTPD_CONF"
sed -i '/^# require_ssl_reuse=NO/d' "$VSFTPD_CONF"
sed -i '/^# ssl_ciphers=HIGH/d' "$VSFTPD_CONF"
sed -i '/^# rsa_cert_file=/d' "$VSFTPD_CONF"
sed -i '/^# rsa_private_key_file=/d' "$VSFTPD_CONF"

# Verify configuration syntax
if ! vsftpd -olisten=NO "$VSFTPD_CONF" 2>&1 | grep -q "listening on"; then
    log_message "ERROR: vsftpd configuration test failed, restoring backup"
    cp "$VSFTPD_CONF_BACKUP" "$VSFTPD_CONF"
    exit 1
fi

log_message "SSL configuration updated successfully"

# Restart vsftpd to apply changes
log_message "Restarting vsftpd to apply SSL configuration..."
if service vsftpd restart 2>&1; then
    log_message "vsftpd restarted successfully with SSL enabled"
    log_message "Both FTP and FTPS are now available on port 2121"
    log_message "Clients can choose to use encryption (FTPS) or not (FTP)"
else
    log_message "ERROR: Failed to restart vsftpd, restoring backup"
    cp "$VSFTPD_CONF_BACKUP" "$VSFTPD_CONF"
    service vsftpd restart || true
    exit 1
fi

log_message "SSL enablement complete"

