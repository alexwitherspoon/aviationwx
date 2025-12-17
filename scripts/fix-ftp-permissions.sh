#!/bin/bash
# Fix FTP/FTPS upload permissions for a specific camera
# Usage: ./fix-ftp-permissions.sh [airport_id] [cam_index]
# Example: ./fix-ftp-permissions.sh kczk 0
# 
# This script fixes permissions to match the expected configuration:
# - Chroot directory: www-data:www-data, 0775 (writable by FTP guest user)

set -euo pipefail

AIRPORT_ID="${1:-}"
CAM_INDEX="${2:-}"

if [ -z "$AIRPORT_ID" ] || [ -z "$CAM_INDEX" ]; then
    echo "Usage: $0 <airport_id> <cam_index>"
    echo "Example: $0 kczk 0"
    exit 1
fi

# Check if running as root
if [ "$(id -u)" -ne 0 ]; then
    echo "ERROR: This script must be run as root (for chown/chmod operations)"
    exit 1
fi

UPLOADS_BASE="/var/www/html/uploads/webcams"
CHROOT_DIR="${UPLOADS_BASE}/${AIRPORT_ID}_${CAM_INDEX}"
INCOMING_DIR="${CHROOT_DIR}/incoming"

echo "=== Fixing FTP Permissions for ${AIRPORT_ID}_${CAM_INDEX} ==="
echo ""

# Check if www-data user exists
if ! id "www-data" &>/dev/null; then
    echo "ERROR: www-data user does not exist!"
    exit 1
fi

# Create chroot directory if it doesn't exist
echo "1. Ensuring chroot directory exists..."
if [ ! -d "$CHROOT_DIR" ]; then
    echo "   Creating chroot directory: $CHROOT_DIR"
    mkdir -p "$CHROOT_DIR"
fi
echo ""

# Fix chroot directory permissions
echo "2. Fixing chroot directory permissions..."
echo "   Directory: $CHROOT_DIR"
chown www-data:www-data "$CHROOT_DIR"
chmod 0775 "$CHROOT_DIR"
echo "   ✓ Set to www-data:www-data, 0775"
echo ""

# Verify permissions
echo "3. Verifying permissions..."
CHROOT_STAT=$(stat -c "%U:%G %a" "$CHROOT_DIR" 2>/dev/null || stat -f "%Su:%Sg %OLp" "$CHROOT_DIR" 2>/dev/null)

echo "   Chroot: $CHROOT_STAT"

if [[ "$CHROOT_STAT" == *"www-data:www-data"* ]]; then
    echo "   ✓ Permissions are correct"
else
    echo "   ⚠️  WARNING: Permissions may not be as expected"
fi
echo ""

# Test write access
echo "4. Testing write access..."
TEST_FILE="${CHROOT_DIR}/.permission_test_$$"
if sudo -u www-data touch "$TEST_FILE" 2>/dev/null; then
    echo "   ✓ Write test passed"
    sudo -u www-data rm -f "$TEST_FILE" 2>/dev/null || true
else
    echo "   ❌ Write test failed - there may be additional issues"
    echo "      Check SELinux/AppArmor or filesystem mount options"
fi
echo ""

echo "=== Fix Complete ==="
echo ""
echo "Next steps:"
echo "1. Try uploading again via FTP"
echo "2. You can upload directly to / (the chroot root) - no subdirectory needed!"
echo "3. Upload path should be: /your-file.jpg (relative to chroot)"
echo ""

