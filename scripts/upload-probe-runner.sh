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
    local ts msg
    ts="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
    msg="$1"
    echo "[$ts] [INFO] $msg" >>"$PROBE_LOG"
}

log_probe_runner "upload-probe-runner started"

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
