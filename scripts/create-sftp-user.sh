#!/bin/bash
# SFTP User Creation Helper
# Creates or updates an SFTP user for push webcam uploads
# Usage: create-sftp-user.sh <username> <password> <upload_dir>
#
# Security model (no chroot):
# - User home directory set to upload folder
# - ForceCommand internal-sftp prevents shell access
# - User added to www-data group for shared directory access
# - No ChrootDirectory (allows simpler camera configuration - upload to /)
#
# This trades strict directory isolation for ease of configuration.
# Cameras can upload to / without needing to configure subdirectories.

set -e

USERNAME="$1"
PASSWORD="$2"
UPLOAD_DIR="$3"

if [ -z "$USERNAME" ] || [ -z "$PASSWORD" ] || [ -z "$UPLOAD_DIR" ]; then
    echo "Usage: $0 <username> <password> <upload_dir>" >&2
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

# Ensure upload directory exists
if [ ! -d "$UPLOAD_DIR" ]; then
    mkdir -p "$UPLOAD_DIR"
fi

# Set directory permissions: ftp:www-data with setgid (2775)
# - ftp (owner): allows vsftpd virtual users to write
# - www-data (group): allows SFTP users (in www-data group) to write
# - setgid: ensures new files inherit www-data group for processor access
chown "$FTP_UID":"$WWW_DATA_GID" "$UPLOAD_DIR"
chmod 2775 "$UPLOAD_DIR"

# Check if user exists
if id "$USERNAME" &>/dev/null; then
    # User exists - update password
    echo "$USERNAME:$PASSWORD" | chpasswd
    echo "Updated password for user: $USERNAME"
    
    # Update home directory if it changed
    CURRENT_HOME=$(getent passwd "$USERNAME" | cut -d: -f6)
    if [ "$CURRENT_HOME" != "$UPLOAD_DIR" ]; then
        echo "Updating home directory from $CURRENT_HOME to $UPLOAD_DIR"
        usermod -d "$UPLOAD_DIR" "$USERNAME"
    fi
    
    # Ensure user is in www-data group (for writing to shared directory)
    if ! groups "$USERNAME" | grep -q '\bwww-data\b'; then
        echo "Adding $USERNAME to www-data group"
        usermod -aG www-data "$USERNAME"
    fi
else
    # Create new user
    # -r: system account
    # -s /usr/sbin/nologin: no shell access
    # -d: home directory (upload folder)
    # -G webcam_users,www-data: groups for access control
    # -M: don't create home directory (we already created it)
    useradd -r -s /usr/sbin/nologin -d "$UPLOAD_DIR" -G webcam_users,www-data -M "$USERNAME"
    echo "$USERNAME:$PASSWORD" | chpasswd
    echo "Created user: $USERNAME (groups: webcam_users, www-data)"
fi

# Ensure directory permissions are correct after any modifications
chown "$FTP_UID":"$WWW_DATA_GID" "$UPLOAD_DIR"
chmod 2775 "$UPLOAD_DIR"

echo "SFTP user setup complete: $USERNAME"
echo "  Home/Upload: $UPLOAD_DIR (ftp:www-data 2775)"
echo "  Security: ForceCommand internal-sftp, no shell, www-data group"

