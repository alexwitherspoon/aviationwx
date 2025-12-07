#!/bin/bash
# SFTP User Creation Helper
# Creates or updates an SFTP user for push webcam uploads
# Usage: create-sftp-user.sh <username> <password> <upload_dir>

set -e

USERNAME="$1"
PASSWORD="$2"
UPLOAD_DIR="$3"

if [ -z "$USERNAME" ] || [ -z "$PASSWORD" ] || [ -z "$UPLOAD_DIR" ]; then
    echo "Usage: $0 <username> <password> <upload_dir>" >&2
    exit 1
fi

# Validate username (14 characters, alphanumeric)
if ! echo "$USERNAME" | grep -qE '^[a-zA-Z0-9]{14}$'; then
    echo "Error: Username must be exactly 14 alphanumeric characters" >&2
    exit 1
fi

# Validate password (14 characters, alphanumeric)
if ! echo "$PASSWORD" | grep -qE '^[a-zA-Z0-9]{14}$'; then
    echo "Error: Password must be exactly 14 alphanumeric characters" >&2
    exit 1
fi

# Ensure upload directory exists and has correct permissions FIRST
# IMPORTANT: The chroot directory must be owned by root for SFTP chroot to work
# This must be done before useradd, because useradd -m will fail if directory exists and isn't writable
if [ ! -d "$UPLOAD_DIR" ]; then
    mkdir -p "$UPLOAD_DIR"
fi

# Chroot directory must be owned by root (SSH requirement)
# Set this before creating user to avoid permission issues
chown root:root "$UPLOAD_DIR"
chmod 755 "$UPLOAD_DIR"

# Check if user exists
if id "$USERNAME" &>/dev/null; then
    # User exists - update password
    echo "$USERNAME:$PASSWORD" | chpasswd
    echo "Updated password for user: $USERNAME"
    
    # Update home directory if it changed (user might have been created with different directory)
    CURRENT_HOME=$(getent passwd "$USERNAME" | cut -d: -f6)
    if [ "$CURRENT_HOME" != "$UPLOAD_DIR" ]; then
        echo "Updating home directory from $CURRENT_HOME to $UPLOAD_DIR"
        usermod -d "$UPLOAD_DIR" "$USERNAME"
        # Ensure directory is still root-owned after usermod
        chown root:root "$UPLOAD_DIR"
    fi
else
    # Create new user
    # Use -M to NOT create home directory (we already created it above)
    # This avoids issues if directory already exists
    useradd -r -s /usr/sbin/nologin -d "$UPLOAD_DIR" -G webcam_users -M "$USERNAME"
    echo "$USERNAME:$PASSWORD" | chpasswd
    echo "Created user: $USERNAME"
    
    # Ensure directory is still root-owned after useradd
    chown root:root "$UPLOAD_DIR"
    chmod 755 "$UPLOAD_DIR"
fi

# Create incoming subdirectory
INCOMING_DIR="$UPLOAD_DIR/incoming"
if [ ! -d "$INCOMING_DIR" ]; then
    mkdir -p "$INCOMING_DIR"
fi
# Always ensure correct ownership and permissions (even if directory exists)
chown "$USERNAME:$USERNAME" "$INCOMING_DIR"
chmod 755 "$INCOMING_DIR"

echo "SFTP user setup complete: $USERNAME -> $UPLOAD_DIR"

