#!/bin/bash
#
# Repair SFTP chroot ownership for sshd internal-sftp (Match Group webcam_users).
#
# Required layout:
#   SFTP_DIR/                 root:root 755
#   SFTP_DIR/{username}/      root:root 755  (ChrootDirectory; not writable by upload user)
#   SFTP_DIR/{username}/files/  ftp:www-data 2775
#
# Invoked from set-cache-permissions.sh (nightly), sync-push-config.php (every run),
# and create-sftp-user.sh. Idempotent.
#
# Usage: repair-sftp-chroot-permissions.sh [USERNAME]
# Environment: SFTP_DIR (default /var/sftp). Must run as root.
#

set -uo pipefail

if [ "$(id -u)" -ne 0 ]; then
    echo "repair-sftp-chroot: error: must run as root" >&2
    exit 1
fi

SFTP_DIR="${SFTP_DIR:-/var/sftp}"
SINGLE_USER="${1:-}"

if [ -n "$SINGLE_USER" ] && ! echo "$SINGLE_USER" | grep -qE '^[a-zA-Z0-9]{1,14}$'; then
    echo "repair-sftp-chroot: error: invalid username (1-14 alphanumeric)" >&2
    exit 1
fi

FTP_UID=$(id -u ftp 2>/dev/null || echo "101")
if command -v getent >/dev/null 2>&1; then
    WWW_DATA_GID=$(getent group www-data | cut -d: -f3 || echo "33")
else
    WWW_DATA_GID=33
fi

repair_user_chroot() {
    local username="$1"
    local chroot_dir="${SFTP_DIR}/${username}"
    local files_dir="${chroot_dir}/files"

    if [ ! -d "$chroot_dir" ]; then
        echo "repair-sftp-chroot: skip missing ${chroot_dir}"
        return 0
    fi

    chown root:root "$chroot_dir" 2>/dev/null || {
        echo "repair-sftp-chroot: warning: could not chown root:root ${chroot_dir}" >&2
        return 1
    }
    chmod 755 "$chroot_dir" 2>/dev/null || true

    if [ ! -d "$files_dir" ]; then
        mkdir -p "$files_dir" 2>/dev/null || {
            echo "repair-sftp-chroot: warning: could not mkdir ${files_dir}" >&2
            return 1
        }
    fi

    chown "${FTP_UID}:${WWW_DATA_GID}" "$files_dir" 2>/dev/null || {
        echo "repair-sftp-chroot: warning: could not chown ftp:www-data ${files_dir}" >&2
        return 1
    }
    chmod 2775 "$files_dir" 2>/dev/null || true

    echo "repair-sftp-chroot: ok ${username} (chroot root:root 755, files ftp:www-data 2775)"
    return 0
}

if [ ! -d "$SFTP_DIR" ]; then
    echo "repair-sftp-chroot: creating SFTP_DIR ${SFTP_DIR}"
    mkdir -p "$SFTP_DIR" || {
        echo "repair-sftp-chroot: error: mkdir ${SFTP_DIR} failed" >&2
        exit 1
    }
fi

if ! chown root:root "$SFTP_DIR" 2>/dev/null; then
    echo "repair-sftp-chroot: warning: could not chown root:root ${SFTP_DIR}" >&2
    failures=1
else
    failures=0
fi
chmod 755 "$SFTP_DIR" 2>/dev/null || true

if [ -n "$SINGLE_USER" ]; then
    repair_user_chroot "$SINGLE_USER" || failures=$((failures + 1))
else
    shopt -s nullglob
    for chroot_dir in "${SFTP_DIR}"/*/; do
        username=$(basename "$chroot_dir")
        if ! echo "$username" | grep -qE '^[a-zA-Z0-9]{1,14}$'; then
            echo "repair-sftp-chroot: skip non-user dir ${username}"
            continue
        fi
        repair_user_chroot "$username" || failures=$((failures + 1))
    done
    shopt -u nullglob
fi

if [ "$failures" -gt 0 ]; then
    echo "repair-sftp-chroot: finished with ${failures} failure(s)" >&2
    exit 1
fi

echo "repair-sftp-chroot: done (${SFTP_DIR})"
exit 0
