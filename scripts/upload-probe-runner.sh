#!/bin/bash
# Runs upload-probe.sh on config.upload_health_probe.interval_sec (default 30).

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
COMMON_SH="${SCRIPT_DIR}/upload-daemon-common.sh"
if [ ! -f "$COMMON_SH" ]; then
    COMMON_SH="/usr/local/libexec/aviationwx/upload-daemon-common.sh"
fi
# shellcheck source=upload-daemon-common.sh
source "$COMMON_SH"

PROBE_SCRIPT="${SCRIPT_DIR}/upload-probe.sh"
PROBE_LOG="${PROBE_LOG:-/var/log/aviationwx/upload-probe.log}"

log_probe_runner() {
    local level="INFO"
    local msg="$1"
    if [[ "$msg" == WARN\ * ]]; then
        level="WARN"
        msg="${msg#WARN }"
    elif [[ "$msg" == ERROR\ * ]]; then
        level="ERROR"
        msg="${msg#ERROR }"
    fi
    local ts
    ts="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
    echo "[$ts] [$level] $msg" >>"$PROBE_LOG"
}

# Wait for entrypoint background sync before the first probe (probe accounts live in /etc).
wait_for_push_config_sync() {
    local max_sec="${UPLOAD_PROBE_SYNC_WAIT_SEC:-90}"
    local start_sec elapsed
    start_sec="$(date +%s 2>/dev/null || echo 0)"
    while true; do
        if [ -f /tmp/sync-push-config.log ]; then
            if grep -q 'FTP/SFTP/FTPS configuration synced successfully' /tmp/sync-push-config.log 2>/dev/null; then
                log_probe_runner "push-config sync finished before first probe"
                return 0
            fi
            if grep -q 'configuration sync failed or timed out' /tmp/sync-push-config.log 2>/dev/null; then
                log_probe_runner "WARN push-config sync failed; running probe anyway"
                return 0
            fi
        fi
        elapsed=$(( $(date +%s 2>/dev/null || echo 0) - start_sec ))
        if [ "$elapsed" -ge "$max_sec" ]; then
            log_probe_runner "WARN push-config sync wait timeout (${max_sec}s); running probe anyway"
            return 0
        fi
        sleep 2
    done
}

log_probe_runner "upload-probe-runner started"
wait_for_push_config_sync

while true; do
    interval="$(read_probe_interval_from_config)"
    if ! [[ "$interval" =~ ^[0-9]+$ ]] || [ "$interval" -lt 1 ]; then
        interval="$UPLOAD_PROBE_INTERVAL_SEC"
    fi
    export UPLOAD_PROBE_INTERVAL_SEC="$interval"

    if [ -x "$PROBE_SCRIPT" ]; then
        "$PROBE_SCRIPT" || true
    else
        log_probe_runner "ERROR missing probe script: $PROBE_SCRIPT"
        log_upload_health_app "error" "upload-probe-runner: probe script missing" \
            "{\"path\":\"${PROBE_SCRIPT}\"}"
    fi
    sleep "$interval"
done
