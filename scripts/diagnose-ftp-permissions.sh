#!/bin/bash
# Diagnostic script for FTP/FTPS upload permissions
# Usage: ./diagnose-ftp-permissions.sh [airport_id] [cam_index]
# Example: ./diagnose-ftp-permissions.sh kczk 0

set -euo pipefail

AIRPORT_ID="${1:-}"
CAM_INDEX="${2:-}"

if [ -z "$AIRPORT_ID" ] || [ -z "$CAM_INDEX" ]; then
    echo "Usage: $0 <airport_id> <cam_index>"
    echo "Example: $0 kczk 0"
    exit 1
fi

UPLOADS_BASE="/var/www/html/uploads/webcams"
CHROOT_DIR="${UPLOADS_BASE}/${AIRPORT_ID}_${CAM_INDEX}"

echo "=== FTP Permissions Diagnostic for ${AIRPORT_ID}_${CAM_INDEX} ==="
echo ""

# Check if directory exists
echo "1. Checking directory existence..."
if [ ! -d "$CHROOT_DIR" ]; then
    echo "   ❌ ERROR: Chroot directory does not exist: $CHROOT_DIR"
    exit 1
else
    echo "   ✓ Chroot directory exists: $CHROOT_DIR"
fi
echo ""

# Check chroot directory permissions
echo "2. Checking chroot directory permissions..."
CHROOT_STAT=$(stat -c "%U:%G %a" "$CHROOT_DIR" 2>/dev/null || stat -f "%Su:%Sg %OLp" "$CHROOT_DIR" 2>/dev/null)
CHROOT_OWNER=$(echo "$CHROOT_STAT" | awk '{print $1}')
CHROOT_PERMS=$(echo "$CHROOT_STAT" | awk '{print $2}')

echo "   Chroot directory: $CHROOT_DIR"
echo "   Owner:Group: $CHROOT_OWNER"
echo "   Permissions: $CHROOT_PERMS"

# Check if www-data user exists
if id "www-data" &>/dev/null; then
    WWW_DATA_UID=$(id -u www-data)
    WWW_DATA_GID=$(id -g www-data)
    echo "   www-data UID:GID: $WWW_DATA_UID:$WWW_DATA_GID"
    
    if [[ "$CHROOT_OWNER" == "www-data:www-data" ]]; then
        echo "   ✓ Ownership is correct (www-data:www-data required for FTP guest user)"
    else
        echo "   ❌ ERROR: Ownership should be www-data:www-data"
        echo "      Current: $CHROOT_OWNER"
        echo "      Required: www-data:www-data"
    fi
else
    echo "   ❌ ERROR: www-data user does not exist!"
fi

# Check write permissions
if [[ "$CHROOT_PERMS" =~ ^[0-7][0-7][2-7]$ ]] || [[ "$CHROOT_PERMS" =~ ^[0-7][2-7][0-7]$ ]]; then
    echo "   ✓ Permissions allow writing (owner/group have write permission)"
else
    echo "   ❌ ERROR: Permissions do not allow writing"
    echo "      Recommended: 0775 (rwxrwxr-x)"
fi
echo ""

# Test write access as www-data
echo "3. Testing write access as www-data..."
if id "www-data" &>/dev/null; then
    TEST_FILE="${CHROOT_DIR}/.permission_test_$$"
    if sudo -u www-data touch "$TEST_FILE" 2>/dev/null; then
        echo "   ✓ Write test passed (can create files in chroot root)"
        sudo -u www-data rm -f "$TEST_FILE" 2>/dev/null || true
    else
        echo "   ❌ ERROR: Write test failed (cannot create files as www-data)"
        echo "      This is the root cause of the upload failure!"
    fi
else
    echo "   ⚠️  Skipped (www-data user does not exist)"
fi
echo ""

# Check vsftpd user config
echo "4. Checking vsftpd user configuration..."
USERNAME="${AIRPORT_ID}_${CAM_INDEX}"
USER_CONFIG="/etc/vsftpd/users/${USERNAME}"

if [ -f "$USER_CONFIG" ]; then
    echo "   ✓ User config file exists: $USER_CONFIG"
    echo "   Contents:"
    cat "$USER_CONFIG" | sed 's/^/      /'
    
    EXPECTED_ROOT="local_root=${CHROOT_DIR}"
    if grep -q "^${EXPECTED_ROOT}$" "$USER_CONFIG" || grep -q "^local_root=${CHROOT_DIR}$" "$USER_CONFIG"; then
        echo "   ✓ local_root is correctly set to chroot directory"
    else
        echo "   ❌ WARNING: local_root may not match expected chroot directory"
    fi
else
    echo "   ❌ ERROR: User config file does not exist: $USER_CONFIG"
    echo "      This means the FTP user may not be properly configured!"
fi
echo ""

# Summary
echo "=== Summary ==="
echo ""
echo "For FTP uploads to work:"
echo "1. Chroot directory must exist and be owned by www-data:www-data with 0775 permissions"
echo "2. Parent directories must be root-owned for chroot security"
echo "3. www-data user must be able to write to the chroot directory"
echo "4. vsftpd user config must set local_root to the chroot directory"
echo ""
echo "When uploading via FTP:"
echo "- You will be chrooted to: $CHROOT_DIR"
echo "- You can upload directly to / (the chroot root)"
echo "- Upload path should be: /your-file.jpg (relative to chroot)"
echo "- No need to navigate to a subdirectory!"
echo ""

