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
    useradd -r -s /bin/false -d "$UPLOAD_DIR" -m "$USERNAME"
    echo "$USERNAME:$PASSWORD" | chpasswd
    echo "Created user: $USERNAME"
fi

# Ensure upload directory exists and has correct permissions
if [ ! -d "$UPLOAD_DIR" ]; then
    mkdir -p "$UPLOAD_DIR"
fi

chown "$USERNAME:$USERNAME" "$UPLOAD_DIR"
chmod 755 "$UPLOAD_DIR"

# Create incoming subdirectory
INCOMING_DIR="$UPLOAD_DIR/incoming"
if [ ! -d "$INCOMING_DIR" ]; then
    mkdir -p "$INCOMING_DIR"
    chown "$USERNAME:$USERNAME" "$INCOMING_DIR"
    chmod 755 "$INCOMING_DIR"
fi

echo "SFTP user setup complete: $USERNAME -> $UPLOAD_DIR"

