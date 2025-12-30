#!/bin/bash
# Fix FTP/FTPS upload permissions for a specific camera
# Usage: ./fix-ftp-permissions.sh <airport_id> <username>
# Example: ./fix-ftp-permissions.sh kczk kczkcam1
# 
# Directory structure: /cache/uploads/{airport}/{username}/
# This provides namespace isolation - cameras can only access their airport's folder

set -euo pipefail

AIRPORT_ID="${1:-}"
USERNAME="${2:-}"

if [ -z "$AIRPORT_ID" ] || [ -z "$USERNAME" ]; then
    echo "Usage: $0 <airport_id> <username>"
    echo "Example: $0 kczk kczkcam1"
    echo ""
    echo "Directory structure: /cache/uploads/{airport}/{username}/"
    exit 1
fi

# Check if running as root
if [ "$(id -u)" -ne 0 ]; then
    echo "ERROR: This script must be run as root (for chown/chmod operations)"
    exit 1
fi

# Directory paths
UPLOADS_BASE="/var/www/html/cache/uploads"
AIRPORT_DIR="${UPLOADS_BASE}/${AIRPORT_ID}"
UPLOAD_DIR="${AIRPORT_DIR}/${USERNAME}"

echo "=== Fixing FTP Permissions for ${AIRPORT_ID}/${USERNAME} ==="
echo ""

# Check if ftp user exists
if ! id "ftp" &>/dev/null; then
    echo "ERROR: ftp user does not exist!"
    exit 1
fi

# Ensure parent directories exist with correct ownership
echo "1. Ensuring directory structure exists..."
if [ ! -d "$UPLOADS_BASE" ]; then
    echo "   Creating uploads base: $UPLOADS_BASE"
    mkdir -p "$UPLOADS_BASE"
fi
chown root:root "$UPLOADS_BASE"
chmod 755 "$UPLOADS_BASE"
echo "   ✓ Uploads base: $UPLOADS_BASE (root:root, 755)"

# Create airport directory
if [ ! -d "$AIRPORT_DIR" ]; then
    echo "   Creating airport directory: $AIRPORT_DIR"
    mkdir -p "$AIRPORT_DIR"
fi
chown root:root "$AIRPORT_DIR"
chmod 755 "$AIRPORT_DIR"
echo "   ✓ Airport dir: $AIRPORT_DIR (root:root, 755)"
echo ""

# Create upload directory if it doesn't exist
echo "2. Ensuring upload directory exists..."
if [ ! -d "$UPLOAD_DIR" ]; then
    echo "   Creating upload directory: $UPLOAD_DIR"
    mkdir -p "$UPLOAD_DIR"
fi
echo ""

# Fix upload directory permissions
echo "3. Fixing upload directory permissions..."
echo "   Directory: $UPLOAD_DIR"
chown ftp:ftp "$UPLOAD_DIR"
chmod 0755 "$UPLOAD_DIR"
echo "   ✓ Set to ftp:ftp, 0755"
echo ""

# Create per-user vsftpd config
echo "4. Creating per-user vsftpd config..."
USER_CONFIG_DIR="/etc/vsftpd/users"
USER_CONFIG_FILE="${USER_CONFIG_DIR}/${USERNAME}"
mkdir -p "$USER_CONFIG_DIR"
echo "local_root=${UPLOAD_DIR}" > "$USER_CONFIG_FILE"
echo "   ✓ Config: $USER_CONFIG_FILE"
echo "   ✓ local_root=${UPLOAD_DIR}"
echo ""

# Verify permissions
echo "5. Verifying permissions..."
UPLOAD_STAT=$(stat -c "%U:%G %a" "$UPLOAD_DIR" 2>/dev/null || stat -f "%Su:%Sg %OLp" "$UPLOAD_DIR" 2>/dev/null)
echo "   Upload dir: $UPLOAD_STAT"

if [[ "$UPLOAD_STAT" == *"ftp:ftp"* ]]; then
    echo "   ✓ Permissions are correct"
else
    echo "   ⚠️  WARNING: Permissions may not be as expected"
fi
echo ""

# Test write access (as ftp user)
echo "6. Testing write access..."
TEST_FILE="${UPLOAD_DIR}/.permission_test_$$"
if sudo -u ftp touch "$TEST_FILE" 2>/dev/null; then
    echo "   ✓ Write test passed"
    rm -f "$TEST_FILE" 2>/dev/null || true
else
    echo "   ❌ Write test failed - there may be additional issues"
fi
echo ""

echo "=== Fix Complete ==="
echo ""
echo "Next steps:"
echo "1. Restart vsftpd if needed: pkill vsftpd && vsftpd /etc/vsftpd/vsftpd.conf &"
echo "2. Try uploading via FTP/FTPS"
echo "3. Upload path: / (relative to chroot root)"
echo ""
