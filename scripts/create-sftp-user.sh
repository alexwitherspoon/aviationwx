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

# Check if user exists
if id "$USERNAME" &>/dev/null; then
    # User exists - update password
    echo "$USERNAME:$PASSWORD" | chpasswd
    echo "Updated password for user: $USERNAME"
else
    # Create new user
    useradd -r -s /usr/sbin/nologin -d "$UPLOAD_DIR" -G webcam_users -m "$USERNAME"
    echo "$USERNAME:$PASSWORD" | chpasswd
    echo "Created user: $USERNAME"
fi

# Ensure upload directory exists and has correct permissions
# IMPORTANT: The chroot directory must be owned by root for SFTP chroot to work
if [ ! -d "$UPLOAD_DIR" ]; then
    mkdir -p "$UPLOAD_DIR"
fi

# Chroot directory must be owned by root (SSH requirement)
chown root:root "$UPLOAD_DIR"
chmod 755 "$UPLOAD_DIR"

# Create incoming subdirectory
INCOMING_DIR="$UPLOAD_DIR/incoming"
if [ ! -d "$INCOMING_DIR" ]; then
    mkdir -p "$INCOMING_DIR"
fi
# Always ensure correct ownership and permissions (even if directory exists)
chown "$USERNAME:$USERNAME" "$INCOMING_DIR"
chmod 755 "$INCOMING_DIR"

echo "SFTP user setup complete: $USERNAME -> $UPLOAD_DIR"

