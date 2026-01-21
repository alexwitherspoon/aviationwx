#!/bin/bash
set -e

# Ensure config directory exists (needed for other config files)
CONFIG_DIR="/var/www/html/config"
if [ ! -d "${CONFIG_DIR}" ]; then
    echo "Creating config directory: ${CONFIG_DIR}"
    mkdir -p "${CONFIG_DIR}"
    chown www-data:www-data "${CONFIG_DIR}" 2>/dev/null || true
fi

# Ensure secrets directory exists (needed for airports.json mount via CONFIG_PATH)
SECRETS_DIR="/var/www/html/secrets"
if [ ! -d "${SECRETS_DIR}" ]; then
    echo "Creating secrets directory: ${SECRETS_DIR}"
    mkdir -p "${SECRETS_DIR}"
    chown www-data:www-data "${SECRETS_DIR}" 2>/dev/null || true
fi

# For backward compatibility, check if airports.json exists in config directory
# (fallback for environments that don't use CONFIG_PATH)
CONFIG_FILE="${CONFIG_DIR}/airports.json"

# Handle airports.json fallback for local development
# Production: airports.json is mounted from /home/aviationwx/airports.json (must exist)
# Local: If airports.json doesn't exist, create it from fallback sources (example > test fixture)
if [ ! -f "${CONFIG_FILE}" ]; then
    echo "airports.json not found in config directory, checking for fallback sources..."
    
    # Try to copy from example file (for local development)
    EXAMPLE_FILE="/var/www/html/config/airports.json.example"
    TEST_FILE="/var/www/html/tests/Fixtures/airports.json.test"
    
    if [ -f "${EXAMPLE_FILE}" ]; then
        echo "Copying airports.json from example file..."
        cp "${EXAMPLE_FILE}" "${CONFIG_FILE}" 2>/dev/null || {
            echo "⚠️  Warning: Could not write to ${CONFIG_FILE} (read-only mount?)"
            echo "  Falling back to test fixture..."
        }
    fi
    
    # If still doesn't exist (either example wasn't found or copy failed), try test fixture
    if [ ! -f "${CONFIG_FILE}" ] && [ -f "${TEST_FILE}" ]; then
        echo "Copying airports.json from test fixture..."
        cp "${TEST_FILE}" "${CONFIG_FILE}" 2>/dev/null || {
            echo "⚠️  Warning: Could not write to ${CONFIG_FILE} (read-only mount?)"
        }
    fi
    
    # Verify file was created
    if [ -f "${CONFIG_FILE}" ]; then
        chmod 640 "${CONFIG_FILE}" 2>/dev/null || true
        chown root:www-data "${CONFIG_FILE}" 2>/dev/null || true
        echo "✓ Created airports.json from fallback source"
    else
        echo "⚠️  Warning: airports.json not found and could not create from fallback sources"
        echo "  Expected locations:"
        echo "    - ${CONFIG_FILE} (mounted or should be created)"
        echo "    - ${EXAMPLE_FILE} (fallback)"
        echo "    - ${TEST_FILE} (fallback)"
        echo "  Container will continue, but application may fail without valid config"
    fi
fi

# Secure airports.json permissions (prevents SFTP users from reading sensitive config)
# airports.json contains API keys, passwords, and other secrets
# Permissions: 640 (root read/write, www-data read, others none)
echo "Securing config file permissions..."
SECURE_CONFIG_FILES=(
    "/var/www/html/config/airports.json"
    "/var/www/html/secrets/airports.json"
    "/home/aviationwx/airports.json"
)
for config_file in "${SECURE_CONFIG_FILES[@]}"; do
    if [ -f "$config_file" ]; then
        chmod 640 "$config_file" 2>/dev/null || true
        chown root:www-data "$config_file" 2>/dev/null || true
    fi
done
echo "✓ Config files secured (640 root:www-data)"

# Start cron daemon in background
echo "Starting cron daemon..."
cron

# Reload cron configuration to ensure it picks up crontab files
# This is especially important on macOS Docker where cron may not auto-reload
sleep 2
if pgrep -x cron > /dev/null; then
    # Send SIGHUP to reload cron configuration
    pkill -HUP cron 2>/dev/null || true
    echo "✓ Cron daemon started and configuration reloaded"
else
    echo "⚠️  Warning: Cron daemon may not have started properly"
fi

# Start scheduler daemon
echo "Starting scheduler daemon..."
nohup /usr/local/bin/php /var/www/html/scripts/scheduler.php > /dev/null 2>&1 &
SCHEDULER_PID=$!
echo "✓ Scheduler started (PID: $SCHEDULER_PID)"

# Initialize cache directory with correct permissions
# This is critical after reboots when /tmp is cleared and the mount point
# may be created with wrong ownership/permissions
echo "Initializing cache directory..."
CACHE_DIR="/var/www/html/cache"
WEBCAM_CACHE_DIR="${CACHE_DIR}/webcams"
WEATHER_CACHE_DIR="${CACHE_DIR}/weather"
UPLOADS_CACHE_DIR="${CACHE_DIR}/uploads"

# Create cache directories if they don't exist
if [ ! -d "${CACHE_DIR}" ]; then
    echo "Creating cache directory: ${CACHE_DIR}"
    mkdir -p "${CACHE_DIR}"
fi

if [ ! -d "${WEBCAM_CACHE_DIR}" ]; then
    echo "Creating webcam cache directory: ${WEBCAM_CACHE_DIR}"
    mkdir -p "${WEBCAM_CACHE_DIR}"
fi

if [ ! -d "${WEATHER_CACHE_DIR}" ]; then
    echo "Creating weather cache directory: ${WEATHER_CACHE_DIR}"
    mkdir -p "${WEATHER_CACHE_DIR}"
    mkdir -p "${WEATHER_CACHE_DIR}/history"
fi

# Set ownership to www-data:www-data (UID 33, GID 33)
# This ensures the web server can write to the cache directory
if [ -d "${CACHE_DIR}" ]; then
    # Try to change ownership - may fail if not running as root, but that's OK
    # The directory might already have correct ownership
    chown -R www-data:www-data "${CACHE_DIR}" 2>/dev/null || {
        echo "Warning: Could not change ownership of cache directory (may already be correct)"
    }
    
    # Set permissions: 755 for parent, 775 for subdirectories (group writable)
    chmod 755 "${CACHE_DIR}" 2>/dev/null || true
    if [ -d "${WEBCAM_CACHE_DIR}" ]; then
        chmod 775 "${WEBCAM_CACHE_DIR}" 2>/dev/null || true
    fi
    if [ -d "${WEATHER_CACHE_DIR}" ]; then
        chmod 775 "${WEATHER_CACHE_DIR}" 2>/dev/null || true
    fi
    
    echo "✓ Cache directory initialized"
    
    # Clear circuit breaker state on container startup
    # This ensures fresh circuit breaker state after code deployments
    # that may change circuit breaker logic
    echo "Clearing circuit breaker state..."
    if [ -f /var/www/html/scripts/deploy-clear-circuit-breakers.php ]; then
        if php /var/www/html/scripts/deploy-clear-circuit-breakers.php 2>&1; then
            echo "✓ Circuit breakers cleared successfully"
        else
            echo "⚠️  Warning: Circuit breaker clearing script returned non-zero exit code"
            echo "   Continuing startup anyway..."
        fi
    else
        # Fallback: manually clear the main backoff.json file
        if [ -f "${CACHE_DIR}/backoff.json" ]; then
            rm -f "${CACHE_DIR}/backoff.json" && echo "✓ Cleared circuit breaker state (fallback)"
        fi
    fi
else
    echo "⚠️  Warning: Cache directory does not exist and could not be created"
fi

# Initialize log directory with correct permissions
# This directory stores file-based logs for cron jobs and Apache
echo "Initializing log directory..."
LOG_DIR="/var/log/aviationwx"

# Create log directory if it doesn't exist
if [ ! -d "${LOG_DIR}" ]; then
    echo "Creating log directory: ${LOG_DIR}"
    mkdir -p "${LOG_DIR}"
fi

# Set ownership to www-data:www-data for most logs
# Some cron jobs run as root, but we'll allow both to write
if [ -d "${LOG_DIR}" ]; then
    # Try to change ownership - may fail if not running as root, but that's OK
    chown -R www-data:www-data "${LOG_DIR}" 2>/dev/null || {
        echo "Warning: Could not change ownership of log directory (may already be correct)"
    }
    
    # Set permissions: 755 for directory, allow group write for cron (root) and www-data
    chmod 755 "${LOG_DIR}" 2>/dev/null || true
    
    # Create initial log files with proper permissions
    touch "${LOG_DIR}/cron-webcam.log" \
          "${LOG_DIR}/cron-weather.log" \
          "${LOG_DIR}/cron-push-webcams.log" \
          "${LOG_DIR}/cron-heartbeat.log" \
          "${LOG_DIR}/apache-access.log" \
          "${LOG_DIR}/apache-error.log" \
          "${LOG_DIR}/sshd.log" \
          "${LOG_DIR}/service-watchdog.log" \
          "${LOG_DIR}/app.log" \
          "${LOG_DIR}/user.log" 2>/dev/null || true
    
    # Set ownership: www-data for most logs, root for system logs
    # Use 775 permissions on directory to allow both www-data and root to write
    chmod 775 "${LOG_DIR}" 2>/dev/null || true
    chown www-data:www-data "${LOG_DIR}"/*.log 2>/dev/null || true
    chmod 644 "${LOG_DIR}"/*.log 2>/dev/null || true
    # System logs owned by root
    chown root:root "${LOG_DIR}/sshd.log" "${LOG_DIR}/service-watchdog.log" 2>/dev/null || true
    chmod 644 "${LOG_DIR}/sshd.log" "${LOG_DIR}/service-watchdog.log" 2>/dev/null || true
    # Ensure heartbeat log is writable by both www-data and root
    chmod 666 "${LOG_DIR}/cron-heartbeat.log" 2>/dev/null || true
    
    echo "✓ Log directory initialized"
else
    echo "⚠️  Warning: Log directory does not exist and could not be created"
fi

# Initialize FTP uploads directory (for vsftpd virtual users)
echo "Initializing FTP uploads directory..."
FTP_DIR="${CACHE_DIR}/ftp"

# Create FTP directory if it doesn't exist
if [ ! -d "${FTP_DIR}" ]; then
    echo "Creating FTP uploads directory: ${FTP_DIR}"
    mkdir -p "${FTP_DIR}"
fi

# FTP uploads use simple directory structure (no chroot needed for vsftpd)
chown root:root "${FTP_DIR}" 2>/dev/null || true
chmod 755 "${FTP_DIR}" 2>/dev/null || true

echo "✓ FTP uploads directory initialized at ${FTP_DIR}"

# Initialize SFTP directory (completely separate from cache for SSH chroot)
# SSH ChrootDirectory requires ALL parent directories to be root-owned
# /var/sftp/ works because /var/ is already root:root
echo "Initializing SFTP directory..."
SFTP_DIR="/var/sftp"

# Create SFTP directory if it doesn't exist
if [ ! -d "${SFTP_DIR}" ]; then
    echo "Creating SFTP directory: ${SFTP_DIR}"
    mkdir -p "${SFTP_DIR}"
fi

# Set strict ownership for SFTP chroot requirements
# CRITICAL: Must be root:root 755 for SSH ChrootDirectory to work
chown root:root "${SFTP_DIR}" 2>/dev/null || true
chmod 755 "${SFTP_DIR}" 2>/dev/null || true

echo "✓ SFTP directory initialized at ${SFTP_DIR}"

# Configure vsftpd pasv_address
# Priority: 1) config.public_ip (explicit), 2) config.upload_hostname (DNS), 3) default DNS fallback
# Using single dual-stack instance (listen_ipv6=YES handles both IPv4 and IPv6)
echo "Configuring pasv_address..."
VSFTPD_PID=""
PASV_ADDRESS=""

# Step 1: Try to read public_ip from airports.json config (explicit configuration)
if [ -f "$CONFIG_FILE" ]; then
    CONFIG_PUBLIC_IP=$(php -r "
        \$config = @json_decode(file_get_contents('$CONFIG_FILE'), true);
        if (\$config && isset(\$config['config']['public_ip'])) {
            \$ip = \$config['config']['public_ip'];
            if (filter_var(\$ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                echo \$ip;
            }
        }
    " 2>/dev/null || true)
    
    if [ -n "$CONFIG_PUBLIC_IP" ]; then
        PASV_ADDRESS="$CONFIG_PUBLIC_IP"
        echo "✓ Using explicit public_ip from config: $PASV_ADDRESS"
    fi
fi

# Step 2: If no explicit IP, try upload_hostname from config (DNS resolution)
if [ -z "$PASV_ADDRESS" ] && [ -f "$CONFIG_FILE" ]; then
    CONFIG_UPLOAD_HOSTNAME=$(php -r "
        \$config = @json_decode(file_get_contents('$CONFIG_FILE'), true);
        if (\$config && isset(\$config['config']['upload_hostname']) && !empty(\$config['config']['upload_hostname'])) {
            echo \$config['config']['upload_hostname'];
        } elseif (\$config && isset(\$config['config']['base_domain']) && !empty(\$config['config']['base_domain'])) {
            echo 'upload.' . \$config['config']['base_domain'];
        }
    " 2>/dev/null || true)
    
    if [ -n "$CONFIG_UPLOAD_HOSTNAME" ] && [ -f "/usr/local/bin/resolve-upload-ip.sh" ]; then
        RESOLVED_IP=$(/usr/local/bin/resolve-upload-ip.sh "$CONFIG_UPLOAD_HOSTNAME" "ipv4" 2>&1 | grep -E '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$' | head -1 || true)
        if [ -n "$RESOLVED_IP" ]; then
            PASV_ADDRESS="$RESOLVED_IP"
            echo "✓ Resolved upload_hostname ($CONFIG_UPLOAD_HOSTNAME) to: $PASV_ADDRESS"
        else
            echo "⚠️  Warning: Failed to resolve upload_hostname: $CONFIG_UPLOAD_HOSTNAME"
        fi
    fi
fi

# Step 3: Fallback to default DNS resolution (upload.aviationwx.org)
if [ -z "$PASV_ADDRESS" ] && [ -f "/usr/local/bin/resolve-upload-ip.sh" ]; then
    echo "Falling back to DNS resolution for upload.aviationwx.org..."
    RESOLVED_IP=$(/usr/local/bin/resolve-upload-ip.sh "upload.aviationwx.org" "ipv4" 2>&1 | grep -E '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$' | head -1 || true)
    
    if [ -n "$RESOLVED_IP" ]; then
        PASV_ADDRESS="$RESOLVED_IP"
        echo "✓ Resolved pasv_address via fallback DNS: $PASV_ADDRESS"
    else
        echo "⚠️  Warning: Failed to resolve IPv4 address for pasv_address"
    fi
fi

if [ -z "$PASV_ADDRESS" ]; then
    echo "⚠️  Warning: Could not determine pasv_address - FTP passive mode may not work correctly"
fi

# Update vsftpd.conf with pasv_address
VSFTPD_CONF="/etc/vsftpd/vsftpd.conf"
if [ -f "$VSFTPD_CONF" ]; then
    if [ -n "$PASV_ADDRESS" ]; then
        # Add or update pasv_address
        if grep -q "^pasv_address=" "$VSFTPD_CONF"; then
            sed -i "s|^pasv_address=.*|pasv_address=$PASV_ADDRESS|" "$VSFTPD_CONF"
        else
            echo "pasv_address=$PASV_ADDRESS" >> "$VSFTPD_CONF"
        fi
        echo "✓ Updated vsftpd pasv_address to: $PASV_ADDRESS"
    fi
else
    echo "Error: vsftpd config not found at $VSFTPD_CONF"
    exit 1
fi

# Enable SSL in vsftpd configs if certificates are available and valid
# Start without SSL if certs don't exist or are invalid to allow vsftpd to start
# on first deployment; SSL can be enabled later via enable-vsftpd-ssl.sh
# Uses wildcard certificate (*.aviationwx.org) which covers upload.aviationwx.org
CERT_DIR="/etc/letsencrypt/live/aviationwx.org"
CERT_FILE="${CERT_DIR}/fullchain.pem"
KEY_FILE="${CERT_DIR}/privkey.pem"
SSL_ENABLED=false

if [ -f "$CERT_FILE" ] && [ -f "$KEY_FILE" ]; then
    # Validate certificates before enabling SSL to prevent vsftpd from crashing
    if [ ! -r "$CERT_FILE" ] || [ ! -r "$KEY_FILE" ]; then
        echo "⚠️  Warning: SSL certificate files exist but are not readable"
        echo "   vsftpd will start without SSL - certificates can be enabled later"
        echo "   Certificate file: $CERT_FILE"
        echo "   Key file: $KEY_FILE"
        echo "   Run enable-vsftpd-ssl.sh or restart container when certificates are fixed"
    elif ! openssl x509 -in "$CERT_FILE" -noout -text >/dev/null 2>&1; then
        echo "⚠️  Warning: SSL certificate file appears to be invalid or corrupted"
        echo "   vsftpd will start without SSL - certificates can be enabled later"
        echo "   Certificate file: $CERT_FILE"
        echo "   Run enable-vsftpd-ssl.sh or restart container when certificates are fixed"
    else
        # Validate private key - try multiple methods for compatibility
        # Try openssl rsa first (most compatible), then pkey (newer OpenSSL)
        # Note: openssl binary is already verified to exist (used in x509 check above)
        KEY_VALID=false
        if openssl rsa -in "$KEY_FILE" -check -noout >/dev/null 2>&1; then
            KEY_VALID=true
        elif openssl rsa -in "$KEY_FILE" -noout >/dev/null 2>&1; then
            KEY_VALID=true
        elif openssl pkey -in "$KEY_FILE" -noout >/dev/null 2>&1; then
            KEY_VALID=true
        fi
        
        if [ "$KEY_VALID" = false ]; then
            echo "⚠️  Warning: SSL private key file appears to be invalid or corrupted"
            echo "   vsftpd will start without SSL - certificates can be enabled later"
            echo "   Key file: $KEY_FILE"
            echo "   Run enable-vsftpd-ssl.sh or restart container when certificates are fixed"
        else
            SSL_ENABLED=true
            enable_ssl_in_config() {
            local config_file="$1"
            if [ ! -f "$config_file" ]; then
                return 0
            fi
            
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
            # Note: Only ssl_tlsv1 is widely supported; ssl_tlsv1_1 and ssl_tlsv1_2 are not supported by all vsftpd versions
            sed -i 's/^# ssl_tlsv1=YES/ssl_tlsv1=YES/' "$config_file"
            sed -i 's/^ssl_tlsv1=NO/ssl_tlsv1=YES/' "$config_file"
            # Remove unsupported TLS version settings if present
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
            
            # SSL/TLS settings - require_ssl_reuse=NO for broad client compatibility
            sed -i 's/^# require_ssl_reuse=NO/require_ssl_reuse=NO/' "$config_file"
            sed -i 's/^require_ssl_reuse=YES/require_ssl_reuse=NO/' "$config_file"
            if ! grep -q "^require_ssl_reuse=" "$config_file" 2>/dev/null; then
                echo "require_ssl_reuse=NO" >> "$config_file"
            fi
            
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
            
            # Ensure ssl_tlsv1 is set (only TLS version widely supported across vsftpd versions)
            if ! grep -q "^ssl_tlsv1=" "$config_file" 2>/dev/null; then
                echo "ssl_tlsv1=YES" >> "$config_file"
            fi
        }
        
            echo "SSL certificates found and validated, enabling SSL in vsftpd config..."
            enable_ssl_in_config "/etc/vsftpd/vsftpd.conf"
            echo "✓ SSL enabled in vsftpd config"
        fi
    fi
else
    echo "SSL certificates not found - vsftpd will run without SSL/TLS"
    echo "   Expected certificate: $CERT_FILE"
    echo "   Expected key: $KEY_FILE"
    echo "   vsftpd will start without SSL - SSL can be enabled later when certificates are available"
    echo "   Run enable-vsftpd-ssl.sh or restart container after obtaining certificates"
fi

# Disable SSL in config if certificates are invalid/missing to prevent vsftpd crashes
if [ "$SSL_ENABLED" = false ]; then
    if [ -f "/etc/vsftpd/vsftpd.conf" ]; then
        sed -i 's/^ssl_enable=YES/ssl_enable=NO/' "/etc/vsftpd/vsftpd.conf" 2>/dev/null || true
        sed -i 's|^rsa_cert_file=.*|# rsa_cert_file=|' "/etc/vsftpd/vsftpd.conf" 2>/dev/null || true
        sed -i 's|^rsa_private_key_file=.*|# rsa_private_key_file=|' "/etc/vsftpd/vsftpd.conf" 2>/dev/null || true
    fi
fi

# Start vsftpd instances (only if IPs were resolved)
echo "Starting vsftpd..."

# Start vsftpd instance and verify it's healthy
# Uses process check + port listening check instead of config validation
# (config validation with vsftpd -olisten=NO hangs indefinitely)
start_vsftpd_instance() {
    local config_file="$1"
    local instance_name="$2"
    local pid_var="$3"
    local listen_port="${4:-2121}"  # Default to 2121, can be overridden
    
    # Validate port number (1-65535)
    if ! [[ "$listen_port" =~ ^[0-9]+$ ]] || [ "$listen_port" -lt 1 ] || [ "$listen_port" -gt 65535 ]; then
        echo "⚠️  Warning: Invalid port number: $listen_port, using default 2121"
        listen_port=2121
    fi
    
    # Basic config file validation (exists and readable)
    if [ ! -f "$config_file" ]; then
        echo "⚠️  Warning: Config file not found: $config_file"
        return 1
    fi
    
    if [ ! -r "$config_file" ]; then
        echo "⚠️  Warning: Config file not readable: $config_file"
        return 1
    fi
    
    echo "Starting $instance_name instance..."
    vsftpd "$config_file" &
    local pid=$!
    eval "$pid_var=$pid"
    
    # Wait for vsftpd to start and bind to port
    # Poll up to 3 seconds (6 iterations of 0.5s)
    local max_iterations=6
    local iteration=0
    local process_ok=false
    local port_ok=false
    
    # Determine which port check command to use (ss preferred, netstat fallback)
    local port_check_cmd=""
    if command -v ss >/dev/null 2>&1; then
        port_check_cmd="ss -tuln"
    elif command -v netstat >/dev/null 2>&1; then
        port_check_cmd="netstat -tuln"
    else
        echo "⚠️  Warning: Neither ss nor netstat available, skipping port check"
        port_ok=true  # Assume OK if we can't check
    fi
    
    while [ $iteration -lt $max_iterations ]; do
        # Check if process is still running
        if kill -0 $pid 2>/dev/null; then
            process_ok=true
            
            # Check if port is listening (more reliable than just process check)
            # Use flexible regex to match port at end of line or followed by space
            if [ -n "$port_check_cmd" ]; then
                if $port_check_cmd 2>/dev/null | grep -qE ":$listen_port[[:space:]]|:$listen_port\$"; then
                    port_ok=true
                    break
                fi
            fi
        else
            # Process died - check why
            echo "⚠️  Warning: $instance_name process exited during startup"
            echo "   This may indicate a configuration error or port conflict"
            eval "$pid_var=\"\""
            return 1
        fi
        
        sleep 0.5
        iteration=$((iteration + 1))
    done
    
    # Final verification
    if [ "$process_ok" = true ] && [ "$port_ok" = true ]; then
        echo "✓ $instance_name started and listening on port $listen_port (PID: $pid)"
        return 0
    elif [ "$process_ok" = true ]; then
        echo "⚠️  Warning: $instance_name process is running but port $listen_port is not listening"
        echo "   vsftpd may still be initializing, or there may be a port binding issue"
        echo "   This is non-fatal - container will continue to start"
        # Keep PID - process is running, may bind later
        return 0
    else
        echo "⚠️  Warning: $instance_name failed to start"
        echo "   This is non-fatal - container will continue to start"
        eval "$pid_var=\"\""
        return 1
    fi
}

# Start single dual-stack vsftpd instance (handles both IPv4 and IPv6)
if [ -f "/etc/vsftpd/vsftpd.conf" ]; then
    start_vsftpd_instance "/etc/vsftpd/vsftpd.conf" "vsftpd (dual-stack)" "VSFTPD_PID" || true
else
    echo "⚠️  Warning: vsftpd config not found - FTP service will not be available"
    echo "   Web service will continue to function normally"
fi

# Give vsftpd a moment to start
sleep 1

# Configure and start rsyslog for sshd dedicated logging
if command -v rsyslogd >/dev/null 2>&1; then
    # Ensure rsyslog config directory exists
    mkdir -p /etc/rsyslog.d
    # Create rsyslog config for sshd if it doesn't exist
    if [ ! -f /etc/rsyslog.d/20-sshd.conf ]; then
        cat > /etc/rsyslog.d/20-sshd.conf << 'EOF'
# rsyslog configuration for sshd
# Routes sshd logs (LOCAL0 facility) to dedicated log file
if $programname == 'sshd' or $syslogfacility-text == 'local0' then /var/log/aviationwx/sshd.log
& stop
EOF
    fi
    # Ensure log directory exists for sshd logs (should already exist from earlier init)
    mkdir -p /var/log/aviationwx
    touch /var/log/aviationwx/sshd.log
    chown root:root /var/log/aviationwx/sshd.log
    chmod 644 /var/log/aviationwx/sshd.log
    # Start rsyslog in background (non-blocking)
    echo "Starting rsyslog for sshd logging..."
    rsyslogd -n &
    sleep 1
    if pgrep -x rsyslogd > /dev/null; then
        echo "✓ rsyslog started for sshd logging"
    else
        echo "⚠️  Warning: rsyslog failed to start, sshd will log to auth.log"
    fi
fi

# Start sshd (if not already running)
echo "Starting sshd..."
service ssh start || {
    echo "Error: sshd failed to start"
    exit 1
}

# Verify vsftpd is running (non-fatal - web service is more critical)
if [ -n "$VSFTPD_PID" ]; then
    if ! kill -0 $VSFTPD_PID 2>/dev/null; then
        echo "⚠️  Warning: vsftpd is not running (non-fatal)"
        VSFTPD_PID=""
    fi
fi

if ! pgrep -x sshd > /dev/null; then
    echo "Error: sshd is not running"
    exit 1
fi

# Verify ports are listening (give services a moment to bind)
sleep 2
if ! netstat -tuln 2>/dev/null | grep -q ':2121\|:2122\|:2222'; then
    echo "Warning: FTP/SFTP ports may not be listening yet"
fi

echo "All services started successfully"

# Start service watchdog in background
echo "Starting service watchdog..."
/usr/local/bin/service-watchdog.sh &
WATCHDOG_PID=$!

# Trap signals to clean up watchdog on exit
trap "kill $WATCHDOG_PID 2>/dev/null || true" EXIT

# Start fail2ban
echo "Starting fail2ban..."
# Ensure log files exist
touch /var/log/vsftpd.log /var/log/auth.log
chmod 644 /var/log/vsftpd.log /var/log/auth.log

# Start fail2ban server in background
# Use systemd service if available, otherwise start directly
if command -v systemctl >/dev/null 2>&1 && systemctl is-system-running >/dev/null 2>&1; then
    systemctl start fail2ban || fail2ban-server -x &
else
    # Start fail2ban server directly in background
    fail2ban-server -x &
fi
FAIL2BAN_PID=$!

# Wait a moment for fail2ban to start
sleep 3

# Verify fail2ban is running
if pgrep -x fail2ban-server > /dev/null || pgrep -f "fail2ban-server" > /dev/null; then
    echo "✓ fail2ban started successfully"
    # Wait a bit more for jails to initialize
    sleep 2
    # Show active jails
    fail2ban-client status 2>/dev/null | grep -A 10 "Jail list" || echo "  (jails initializing...)"
else
    echo "⚠️  Warning: fail2ban may not have started properly"
    echo "This is non-fatal - services will continue without fail2ban protection"
fi

# Trap signals to clean up fail2ban on exit
trap "kill $WATCHDOG_PID $FAIL2BAN_PID 2>/dev/null || true" EXIT

# Sync FTP/SFTP/FTPS configuration on container startup
# This ensures users and directories are created/updated when container starts
# Runs as root to write to /etc/vsftpd/ and create system users
# Run in background to avoid blocking Apache startup (non-critical for web service)
echo "Syncing FTP/SFTP/FTPS configuration (background)..."
(cd /var/www/html && timeout 30 /usr/local/bin/php scripts/sync-push-config.php > /tmp/sync-push-config.log 2>&1 && \
    echo "✓ FTP/SFTP/FTPS configuration synced successfully" || \
    echo "⚠️  Warning: FTP/SFTP/FTPS configuration sync failed or timed out (check /tmp/sync-push-config.log)") &
SYNC_PID=$!

# Don't wait for sync to complete - Apache can start immediately
# The sync will complete in background, and if it fails, it will retry on next startup

# Configure Apache port and bind address based on environment
# Production (APP_ENV=production): Listen on 127.0.0.1:8080 (internal, behind nginx)
# Local/CI: Listen on 0.0.0.0:80 (bridge networking with port mapping)
if [ "${APP_ENV:-}" = "production" ]; then
    echo "Configuring Apache for production (127.0.0.1:8080)..."
    # Configure Apache to listen on 127.0.0.1:8080 (localhost only, not 0.0.0.0)
    # This ensures port 8080 is only accessible from localhost (nginx can proxy)
    # Try exact match first, then fallback to more specific pattern
    if grep -q "^Listen 80$" /etc/apache2/ports.conf 2>/dev/null; then
        sed -i 's/^Listen 80$/Listen 127.0.0.1:8080/' /etc/apache2/ports.conf
    elif grep -q "^Listen " /etc/apache2/ports.conf 2>/dev/null; then
        # Only replace the first Listen directive (default one)
        sed -i '0,/^Listen /s/^Listen .*/Listen 127.0.0.1:8080/' /etc/apache2/ports.conf
    else
        echo "Listen 127.0.0.1:8080" >> /etc/apache2/ports.conf
    fi
    
    # Configure VirtualHost - try exact match first
    if grep -q "<VirtualHost \*:80>" /etc/apache2/sites-available/000-default.conf 2>/dev/null; then
        sed -i 's/<VirtualHost \*:80>/<VirtualHost 127.0.0.1:8080>/' /etc/apache2/sites-available/000-default.conf
    elif grep -q "<VirtualHost \*:" /etc/apache2/sites-available/000-default.conf 2>/dev/null; then
        # Only replace the first VirtualHost directive (default one)
        sed -i '0,/<VirtualHost \*:/s/<VirtualHost \*:[0-9]*>/<VirtualHost 127.0.0.1:8080>/' /etc/apache2/sites-available/000-default.conf
    fi
    
    # Validate configuration
    if apache2ctl configtest > /dev/null 2>&1; then
        echo "✓ Apache configured for production (127.0.0.1:8080)"
    else
        echo "⚠️  Warning: Apache configuration test failed, but continuing..."
        apache2ctl configtest 2>&1 | head -5 || true
    fi
else
    echo "Configuring Apache for local/CI (0.0.0.0:80)..."
    # Ensure Apache listens on 0.0.0.0:80 for bridge networking
    # Try exact match first (from production config)
    if grep -q "^Listen 127\.0\.0\.1:8080$" /etc/apache2/ports.conf 2>/dev/null; then
        sed -i 's/^Listen 127\.0\.0\.1:8080$/Listen 80/' /etc/apache2/ports.conf
    elif grep -q "^Listen " /etc/apache2/ports.conf 2>/dev/null; then
        # Only replace the first Listen directive
        sed -i '0,/^Listen /s/^Listen .*/Listen 80/' /etc/apache2/ports.conf
    else
        echo "Listen 80" >> /etc/apache2/ports.conf
    fi
    
    # Configure VirtualHost - try exact match first (from production config)
    if grep -q "<VirtualHost 127\.0\.0\.1:8080>" /etc/apache2/sites-available/000-default.conf 2>/dev/null; then
        sed -i 's/<VirtualHost 127\.0\.0\.1:8080>/<VirtualHost *:80>/' /etc/apache2/sites-available/000-default.conf
    elif grep -q "<VirtualHost \*:" /etc/apache2/sites-available/000-default.conf 2>/dev/null; then
        # Only replace the first VirtualHost directive
        sed -i '0,/<VirtualHost \*:/s/<VirtualHost \*:[0-9]*>/<VirtualHost *:80>/' /etc/apache2/sites-available/000-default.conf
    fi
    
    # Validate configuration
    if apache2ctl configtest > /dev/null 2>&1; then
        echo "✓ Apache configured for local/CI (0.0.0.0:80)"
    else
        echo "⚠️  Warning: Apache configuration test failed, but continuing..."
        apache2ctl configtest 2>&1 | head -5 || true
    fi
fi

# Execute Apache entrypoint (starts Apache in foreground)
# Use docker-php-entrypoint if available, otherwise call apache2-foreground directly
if command -v docker-php-entrypoint >/dev/null 2>&1; then
    exec docker-php-entrypoint apache2-foreground
else
    exec apache2-foreground
fi

