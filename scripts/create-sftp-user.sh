#!/bin/bash
# SFTP User Creation Helper
# Creates or updates an SFTP user for push webcam uploads
# Usage: create-sftp-user.sh <username> <password> <chroot_dir>
#
# Directory structure created:
#   {chroot_dir}/       <- root:root 755 (chroot point, not writable)
#   {chroot_dir}/files/ <- ftp:www-data 2775 (writable upload directory)
#
# The user is added to www-data group so they can write to files/ directory
# which is owned by ftp:www-data. This allows both FTP and SFTP to use the
# same directory with the same permissions.

set -e

USERNAME="$1"
PASSWORD="$2"
CHROOT_DIR="$3"

if [ -z "$USERNAME" ] || [ -z "$PASSWORD" ] || [ -z "$CHROOT_DIR" ]; then
    echo "Usage: $0 <username> <password> <chroot_dir>" >&2
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

# Ensure chroot directory exists
if [ ! -d "$CHROOT_DIR" ]; then
    mkdir -p "$CHROOT_DIR"
fi

# Chroot directory must be owned by root (SSH requirement)
chown root:root "$CHROOT_DIR"
chmod 755 "$CHROOT_DIR"

# Create files/ subdirectory for actual uploads
FILES_DIR="$CHROOT_DIR/files"
if [ ! -d "$FILES_DIR" ]; then
    mkdir -p "$FILES_DIR"
fi

# files/ directory: ftp:www-data with setgid (2775)
# - ftp (owner): allows vsftpd virtual users to write
# - www-data (group): allows SFTP users (in www-data group) to write
# - setgid: ensures new files inherit www-data group for processor access
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
    
    # Ensure user is in www-data group (for writing to files/ directory)
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

# Ensure chroot directory is still root-owned after any modifications
chown root:root "$CHROOT_DIR"
chmod 755 "$CHROOT_DIR"

# Ensure files/ directory has correct permissions
chown "$FTP_UID":"$WWW_DATA_GID" "$FILES_DIR"
chmod 2775 "$FILES_DIR"

echo "SFTP user setup complete: $USERNAME"
echo "  Chroot: $CHROOT_DIR (root:root 755)"
echo "  Upload: $FILES_DIR (ftp:www-data 2775)"

