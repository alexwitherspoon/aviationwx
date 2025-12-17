#!/bin/bash
# Enable SSL in vsftpd when Let's Encrypt certificates are available
# This script should be run after certificates are obtained
# Uses wildcard certificate (*.aviationwx.org) which covers upload.aviationwx.org

set -euo pipefail

DOMAIN="aviationwx.org"
CERT_DIR="/etc/letsencrypt/live/$DOMAIN"
VSFTPD_IPV4_CONF="/etc/vsftpd/vsftpd_ipv4.conf"
VSFTPD_IPV6_CONF="/etc/vsftpd/vsftpd_ipv6.conf"
VSFTPD_IPV4_CONF_BACKUP="/etc/vsftpd/vsftpd_ipv4.conf.backup"

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

# Validate private key - try multiple methods for compatibility
KEY_VALID=false
if openssl pkey -in "$CERT_DIR/privkey.pem" -noout >/dev/null 2>&1; then
    KEY_VALID=true
elif openssl rsa -in "$CERT_DIR/privkey.pem" -check -noout >/dev/null 2>&1; then
    KEY_VALID=true
elif openssl rsa -in "$CERT_DIR/privkey.pem" -noout >/dev/null 2>&1; then
    KEY_VALID=true
fi

if [ "$KEY_VALID" = false ]; then
    log_message "ERROR: SSL private key file appears to be invalid or corrupted"
    log_message "Key file: $CERT_DIR/privkey.pem"
    log_message "Attempting to read key file for debugging..."
    if [ -r "$CERT_DIR/privkey.pem" ]; then
        log_message "Key file is readable, but validation failed"
        head -c 100 "$CERT_DIR/privkey.pem" | cat -A || true
    else
        log_message "Key file is NOT readable"
        ls -la "$CERT_DIR/privkey.pem" || true
    fi
    exit 1
fi

log_message "✓ SSL certificates validated successfully"

enable_ssl_in_config() {
    local config_file="$1"
    if [ ! -f "$config_file" ]; then
        return 0
    fi
    
    # Enable SSL
    sed -i 's/^ssl_enable=NO/ssl_enable=YES/' "$config_file"
    sed -i 's/^# ssl_enable=YES/ssl_enable=YES/' "$config_file"
    
    # Allow both FTP and FTPS (optional encryption)
    sed -i 's/^# force_local_data_ssl=YES/force_local_data_ssl=NO/' "$config_file"
    sed -i 's/^force_local_data_ssl=YES/force_local_data_ssl=NO/' "$config_file"
    sed -i 's/^# force_local_logins_ssl=YES/force_local_logins_ssl=NO/' "$config_file"
    sed -i 's/^force_local_logins_ssl=YES/force_local_logins_ssl=NO/' "$config_file"
    
    # Enable TLS versions for camera compatibility
    sed -i 's/^# ssl_tlsv1=YES/ssl_tlsv1=YES/' "$config_file"
    sed -i 's/^ssl_tlsv1=NO/ssl_tlsv1=YES/' "$config_file"
    sed -i 's/^# ssl_tlsv1_1=YES/ssl_tlsv1_1=YES/' "$config_file" 2>/dev/null || true
    sed -i 's/^# ssl_tlsv1_2=YES/ssl_tlsv1_2=YES/' "$config_file" 2>/dev/null || true
    sed -i 's/^ssl_tlsv1_1=NO/ssl_tlsv1_1=YES/' "$config_file" 2>/dev/null || true
    sed -i 's/^ssl_tlsv1_2=NO/ssl_tlsv1_2=YES/' "$config_file" 2>/dev/null || true
    
    # Disable insecure SSL versions
    sed -i 's/^# ssl_sslv2=NO/ssl_sslv2=NO/' "$config_file"
    sed -i 's/^ssl_sslv2=YES/ssl_sslv2=NO/' "$config_file"
    sed -i 's/^# ssl_sslv3=NO/ssl_sslv3=NO/' "$config_file"
    sed -i 's/^ssl_sslv3=YES/ssl_sslv3=NO/' "$config_file"
    
    # SSL/TLS settings
    sed -i 's/^# require_ssl_reuse=NO/require_ssl_reuse=NO/' "$config_file"
    sed -i 's/^require_ssl_reuse=YES/require_ssl_reuse=NO/' "$config_file"
    sed -i 's/^# ssl_ciphers=HIGH/ssl_ciphers=HIGH/' "$config_file"
    sed -i "s|^# rsa_cert_file=.*|rsa_cert_file=$CERT_DIR/fullchain.pem|" "$config_file"
    sed -i "s|^# rsa_private_key_file=.*|rsa_private_key_file=$CERT_DIR/privkey.pem|" "$config_file"
    
    # Remove commented SSL lines
    sed -i '/^# ssl_enable=/d' "$config_file"
    sed -i '/^# force_local_data_ssl=/d' "$config_file"
    sed -i '/^# force_local_logins_ssl=/d' "$config_file"
    sed -i '/^# ssl_tlsv/d' "$config_file"
    sed -i '/^# ssl_sslv/d' "$config_file"
    sed -i '/^# require_ssl_reuse=/d' "$config_file"
    sed -i '/^# ssl_ciphers=/d' "$config_file"
    sed -i '/^# rsa_cert_file=/d' "$config_file"
    sed -i '/^# rsa_private_key_file=/d' "$config_file"
    
    # Add TLS versions if they don't exist
    if ! grep -q "^ssl_tlsv1_1=" "$config_file" 2>/dev/null; then
        echo "ssl_tlsv1_1=YES" >> "$config_file"
    fi
    if ! grep -q "^ssl_tlsv1_2=" "$config_file" 2>/dev/null; then
        echo "ssl_tlsv1_2=YES" >> "$config_file"
    fi
}

# Check if SSL is already enabled in IPv4 config (used as reference)
SSL_ALREADY_ENABLED=false
if [ -f "$VSFTPD_IPV4_CONF" ] && grep -q "^ssl_enable=YES" "$VSFTPD_IPV4_CONF" 2>/dev/null; then
    SSL_ALREADY_ENABLED=true
    log_message "SSL is already enabled in vsftpd configuration"
    log_message "Updating TLS version settings for broad camera compatibility..."
fi

if [ "$SSL_ALREADY_ENABLED" = false ]; then
    log_message "Enabling SSL in vsftpd configuration..."
    # Backup IPv4 config for rollback if needed
    if [ -f "$VSFTPD_IPV4_CONF" ]; then
        cp "$VSFTPD_IPV4_CONF" "$VSFTPD_IPV4_CONF_BACKUP"
    fi
fi

# Enable SSL in dual configs (IPv4 and IPv6)
enable_ssl_in_config "$VSFTPD_IPV4_CONF"
enable_ssl_in_config "$VSFTPD_IPV6_CONF"

# Verify configuration syntax using IPv4 config
if [ -f "$VSFTPD_IPV4_CONF" ]; then
    CONFIG_TEST_OUTPUT=$(timeout 5 vsftpd -olisten=NO "$VSFTPD_IPV4_CONF" 2>&1 || echo "CONFIG_TEST_FAILED")
    if ! echo "$CONFIG_TEST_OUTPUT" | grep -q "listening on"; then
        log_message "ERROR: vsftpd IPv4 configuration test failed"
        log_message "Test output: $CONFIG_TEST_OUTPUT"
        if [ -f "$VSFTPD_IPV4_CONF_BACKUP" ]; then
            log_message "Restoring backup configuration..."
            cp "$VSFTPD_IPV4_CONF_BACKUP" "$VSFTPD_IPV4_CONF"
        fi
        exit 1
    fi
fi

if [ "$SSL_ALREADY_ENABLED" = false ]; then
    log_message "SSL configuration updated successfully"
else
    log_message "TLS version settings updated successfully"
fi

# Restart vsftpd to apply changes (only if it's already running)
# Handle both single-instance and dual-instance (dual-stack) modes
if pgrep -x vsftpd > /dev/null 2>&1; then
    log_message "vsftpd is running"
    VSFTPD_COUNT=$(pgrep -x vsftpd | wc -l)
    
    if [ "$VSFTPD_COUNT" -gt 1 ]; then
        # Dual-instance mode (IPv4 + IPv6)
        log_message "Multiple vsftpd instances detected (dual-stack mode)"
        log_message "Restarting dual-instance vsftpd processes..."
        
        # Get PIDs of running vsftpd processes
        VSFTPD_PIDS=$(pgrep -x vsftpd)
        RESTART_SUCCESS=true
        
        # Kill existing vsftpd processes
        for pid in $VSFTPD_PIDS; do
            log_message "Stopping vsftpd process (PID: $pid)..."
            kill $pid 2>/dev/null || true
        done
        
        # Wait for processes to stop
        sleep 2
        
        # Verify processes are stopped
        if pgrep -x vsftpd > /dev/null 2>&1; then
            log_message "WARNING: Some vsftpd processes did not stop, forcing kill..."
            pkill -9 vsftpd 2>/dev/null || true
            sleep 1
        fi
        
        # Start IPv4 instance if config exists
        if [ -f "$VSFTPD_IPV4_CONF" ]; then
            log_message "Starting vsftpd IPv4 instance..."
            if vsftpd "$VSFTPD_IPV4_CONF" >/dev/null 2>&1 & then
                sleep 1
                if ! pgrep -x vsftpd > /dev/null 2>&1; then
                    log_message "ERROR: Failed to start vsftpd IPv4 instance"
                    RESTART_SUCCESS=false
                else
                    log_message "✓ vsftpd IPv4 instance started"
                fi
            else
                log_message "ERROR: Failed to start vsftpd IPv4 instance"
                RESTART_SUCCESS=false
            fi
        fi
        
        # Start IPv6 instance if config exists
        if [ -f "$VSFTPD_IPV6_CONF" ]; then
            log_message "Starting vsftpd IPv6 instance..."
            if vsftpd "$VSFTPD_IPV6_CONF" >/dev/null 2>&1 & then
                sleep 1
                if ! pgrep -x vsftpd > /dev/null 2>&1 || [ $(pgrep -x vsftpd | wc -l) -lt 2 ]; then
                    log_message "ERROR: Failed to start vsftpd IPv6 instance"
                    RESTART_SUCCESS=false
                else
                    log_message "✓ vsftpd IPv6 instance started"
                fi
            else
                log_message "ERROR: Failed to start vsftpd IPv6 instance"
                RESTART_SUCCESS=false
            fi
        fi
        
        # Verify both instances are running
        FINAL_COUNT=$(pgrep -x vsftpd | wc -l)
        if [ "$FINAL_COUNT" -ge 2 ] && [ "$RESTART_SUCCESS" = true ]; then
            if [ "$SSL_ALREADY_ENABLED" = false ]; then
                log_message "✓ vsftpd dual-instance restarted successfully with SSL enabled"
            else
                log_message "✓ vsftpd dual-instance restarted successfully with updated TLS versions"
            fi
            log_message "Both FTP and FTPS are now available on port 2121 (IPv4 and IPv6)"
        else
            log_message "WARNING: vsftpd restart may have failed - only $FINAL_COUNT instance(s) running"
            log_message "SSL configuration has been updated but may require container restart"
            # Rollback on failure if backup exists
            if [ -f "$VSFTPD_IPV4_CONF_BACKUP" ]; then
                log_message "Rolling back SSL configuration changes..."
                cp "$VSFTPD_IPV4_CONF_BACKUP" "$VSFTPD_IPV4_CONF"
            fi
        fi
    else
        # Single instance mode
        log_message "Restarting vsftpd to apply configuration changes..."
        if service vsftpd restart 2>&1; then
            if [ "$SSL_ALREADY_ENABLED" = false ]; then
                log_message "✓ vsftpd restarted successfully with SSL enabled"
            else
                log_message "✓ vsftpd restarted successfully with updated TLS versions"
            fi
            log_message "Both FTP and FTPS are now available on port 2121"
            log_message "Clients can choose to use encryption (FTPS) or not (FTP)"
        else
            log_message "WARNING: Failed to restart vsftpd via service command"
            log_message "SSL configuration has been updated and will be active on next container restart"
            log_message "Or restart the container to apply changes immediately"
            # Rollback on failure if backup exists
            if [ -f "$VSFTPD_IPV4_CONF_BACKUP" ]; then
                log_message "Rolling back SSL configuration changes..."
                cp "$VSFTPD_IPV4_CONF_BACKUP" "$VSFTPD_IPV4_CONF"
            fi
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

