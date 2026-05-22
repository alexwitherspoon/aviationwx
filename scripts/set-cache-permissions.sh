#!/bin/bash
#
# Apply cache bind-mount ownership and modes, including webcams setgid layout,
# plus FTP and SFTP parent directories required by vsftpd and sshd chroot.
#
# Invoked from docker/docker-entrypoint.sh and config/crontab (daily 01:00 UTC).
# Ends with repair-sftp-chroot-permissions.sh for per-user /var/sftp chroots.
#
# Must run as root for chown to root:www-data on cache/webcams.
#
# Environment overrides:
#   CACHE_DIR   Cache root: /var/www/html/cache when that directory exists, otherwise ${PROJECT_ROOT}/cache
#               (PROJECT_ROOT is the parent of this script's directory).
#   SFTP_DIR    SFTP chroot parent (default: /var/sftp)

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

# Default cache: app tree when present, else cache/ beside the repo root inferred from this path.
if [ -d "/var/www/html/cache" ]; then
    _default_cache="/var/www/html/cache"
else
    _default_cache="${PROJECT_ROOT}/cache"
fi
# :- treats unset or empty CACHE_DIR (e.g. env mistake) as use default
CACHE_DIR="${CACHE_DIR:-${_default_cache}}"
SFTP_DIR="${SFTP_DIR:-/var/sftp}"

WEBCAM_CACHE_DIR="${CACHE_DIR}/webcams"
WEATHER_CACHE_DIR="${CACHE_DIR}/weather"
FTP_DIR="${CACHE_DIR}/ftp"
METRICS_DIR="${CACHE_DIR}/metrics"
METRICS_HOURLY_DIR="${METRICS_DIR}/hourly"
METRICS_DAILY_DIR="${METRICS_DIR}/daily"
METRICS_WEEKLY_DIR="${METRICS_DIR}/weekly"
PEAK_GUSTS_DIR="${CACHE_DIR}/peak_gusts"
TEMP_EXTREMES_DIR="${CACHE_DIR}/temp_extremes"
RUNWAYS_DIR="${CACHE_DIR}/runways"
GEOMAG_DIR="${CACHE_DIR}/geomag"
NOTAM_DIR="${CACHE_DIR}/notam"
STATION_POWER_DIR="${CACHE_DIR}/station-power"
PARTNERS_DIR="${CACHE_DIR}/partners"
RATE_LIMITS_DIR="${CACHE_DIR}/rate_limits"
MAP_TILES_DIR="${CACHE_DIR}/map_tiles"

echo "set-cache-permissions: CACHE_DIR=${CACHE_DIR} SFTP_DIR=${SFTP_DIR}"

if [ -d "${CACHE_DIR}" ]; then
    # Safe in production: /var/sftp is a separate bind mount, not under CACHE_DIR in the container.
    chown -R www-data:www-data "${CACHE_DIR}" 2>/dev/null || {
        echo "set-cache-permissions: warning: could not chown -R cache (may lack privileges or already correct)"
    }

    chmod 755 "${CACHE_DIR}" 2>/dev/null || true
    if [ -d "${WEBCAM_CACHE_DIR}" ]; then
        chmod 775 "${WEBCAM_CACHE_DIR}" 2>/dev/null || true
        # Setgid + root:www-data on webcams only: new dirs inherit group www-data
        chown root:www-data "${WEBCAM_CACHE_DIR}" 2>/dev/null || true
        chmod 2775 "${WEBCAM_CACHE_DIR}" 2>/dev/null || true
        chmod g+s "${WEBCAM_CACHE_DIR}" 2>/dev/null || true
    fi
    if [ -d "${WEATHER_CACHE_DIR}" ]; then
        chmod 775 "${WEATHER_CACHE_DIR}" 2>/dev/null || true
    fi
    # Writable app data under cache (www-data); ftp/ is re-owned root below for vsftpd
    for _d in "${PEAK_GUSTS_DIR}" "${TEMP_EXTREMES_DIR}" "${RUNWAYS_DIR}" "${GEOMAG_DIR}" "${NOTAM_DIR}" "${PARTNERS_DIR}" "${RATE_LIMITS_DIR}" "${MAP_TILES_DIR}"; do
        if [ -d "${_d}" ]; then
            chmod 775 "${_d}" 2>/dev/null || true
        fi
    done
    if [ -d "${METRICS_DIR}" ]; then
        chmod 775 "${METRICS_DIR}" 2>/dev/null || true
        chmod 775 "${METRICS_HOURLY_DIR}" 2>/dev/null || true
        chmod 775 "${METRICS_DAILY_DIR}" 2>/dev/null || true
        chmod 775 "${METRICS_WEEKLY_DIR}" 2>/dev/null || true
    fi
    echo "set-cache-permissions: cache permissions applied"
else
    echo "set-cache-permissions: warning: CACHE_DIR missing (${CACHE_DIR}); skipping cache tree"
fi

# FTP lives under CACHE_DIR. Do not mkdir cache/ftp when CACHE_DIR is absent: mkdir -p would
# create the cache tree as root and bypass the www-data mkdir + chown path from the entrypoint.
if [ -d "${CACHE_DIR}" ]; then
    if [ ! -d "${FTP_DIR}" ]; then
        echo "set-cache-permissions: creating FTP_DIR ${FTP_DIR}"
        mkdir -p "${FTP_DIR}" || echo "set-cache-permissions: warning: mkdir FTP_DIR failed"
    fi
    chown root:root "${FTP_DIR}" 2>/dev/null || true
    chmod 755 "${FTP_DIR}" 2>/dev/null || true
    echo "set-cache-permissions: FTP parent ${FTP_DIR}"
else
    echo "set-cache-permissions: skipping FTP parent (CACHE_DIR not a directory)"
fi

# SFTP chroot parent: root:root 755
if [ ! -d "${SFTP_DIR}" ]; then
    echo "set-cache-permissions: creating SFTP_DIR ${SFTP_DIR}"
    mkdir -p "${SFTP_DIR}" || echo "set-cache-permissions: warning: mkdir SFTP_DIR failed"
fi
chown root:root "${SFTP_DIR}" 2>/dev/null || true
chmod 755 "${SFTP_DIR}" 2>/dev/null || true
echo "set-cache-permissions: SFTP parent ${SFTP_DIR}"

# Per-user SFTP chroots (host bind mount under /tmp/aviationwx-cache/sftp on production).
REPAIR_SFTP_SCRIPT="/usr/local/libexec/aviationwx/repair-sftp-chroot-permissions.sh"
if [ ! -x "${REPAIR_SFTP_SCRIPT}" ]; then
    REPAIR_SFTP_SCRIPT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/repair-sftp-chroot-permissions.sh"
fi
REPAIR_EXIT=0
if [ -x "${REPAIR_SFTP_SCRIPT}" ]; then
    if SFTP_DIR="${SFTP_DIR}" "${REPAIR_SFTP_SCRIPT}"; then
        echo "set-cache-permissions: SFTP chroot directories repaired"
    else
        echo "set-cache-permissions: error: SFTP chroot repair failed" >&2
        REPAIR_EXIT=1
    fi
else
    echo "set-cache-permissions: error: repair-sftp-chroot-permissions.sh not found" >&2
    REPAIR_EXIT=1
fi

echo "set-cache-permissions: done"
exit "${REPAIR_EXIT}"
