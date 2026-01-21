#!/bin/bash
# SFTP User Creation Helper
# Creates or updates an SFTP user for push webcam uploads with chroot
# Usage: create-sftp-user.sh <username> <password>
#
# Directory structure (using dedicated /cache/sftp/ hierarchy):
#   /cache/sftp/{username}/       ← root:root 755 (SFTP chroot)
#   /cache/sftp/{username}/files/ ← ftp:www-data 2775 (upload here)
#
# SFTP users are chrooted to /cache/sftp/{username}/ and must upload to /files/
# The entire path from / to chroot must be root-owned for SSH chroot to work.

set -e

USERNAME="$1"
PASSWORD="$2"

# Base SFTP directory (must match CACHE_SFTP_DIR in cache-paths.php)
SFTP_BASE_DIR="/var/www/html/cache/sftp"

if [ -z "$USERNAME" ] || [ -z "$PASSWORD" ]; then
    echo "Usage: $0 <username> <password>" >&2
    exit 1
fi

# Validate username (alphanumeric, up to 14 characters)
if ! echo "$USERNAME" | grep -qE '^[a-zA-Z0-9]{1,14}$'; then
    echo "Error: Username must be 1-14 alphanumeric characters" >&2
    exit 1
fi

# Validate password (14 characters, alphanumeric)
if ! echo "$PASSWORD" | grep -qE '^[a-zA-Z0-9]{14}$'; then
    echo "Error: Password must be exactly 14 alphanumeric characters" >&2
    exit 1
fi

# Get ftp and www-data user/group info
FTP_UID=$(id -u ftp 2>/dev/null || echo "101")
WWW_DATA_GID=$(getent group www-data | cut -d: -f3 || echo "33")

# Chroot directory: /cache/sftp/{username}/
CHROOT_DIR="$SFTP_BASE_DIR/$USERNAME"

# Ensure base SFTP directory exists (root-owned)
if [ ! -d "$SFTP_BASE_DIR" ]; then
    mkdir -p "$SFTP_BASE_DIR"
fi
chown root:root "$SFTP_BASE_DIR"
chmod 755 "$SFTP_BASE_DIR"

# Create chroot directory (root-owned, not writable - required for SSH chroot)
if [ ! -d "$CHROOT_DIR" ]; then
    mkdir -p "$CHROOT_DIR"
fi
chown root:root "$CHROOT_DIR"
chmod 755 "$CHROOT_DIR"

# Create files/ subdirectory (writable upload directory)
FILES_DIR="$CHROOT_DIR/files"
if [ ! -d "$FILES_DIR" ]; then
    mkdir -p "$FILES_DIR"
fi

# files/ directory: ftp:www-data with setgid (2775)
# - ftp owner: consistent with FTP uploads
# - www-data group: allows processor to read files
# - setgid: ensures new files inherit www-data group
chown "$FTP_UID":"$WWW_DATA_GID" "$FILES_DIR"
chmod 2775 "$FILES_DIR"

# Check if user exists
if id "$USERNAME" &>/dev/null; then
    # User exists - update password
    echo "$USERNAME:$PASSWORD" | chpasswd
    echo "Updated password for user: $USERNAME"
    
    # Update home directory if it changed
    CURRENT_HOME=$(getent passwd "$USERNAME" | cut -d: -f6)
    if [ "$CURRENT_HOME" != "$CHROOT_DIR" ]; then
        echo "Updating home directory from $CURRENT_HOME to $CHROOT_DIR"
        usermod -d "$CHROOT_DIR" "$USERNAME"
    fi
    
    # Ensure user is in www-data group
    if ! groups "$USERNAME" | grep -q '\bwww-data\b'; then
        echo "Adding $USERNAME to www-data group"
        usermod -aG www-data "$USERNAME"
    fi
else
    # Create new user
    # -r: system account
    # -s /usr/sbin/nologin: no shell access
    # -d: home directory (chroot point)
    # -G webcam_users,www-data: groups for access control
    # -M: don't create home directory (we already created it)
    useradd -r -s /usr/sbin/nologin -d "$CHROOT_DIR" -G webcam_users,www-data -M "$USERNAME"
    echo "$USERNAME:$PASSWORD" | chpasswd
    echo "Created user: $USERNAME (groups: webcam_users, www-data)"
fi

# Ensure permissions are correct after any modifications
chown root:root "$SFTP_BASE_DIR"
chmod 755 "$SFTP_BASE_DIR"
chown root:root "$CHROOT_DIR"
chmod 755 "$CHROOT_DIR"
chown "$FTP_UID":"$WWW_DATA_GID" "$FILES_DIR"
chmod 2775 "$FILES_DIR"

echo "SFTP user setup complete: $USERNAME"
echo "  Chroot: $CHROOT_DIR (root:root 755)"
echo "  Upload: $FILES_DIR (ftp:www-data 2775)"
echo "  SFTP path: /files/"
