#!/bin/bash
set -e

# Ensure config directory exists (needed for airports.json mount)
CONFIG_DIR="/var/www/html/config"
CONFIG_FILE="${CONFIG_DIR}/airports.json"
if [ ! -d "${CONFIG_DIR}" ]; then
    echo "Creating config directory: ${CONFIG_DIR}"
    mkdir -p "${CONFIG_DIR}"
    chown www-data:www-data "${CONFIG_DIR}" 2>/dev/null || true
fi

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
        chmod 644 "${CONFIG_FILE}" 2>/dev/null || true
        chown www-data:www-data "${CONFIG_FILE}" 2>/dev/null || true
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

# Start cron daemon in background
echo "Starting cron daemon..."
cron

# Initialize cache directory with correct permissions
# This is critical after reboots when /tmp is cleared and the mount point
# may be created with wrong ownership/permissions
echo "Initializing cache directory..."
CACHE_DIR="/var/www/html/cache"
WEBCAM_CACHE_DIR="${CACHE_DIR}/webcams"
PUSH_WEBCAM_CACHE_DIR="${CACHE_DIR}/push_webcams"

# Create cache directories if they don't exist
if [ ! -d "${CACHE_DIR}" ]; then
    echo "Creating cache directory: ${CACHE_DIR}"
    mkdir -p "${CACHE_DIR}"
fi

if [ ! -d "${WEBCAM_CACHE_DIR}" ]; then
    echo "Creating webcam cache directory: ${WEBCAM_CACHE_DIR}"
    mkdir -p "${WEBCAM_CACHE_DIR}"
fi

if [ ! -d "${PUSH_WEBCAM_CACHE_DIR}" ]; then
    echo "Creating push webcam cache directory: ${PUSH_WEBCAM_CACHE_DIR}"
    mkdir -p "${PUSH_WEBCAM_CACHE_DIR}"
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
    if [ -d "${PUSH_WEBCAM_CACHE_DIR}" ]; then
        chmod 775 "${PUSH_WEBCAM_CACHE_DIR}" 2>/dev/null || true
    fi
    
    echo "✓ Cache directory initialized"
else
    echo "⚠️  Warning: Cache directory does not exist and could not be created"
fi

# Initialize uploads directory (ephemeral, inside container only)
# Parent directories must be owned by root for SFTP chroot to work
echo "Initializing uploads directory..."
UPLOADS_DIR="/var/www/html/uploads"
UPLOADS_WEBCAMS_DIR="${UPLOADS_DIR}/webcams"

# Create uploads directories if they don't exist
if [ ! -d "${UPLOADS_DIR}" ]; then
    echo "Creating uploads directory: ${UPLOADS_DIR}"
    mkdir -p "${UPLOADS_DIR}"
fi

if [ ! -d "${UPLOADS_WEBCAMS_DIR}" ]; then
    echo "Creating webcams upload directory: ${UPLOADS_WEBCAMS_DIR}"
    mkdir -p "${UPLOADS_WEBCAMS_DIR}"
fi

# Set ownership to root:root for chroot requirements
# All parent directories must be root-owned for SSH chroot to work
chown root:root "${UPLOADS_DIR}" 2>/dev/null || true
chmod 755 "${UPLOADS_DIR}" 2>/dev/null || true

chown root:root "${UPLOADS_WEBCAMS_DIR}" 2>/dev/null || true
chmod 755 "${UPLOADS_WEBCAMS_DIR}" 2>/dev/null || true

echo "✓ Uploads directory initialized"

# Check if SSL certificates exist and enable SSL in vsftpd if available
if [ -f "/etc/letsencrypt/live/upload.aviationwx.org/fullchain.pem" ] && \
   [ -f "/etc/letsencrypt/live/upload.aviationwx.org/privkey.pem" ] && \
   [ -f "/usr/local/bin/enable-vsftpd-ssl.sh" ]; then
    echo "SSL certificates found, enabling SSL in vsftpd..."
    /usr/local/bin/enable-vsftpd-ssl.sh || {
        echo "Warning: Failed to enable SSL in vsftpd, starting without SSL"
    }
fi

# Start vsftpd
echo "Starting vsftpd..."
# Try to start vsftpd, if it fails, try to get error details
if ! service vsftpd start 2>&1; then
    echo "Error: vsftpd failed to start, checking configuration..."
    # Test configuration
    vsftpd -olisten=NO /etc/vsftpd.conf 2>&1 || true
    echo "Error: vsftpd failed to start"
    exit 1
fi

# Give vsftpd a moment to start
sleep 1

# Start sshd (if not already running)
echo "Starting sshd..."
service ssh start || {
    echo "Error: sshd failed to start"
    exit 1
}

# Verify services are running
if ! pgrep -x vsftpd > /dev/null; then
    echo "Error: vsftpd is not running"
    exit 1
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

# Execute Apache entrypoint (starts Apache in foreground)
# Use docker-php-entrypoint if available, otherwise call apache2-foreground directly
if command -v docker-php-entrypoint >/dev/null 2>&1; then
    exec docker-php-entrypoint apache2-foreground
else
    exec apache2-foreground
fi

