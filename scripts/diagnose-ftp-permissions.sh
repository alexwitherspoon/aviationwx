#!/bin/bash
# Diagnostic script for FTP/FTPS upload permissions
# Usage: ./diagnose-ftp-permissions.sh <airport_id> <username>
# Example: ./diagnose-ftp-permissions.sh kczk kczkcam1
#
# Directory structure: /cache/webcam/uploads/{airport}/{username}/

set -euo pipefail

AIRPORT_ID="${1:-}"
USERNAME="${2:-}"

if [ -z "$AIRPORT_ID" ] || [ -z "$USERNAME" ]; then
    echo "Usage: $0 <airport_id> <username>"
    echo "Example: $0 kczk kczkcam1"
    exit 1
fi

# Directory paths
UPLOADS_BASE="/var/www/html/cache/webcam/uploads"
AIRPORT_DIR="${UPLOADS_BASE}/${AIRPORT_ID}"
UPLOAD_DIR="${AIRPORT_DIR}/${USERNAME}"
USER_CONFIG="/etc/vsftpd/users/${USERNAME}"

echo "=== FTP Permissions Diagnostic for ${AIRPORT_ID}/${USERNAME} ==="
echo ""

# Check directory structure
echo "1. Checking directory structure..."
echo "   Base: $UPLOADS_BASE"
if [ -d "$UPLOADS_BASE" ]; then
    BASE_STAT=$(stat -c "%U:%G %a" "$UPLOADS_BASE" 2>/dev/null || stat -f "%Su:%Sg %OLp" "$UPLOADS_BASE" 2>/dev/null)
    echo "   ✓ Exists: $BASE_STAT"
else
    echo "   ❌ Does not exist"
fi

echo "   Airport: $AIRPORT_DIR"
if [ -d "$AIRPORT_DIR" ]; then
    AIRPORT_STAT=$(stat -c "%U:%G %a" "$AIRPORT_DIR" 2>/dev/null || stat -f "%Su:%Sg %OLp" "$AIRPORT_DIR" 2>/dev/null)
    echo "   ✓ Exists: $AIRPORT_STAT"
else
    echo "   ❌ Does not exist"
fi

echo "   Upload: $UPLOAD_DIR"
if [ -d "$UPLOAD_DIR" ]; then
    UPLOAD_STAT=$(stat -c "%U:%G %a" "$UPLOAD_DIR" 2>/dev/null || stat -f "%Su:%Sg %OLp" "$UPLOAD_DIR" 2>/dev/null)
    echo "   ✓ Exists: $UPLOAD_STAT"
    
    # Check ownership
    if [[ "$UPLOAD_STAT" == *"ftp:ftp"* ]]; then
        echo "   ✓ Ownership correct (ftp:ftp)"
    else
        echo "   ⚠️  Ownership should be ftp:ftp"
    fi
else
    echo "   ❌ Does not exist - run fix-ftp-permissions.sh"
fi
echo ""

# Check per-user vsftpd config
echo "2. Checking per-user vsftpd config..."
if [ -f "$USER_CONFIG" ]; then
    echo "   ✓ Config exists: $USER_CONFIG"
    echo "   Content:"
    cat "$USER_CONFIG" | sed 's/^/      /'
    
    # Verify local_root matches
    EXPECTED_ROOT="local_root=${UPLOAD_DIR}"
    if grep -q "^${EXPECTED_ROOT}$" "$USER_CONFIG"; then
        echo "   ✓ local_root is correctly set"
    else
        echo "   ⚠️  local_root may not match expected path"
        echo "      Expected: $EXPECTED_ROOT"
    fi
else
    echo "   ❌ Config does not exist: $USER_CONFIG"
fi
echo ""

# Check vsftpd main config
echo "3. Checking vsftpd main config..."
VSFTPD_CONF="/etc/vsftpd/vsftpd.conf"
if [ -f "$VSFTPD_CONF" ]; then
    echo "   ✓ Main config exists"
    if grep -q "^user_config_dir=" "$VSFTPD_CONF"; then
        USER_CONFIG_DIR=$(grep "^user_config_dir=" "$VSFTPD_CONF" | cut -d= -f2)
        echo "   ✓ user_config_dir=$USER_CONFIG_DIR"
    else
        echo "   ⚠️  user_config_dir not set - per-user configs won't be read!"
    fi
else
    echo "   ❌ Main config not found"
fi
echo ""

# Check vsftpd process
echo "4. Checking vsftpd process..."
if pgrep -x vsftpd > /dev/null; then
    echo "   ✓ vsftpd is running"
    ps aux | grep vsftpd | grep -v grep | sed 's/^/      /'
else
    echo "   ❌ vsftpd is not running"
fi
echo ""

# Check virtual users database
echo "5. Checking virtual users..."
VSFTPD_USERS="/etc/vsftpd/virtual_users.txt"
if [ -f "$VSFTPD_USERS" ]; then
    if grep -q "^${USERNAME}$" "$VSFTPD_USERS"; then
        echo "   ✓ User ${USERNAME} exists in virtual_users.txt"
    else
        echo "   ❌ User ${USERNAME} not found in virtual_users.txt"
    fi
else
    echo "   ❌ virtual_users.txt not found"
fi
echo ""

# Summary
echo "=== Summary ==="
echo "Expected setup:"
echo "1. Upload directory: ${UPLOAD_DIR} (ftp:ftp, 755)"
echo "2. Per-user config: ${USER_CONFIG} with local_root=${UPLOAD_DIR}"
echo "3. vsftpd.conf must have: user_config_dir=/etc/vsftpd/users"
echo "4. User must exist in /etc/vsftpd/virtual_users.txt"
echo ""
