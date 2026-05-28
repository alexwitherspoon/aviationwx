#!/bin/bash
# Monitors vsftpd, container sshd (SFTP), and cron; evaluates upload-probe heartbeats.
# Loop interval: WATCHDOG_LOOP_SEC (default 50). Probes publish per config interval_sec.

set -u

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
COMMON_SH="${SCRIPT_DIR}/upload-daemon-common.sh"
if [ ! -f "$COMMON_SH" ]; then
    COMMON_SH="/usr/local/libexec/aviationwx/upload-daemon-common.sh"
fi
if [ ! -f "$COMMON_SH" ]; then
    echo "upload-daemon-common.sh not found" >&2
    exit 1
fi
# shellcheck source=upload-daemon-common.sh
source "$COMMON_SH"

WATCHDOG_LOG_FILE="${WATCHDOG_LOG_FILE:-/var/log/aviationwx/service-watchdog.log}"
FTPS_FAIL_STREAK_FILE="${FTPS_FAIL_STREAK_FILE:-/var/lib/aviationwx/upload-watchdog-ftps.fail-streak}"
SFTP_FAIL_STREAK_FILE="${SFTP_FAIL_STREAK_FILE:-/var/lib/aviationwx/upload-watchdog-sftp.fail-streak}"
FTPS_LAST_RESTART_FILE="${FTPS_LAST_RESTART_FILE:-/var/lib/aviationwx/upload-watchdog-ftps.last-restart}"
SFTP_LAST_RESTART_FILE="${SFTP_LAST_RESTART_FILE:-/var/lib/aviationwx/upload-watchdog-sftp.last-restart}"

MAX_PROCESS_RESTART_ATTEMPTS=5
RESTART_BACKOFF_BASE=60

declare -A restart_counts
declare -A last_restart_time
restart_counts["cron"]=0
last_restart_time["cron"]=0

check_and_restart_cron() {
    local current_time last_restart restart_count backoff_seconds
    if pgrep -x cron >/dev/null 2>&1; then
        if [ "${restart_counts[cron]:-0}" -gt 0 ]; then
            watchdog_log "INFO" "Service cron recovered, resetting restart counter"
            restart_counts[cron]=0
            last_restart_time[cron]=0
        fi
        return 0
    fi

    current_time="$(date +%s)"
    last_restart="${last_restart_time[cron]:-0}"
    restart_count="${restart_counts[cron]:-0}"
    backoff_seconds=$((RESTART_BACKOFF_BASE * (2 ** restart_count)))
    if [ "$backoff_seconds" -gt 960 ]; then
        backoff_seconds=960
    fi

    if [ "$restart_count" -ge "$MAX_PROCESS_RESTART_ATTEMPTS" ]; then
        if [ $((current_time - last_restart)) -gt 3600 ]; then
            restart_counts[cron]=0
            restart_count=0
        else
            watchdog_log "WARN" "cron down; max restart attempts reached"
            return 1
        fi
    fi

    if [ $((current_time - last_restart)) -lt "$backoff_seconds" ]; then
        return 1
    fi

    watchdog_log "WARN" "cron down; restart attempt $((restart_count + 1))/$MAX_PROCESS_RESTART_ATTEMPTS"
    if cron >>"$WATCHDOG_LOG_FILE" 2>&1; then
        restart_counts[cron]=$((restart_count + 1))
        last_restart_time[cron]=$current_time
        sleep 2
        if pgrep -x cron >/dev/null 2>&1; then
            watchdog_log "INFO" "cron restarted successfully"
            return 0
        fi
    fi
    restart_counts[cron]=$((restart_count + 1))
    last_restart_time[cron]=$current_time
    watchdog_log "ERROR" "cron restart failed"
    return 1
}

# Fail closed: return 1 when probe evaluation is unhealthy.
evaluate_probe_protocol() {
    local protocol="$1"
    local streak_file="$2"
    local last_restart_file="$3"
    local restart_fn="$4"
    local stale_sec skipped ok epoch now age streak detail

    if ! command -v jq >/dev/null 2>&1; then
        watchdog_log "ERROR" "jq required for upload probe evaluation ($protocol)"
        log_upload_health_app "error" "upload watchdog cannot evaluate probe: jq missing" \
            "{\"protocol\":\"${protocol}\"}"
        increment_streak "$streak_file" >/dev/null
        try_restart_upload_daemon "$protocol" "$streak_file" "$last_restart_file" "$restart_fn"
        return 1
    fi

    stale_sec="$(read_probe_stale_sec_from_heartbeat)"
    if ! [[ "$stale_sec" =~ ^[0-9]+$ ]]; then
        stale_sec=75
    fi

    if [ ! -f "$UPLOAD_PROBE_STATE_FILE" ]; then
        increment_streak "$streak_file" >/dev/null
        watchdog_log "WARN" "upload probe heartbeat missing ($protocol)"
        log_upload_health_app "error" "upload probe heartbeat missing" "{\"protocol\":\"${protocol}\"}"
        try_restart_upload_daemon "$protocol" "$streak_file" "$last_restart_file" "$restart_fn"
        return 1
    fi

    if ! jq -e . "$UPLOAD_PROBE_STATE_FILE" >/dev/null 2>&1; then
        increment_streak "$streak_file" >/dev/null
        watchdog_log "WARN" "upload probe heartbeat corrupt ($protocol)"
        log_upload_health_app "error" "upload probe heartbeat corrupt" "{\"protocol\":\"${protocol}\"}"
        try_restart_upload_daemon "$protocol" "$streak_file" "$last_restart_file" "$restart_fn"
        return 1
    fi

    skipped="$(jq -r ".${protocol}.skipped // false" "$UPLOAD_PROBE_STATE_FILE" 2>/dev/null || echo false)"
    if [ "$skipped" = "true" ]; then
        reset_streak "$streak_file"
        return 0
    fi

    ok="$(jq -r ".${protocol}.ok // false" "$UPLOAD_PROBE_STATE_FILE")"
    epoch="$(jq -r '.epoch // empty' "$UPLOAD_PROBE_STATE_FILE")"
    now="$(date +%s)"

    if ! probe_heartbeat_epoch_is_valid "$epoch"; then
        streak="$(increment_streak "$streak_file")"
        watchdog_log "WARN" "${protocol} probe heartbeat invalid epoch (value=${epoch:-missing} streak=${streak})"
        log_upload_health_app "error" "upload probe heartbeat invalid epoch" \
            "$(jq -n --arg protocol "$protocol" --arg epoch "${epoch:-}" --argjson streak "$streak" \
                '{protocol: $protocol, epoch: $epoch, streak: $streak}')"
        ok="false"
    else
        age=$((now - epoch))
        if [ "$age" -gt "$stale_sec" ]; then
            streak="$(increment_streak "$streak_file")"
            watchdog_log "WARN" "${protocol} probe stale (age=${age}s stale_sec=${stale_sec} streak=${streak})"
            log_upload_health_app "error" "upload probe heartbeat stale" \
                "{\"protocol\":\"${protocol}\",\"age_sec\":${age},\"stale_sec\":${stale_sec},\"streak\":${streak}}"
            ok="false"
        elif [ "$ok" = "true" ]; then
            reset_streak "$streak_file"
            return 0
        else
            streak="$(increment_streak "$streak_file")"
            detail="$(jq -r ".${protocol}.detail // \"\"" "$UPLOAD_PROBE_STATE_FILE")"
            watchdog_log "WARN" "${protocol} probe failed (streak=${streak} detail=${detail})"
            log_upload_health_app "error" "upload probe check failed" \
                "$(jq -n --arg protocol "$protocol" --argjson streak "$streak" --arg detail "$detail" \
                    '{protocol: $protocol, streak: $streak, detail: $detail}')"
        fi
    fi

    if [ "$ok" != "true" ]; then
        try_restart_upload_daemon "$protocol" "$streak_file" "$last_restart_file" "$restart_fn"
        return 1
    fi
    return 0
}

handle_upload_daemon_down() {
    local protocol="$1"
    local process_name="$2"
    local streak_file="$3"
    local last_restart_file="$4"
    local restart_fn="$5"

    watchdog_log "WARN" "${protocol} process not running (${process_name})"
    log_upload_health_app "error" "upload daemon process not running" "{\"protocol\":\"${protocol}\"}"
    increment_streak "$streak_file" >/dev/null
    try_restart_upload_daemon "$protocol" "$streak_file" "$last_restart_file" "$restart_fn"
}

watchdog_log "INFO" "Service watchdog started (loop=${WATCHDOG_LOOP_SEC}s)"

while true; do
    set +e

    if ! pgrep -x vsftpd >/dev/null 2>&1; then
        handle_upload_daemon_down "ftps" "vsftpd" "$FTPS_FAIL_STREAK_FILE" "$FTPS_LAST_RESTART_FILE" restart_vsftpd_daemon
    else
        evaluate_probe_protocol "ftps" "$FTPS_FAIL_STREAK_FILE" "$FTPS_LAST_RESTART_FILE" restart_vsftpd_daemon
    fi

    if ! pgrep -x sshd >/dev/null 2>&1; then
        handle_upload_daemon_down "sftp" "sshd" "$SFTP_FAIL_STREAK_FILE" "$SFTP_LAST_RESTART_FILE" restart_container_sshd
    else
        evaluate_probe_protocol "sftp" "$SFTP_FAIL_STREAK_FILE" "$SFTP_LAST_RESTART_FILE" restart_container_sshd
    fi

    check_and_restart_cron

    set -u
    sleep "$WATCHDOG_LOOP_SEC"
done
