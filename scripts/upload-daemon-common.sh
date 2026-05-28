#!/bin/bash
# Shared helpers for upload health probe and service-watchdog (vsftpd / container sshd).
# Sourced by scripts under /usr/local/libexec/aviationwx/ at runtime.

UPLOAD_PROBE_INTERVAL_SEC="${UPLOAD_PROBE_INTERVAL_SEC:-30}"
WATCHDOG_LOOP_SEC="${WATCHDOG_LOOP_SEC:-50}"
UPLOAD_PROBE_FAIL_STREAK_THRESHOLD="${UPLOAD_PROBE_FAIL_STREAK_THRESHOLD:-2}"
UPLOAD_DAEMON_RESTART_MIN_INTERVAL_SEC="${UPLOAD_DAEMON_RESTART_MIN_INTERVAL_SEC:-1800}"

UPLOAD_PROBE_STATE_FILE="${UPLOAD_PROBE_STATE_FILE:-/var/lib/aviationwx/upload-probe.json}"
VSFTPD_RESTART_LOCK="${VSFTPD_RESTART_LOCK:-/var/lib/aviationwx/vsftpd.restart.lock}"
SSHD_RESTART_LOCK="${SSHD_RESTART_LOCK:-/var/lib/aviationwx/sshd.restart.lock}"
VSFTPD_CONF="${VSFTPD_CONF:-/etc/vsftpd/vsftpd.conf}"

CONFIG_PATH="${CONFIG_PATH:-/var/www/html/config/airports.json}"
if [ -x /usr/local/bin/php ]; then
    APP_PHP=/usr/local/bin/php
else
    APP_PHP="${APP_PHP:-php}"
fi

php_as_www_data() {
    if command -v runuser >/dev/null 2>&1 && [ "$(id -u)" -eq 0 ]; then
        runuser -u www-data -- env "CONFIG_PATH=${CONFIG_PATH}" "$APP_PHP" "$@"
    else
        env "CONFIG_PATH=${CONFIG_PATH}" "$APP_PHP" "$@"
    fi
}

watchdog_log() {
    local level="$1"
    shift
    local msg="$*"
    local ts log_file
    ts="$(date '+%Y-%m-%d %H:%M:%S')"
    log_file="${WATCHDOG_LOG_FILE:-/var/log/aviationwx/service-watchdog.log}"
    mkdir -p "$(dirname "$log_file")" 2>/dev/null || true
    echo "[$ts] [$level] $msg" >>"$log_file"
}

ensure_aviationwx_state_dir() {
    mkdir -p "$(dirname "$UPLOAD_PROBE_STATE_FILE")" 2>/dev/null || true
    mkdir -p "$(dirname "$VSFTPD_RESTART_LOCK")" 2>/dev/null || true
    mkdir -p "$(dirname "$SSHD_RESTART_LOCK")" 2>/dev/null || true
}

# Structured app.log entry for upload daemon diagnostics (no credentials in context).
log_upload_health_app() {
    local level="$1"
    local message="$2"
    local context_json="${3:-{}}"
    AVWX_LOG_LEVEL="$level" AVWX_LOG_MSG="$message" AVWX_LOG_CTX="$context_json" \
        php_as_www_data -r '
            require_once "/var/www/html/lib/logger.php";
            $ctx = json_decode(getenv("AVWX_LOG_CTX") ?: "{}", true);
            if (!is_array($ctx)) {
                $ctx = [];
            }
            aviationwx_log(
                getenv("AVWX_LOG_LEVEL") ?: "info",
                getenv("AVWX_LOG_MSG") ?: "",
                $ctx,
                "app"
            );
        ' 2>/dev/null || true
}

read_probe_interval_from_config() {
    php_as_www_data -r 'require_once "/var/www/html/lib/config.php"; echo (int) getUploadHealthProbeSettings()["interval_sec"];' 2>/dev/null \
        | tr -d '[:space:]' || echo "$UPLOAD_PROBE_INTERVAL_SEC"
}

# Returns 0 when epoch is a positive integer suitable for heartbeat age math.
probe_heartbeat_epoch_is_valid() {
    local epoch="$1"
    [[ "$epoch" =~ ^[0-9]+$ ]] && [ "$epoch" -gt 0 ]
}

read_probe_stale_sec_from_heartbeat() {
    if [ -f "$UPLOAD_PROBE_STATE_FILE" ] && command -v jq >/dev/null 2>&1; then
        local from_file
        from_file="$(jq -r '.stale_sec // empty' "$UPLOAD_PROBE_STATE_FILE" 2>/dev/null || true)"
        if [[ "$from_file" =~ ^[0-9]+$ ]] && [ "$from_file" -gt 0 ]; then
            echo "$from_file"
            return 0
        fi
    fi
    php_as_www_data -r 'require_once "/var/www/html/lib/config.php"; echo (int) getUploadHealthProbeSettings()["stale_sec"];' 2>/dev/null \
        | tr -d '[:space:]' || echo "$((UPLOAD_PROBE_INTERVAL_SEC * 2 + 15))"
}

read_int_file() {
    local path="$1"
    local default="${2:-0}"
    local raw
    if [ ! -f "$path" ]; then
        echo "$default"
        return 0
    fi
    raw="$(tr -d '[:space:]' <"$path" 2>/dev/null || echo "")"
    if [[ "$raw" =~ ^[0-9]+$ ]]; then
        echo "$raw"
    else
        echo "$default"
    fi
}

write_int_file() {
    local path="$1"
    local value="$2"
    mkdir -p "$(dirname "$path")" 2>/dev/null || true
    printf '%s' "$value" >"$path"
}

increment_streak() {
    local path="$1"
    local current
    current="$(read_int_file "$path" 0)"
    current=$((current + 1))
    write_int_file "$path" "$current"
    echo "$current"
}

reset_streak() {
    write_int_file "$1" "0"
}

can_restart_daemon() {
    local last_restart_file="$1"
    local now last
    now="$(date +%s)"
    last="$(read_int_file "$last_restart_file" 0)"
    if [ "$last" -eq 0 ]; then
        return 0
    fi
    [ $((now - last)) -ge "$UPLOAD_DAEMON_RESTART_MIN_INTERVAL_SEC" ]
}

record_daemon_restart() {
    write_int_file "$1" "$(date +%s)"
}

restart_vsftpd_daemon() {
    local reason="${1:-unspecified}"
    ensure_aviationwx_state_dir
    if ! command -v flock >/dev/null 2>&1; then
        watchdog_log "ERROR" "flock not available; skipping vsftpd restart ($reason)"
        log_upload_health_app "error" "vsftpd restart skipped: flock unavailable" "{\"reason\":\"${reason}\"}"
        return 1
    fi
    if ! flock -n "$VSFTPD_RESTART_LOCK" bash -c "
        set -euo pipefail
        if [ ! -f \"$VSFTPD_CONF\" ]; then
            echo 'vsftpd.conf missing' >&2
            exit 1
        fi
        pkill -x vsftpd 2>/dev/null || true
        sleep 1
        for _ in 1 2 3 4 5; do
            pgrep -x vsftpd >/dev/null 2>&1 || break
            sleep 1
        done
        if pgrep -x vsftpd >/dev/null 2>&1; then
            pkill -9 -x vsftpd 2>/dev/null || true
            sleep 1
        fi
        /usr/sbin/vsftpd \"$VSFTPD_CONF\" &
        sleep 2
        pgrep -x vsftpd >/dev/null 2>&1
    "; then
        watchdog_log "ERROR" "vsftpd restart failed or lock held ($reason)"
        log_upload_health_app "error" "vsftpd restart failed" "{\"reason\":\"${reason}\"}"
        return 1
    fi
    watchdog_log "INFO" "vsftpd restarted ($reason)"
    log_upload_health_app "warning" "vsftpd restarted by upload health watchdog" "{\"reason\":\"${reason}\"}"
    return 0
}

restart_container_sshd() {
    local reason="${1:-unspecified}"
    ensure_aviationwx_state_dir
    if ! command -v flock >/dev/null 2>&1; then
        watchdog_log "ERROR" "flock not available; skipping sshd restart ($reason)"
        log_upload_health_app "error" "container sshd restart skipped: flock unavailable" "{\"reason\":\"${reason}\"}"
        return 1
    fi
    if ! flock -n "$SSHD_RESTART_LOCK" bash -c "
        set -euo pipefail
        if command -v service >/dev/null 2>&1; then
            service ssh restart
        else
            pkill -HUP sshd 2>/dev/null || true
        fi
        sleep 2
        pgrep -x sshd >/dev/null 2>&1
    "; then
        watchdog_log "ERROR" "container sshd restart failed or lock held ($reason)"
        log_upload_health_app "error" "container sshd restart failed" "{\"reason\":\"${reason}\"}"
        return 1
    fi
    watchdog_log "INFO" "container sshd restarted ($reason)"
    log_upload_health_app "warning" "container sshd restarted by upload health watchdog" "{\"reason\":\"${reason}\"}"
    return 0
}

# Throttled daemon restart after probe failure streak (shared by vsftpd and container sshd).
try_restart_upload_daemon() {
    local protocol="$1"
    local streak_file="$2"
    local last_restart_file="$3"
    local restart_fn="$4"
    local streak reason

    streak="$(read_int_file "$streak_file" 0)"
    if [ "$streak" -lt "$UPLOAD_PROBE_FAIL_STREAK_THRESHOLD" ]; then
        return 1
    fi
    if ! can_restart_daemon "$last_restart_file"; then
        watchdog_log "ERROR" "${protocol} unhealthy but restart throttled (streak=${streak})"
        log_upload_health_app "error" "upload daemon restart throttled" \
            "{\"protocol\":\"${protocol}\",\"streak\":${streak}}"
        return 1
    fi
    reason="probe unhealthy streak=${streak}"
    if "$restart_fn" "$reason"; then
        record_daemon_restart "$last_restart_file"
        reset_streak "$streak_file"
        watchdog_log "INFO" "${protocol} recovered via daemon restart"
        return 0
    fi
    return 1
}
