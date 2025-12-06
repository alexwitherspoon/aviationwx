#!/bin/bash
# Comprehensive FTP/SFTP Diagnostic Script
# Run this inside the Docker container or on the host

set -e

echo "=========================================="
echo "FTP/SFTP Diagnostic Script"
echo "=========================================="
echo ""

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Check if running in Docker or on host
if [ -f /.dockerenv ] || [ -n "$DOCKER_CONTAINER" ]; then
    echo "Running inside Docker container"
    DOCKER_PREFIX=""
    RUN_CMD=""
else
    echo "Running on host - will use docker compose exec"
    # Check which compose file exists
    if [ -f "docker/docker-compose.prod.yml" ]; then
        DOCKER_PREFIX="docker compose -f docker/docker-compose.prod.yml exec web"
    elif [ -f "docker/docker-compose.local.yml" ]; then
        DOCKER_PREFIX="docker compose -f docker/docker-compose.local.yml exec web"
    else
        DOCKER_PREFIX="docker compose exec web"
    fi
    RUN_CMD="$DOCKER_PREFIX"
fi

echo ""
echo "=========================================="
echo "1. CHECKING PUSH CAMERA CONFIGURATION"
echo "=========================================="

# Check if push cameras are configured
$RUN_CMD php -r "
require '/var/www/html/lib/config.php';
\$config = loadConfig(false);
if (!\$config) {
    echo 'ERROR: Config load failed\n';
    exit(1);
}
\$found = false;
foreach (\$config['airports'] ?? [] as \$aid => \$ap) {
    foreach (\$ap['webcams'] ?? [] as \$i => \$cam) {
        \$isPush = (isset(\$cam['type']) && \$cam['type'] === 'push') || isset(\$cam['push_config']);
        if (\$isPush && isset(\$cam['push_config'])) {
            \$found = true;
            \$p = \$cam['push_config']['protocol'] ?? 'none';
            \$u = \$cam['push_config']['username'] ?? 'none';
            \$port = \$cam['push_config']['port'] ?? 'default';
            echo \"Found: \$aid cam \$i - Protocol: \$p - Username: \$u - Port: \$port\n\";
        }
    }
}
if (!\$found) {
    echo 'No push cameras found in configuration\n';
}
"

echo ""
echo "=========================================="
echo "2. CHECKING SYNC STATUS"
echo "=========================================="

# Check last sync
$RUN_CMD cat /var/www/html/cache/push_webcams/last_sync.json 2>/dev/null || echo "No sync timestamp found"

# Check username mapping
echo ""
echo "Username mapping:"
$RUN_CMD cat /var/www/html/cache/push_webcams/username_mapping.json 2>/dev/null || echo "No username mapping found"

echo ""
echo "=========================================="
echo "3. CHECKING FTP USERS (vsftpd)"
echo "=========================================="

# Check virtual users file
echo "Virtual users file:"
$RUN_CMD cat /etc/vsftpd/virtual_users.txt 2>/dev/null || echo "File not found or empty"

# Check user config files
echo ""
echo "User config files:"
$RUN_CMD ls -la /etc/vsftpd/users/ 2>/dev/null || echo "Directory not found"

# Check if db file exists
echo ""
echo "Virtual users database:"
$RUN_CMD ls -lh /etc/vsftpd/virtual_users.db 2>/dev/null || echo "Database file not found"

echo ""
echo "=========================================="
echo "4. CHECKING SFTP USERS (system users)"
echo "=========================================="

# Check for SFTP users
echo "SFTP users in webcam_users group:"
$RUN_CMD getent group webcam_users 2>/dev/null || echo "Group not found"

# List users
$RUN_CMD awk -F: '/webcam_users/ {print $4}' /etc/group 2>/dev/null | tr ',' '\n' | while read user; do
    if [ -n "$user" ]; then
        echo "  User: $user"
        $RUN_CMD getent passwd "$user" 2>/dev/null | awk -F: '{print "    Home: " $6}'
    fi
done

echo ""
echo "=========================================="
echo "5. CHECKING DIRECTORY STRUCTURE"
echo "=========================================="

# Check base directories
echo "Base uploads directory:"
$RUN_CMD ls -ld /var/www/html/uploads 2>/dev/null || echo "Not found"

echo ""
echo "Webcams directory:"
$RUN_CMD ls -ld /var/www/html/uploads/webcams 2>/dev/null || echo "Not found"

# Check for camera directories
echo ""
echo "Camera directories:"
$RUN_CMD ls -la /var/www/html/uploads/webcams/ 2>/dev/null || echo "No camera directories found"

# Check specific camera directories
$RUN_CMD find /var/www/html/uploads/webcams -maxdepth 1 -type d -name "*_*" 2>/dev/null | while read dir; do
    if [ -n "$dir" ]; then
        echo ""
        echo "Directory: $dir"
        $RUN_CMD ls -la "$dir" 2>/dev/null
        if [ -d "$dir/incoming" ]; then
            echo "  Incoming directory:"
            $RUN_CMD ls -la "$dir/incoming" 2>/dev/null
        fi
    fi
done

echo ""
echo "=========================================="
echo "6. CHECKING SERVICES"
echo "=========================================="

# Check vsftpd
echo "vsftpd process:"
$RUN_CMD pgrep -a vsftpd || echo "vsftpd not running"

# Check sshd
echo ""
echo "sshd process:"
$RUN_CMD pgrep -a sshd || echo "sshd not running"

# Check listening ports
echo ""
echo "Listening ports:"
$RUN_CMD netstat -tlnp 2>/dev/null | grep -E "2121|2122|2222" || echo "No FTP/SFTP ports listening"

echo ""
echo "=========================================="
echo "7. CHECKING VSFTPD CONFIGURATION"
echo "=========================================="

# Check vsftpd config
echo "vsftpd.conf key settings:"
$RUN_CMD grep -E "listen_port|guest_enable|guest_username|user_config_dir|ssl_enable|local_root" /etc/vsftpd.conf 2>/dev/null || echo "Config file not found"

echo ""
echo "=========================================="
echo "8. CHECKING RECENT LOGS"
echo "=========================================="

# Check vsftpd logs
echo "Recent vsftpd log entries (last 20):"
$RUN_CMD tail -20 /var/log/vsftpd.log 2>/dev/null || echo "No vsftpd log entries"

# Check auth logs for SFTP
echo ""
echo "Recent SSH/SFTP log entries (last 20):"
$RUN_CMD tail -20 /var/log/auth.log 2>/dev/null | grep -i "sftp\|ssh" || echo "No SSH/SFTP log entries"

# Check application logs
echo ""
echo "Recent sync-push-config log entries:"
$RUN_CMD journalctl -u cron -n 50 2>/dev/null | grep -i "sync-push-config" || echo "No cron logs found (check Docker logs instead)"

echo ""
echo "=========================================="
echo "9. TESTING CONNECTIONS (if credentials available)"
echo "=========================================="

# Get first push camera config for testing
TEST_USER=$($RUN_CMD php -r "
require '/var/www/html/lib/config.php';
\$config = loadConfig(false);
if (!\$config) exit(1);
foreach (\$config['airports'] ?? [] as \$aid => \$ap) {
    foreach (\$ap['webcams'] ?? [] as \$i => \$cam) {
        \$isPush = (isset(\$cam['type']) && \$cam['type'] === 'push') || isset(\$cam['push_config']);
        if (\$isPush && isset(\$cam['push_config']['username'])) {
            echo \$cam['push_config']['username'];
            exit(0);
        }
    }
}
" 2>/dev/null)

if [ -n "$TEST_USER" ]; then
    echo "Found test user: $TEST_USER"
    echo ""
    echo "Testing FTP connection (port 2121):"
    echo "USER $TEST_USER" | $RUN_CMD timeout 5 nc localhost 2121 2>/dev/null || echo "Connection failed"
    
    echo ""
    echo "Testing SFTP connection (port 2222):"
    echo "Testing if port is open..."
    $RUN_CMD timeout 2 bash -c "echo > /dev/tcp/localhost/2222" 2>/dev/null && echo "Port 2222 is open" || echo "Port 2222 is not accessible"
else
    echo "No push camera configured for testing"
fi

echo ""
echo "=========================================="
echo "DIAGNOSTIC COMPLETE"
echo "=========================================="

