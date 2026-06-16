#!/bin/bash
# Functional FTPS/SFTP upload probe; writes heartbeat for service-watchdog.
# Run every interval_sec via upload-probe-runner.sh (not invoked by watchdog).

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
COMMON_SH="${SCRIPT_DIR}/upload-daemon-common.sh"
if [ ! -f "$COMMON_SH" ]; then
    COMMON_SH="/usr/local/libexec/aviationwx/upload-daemon-common.sh"
fi
# shellcheck source=upload-daemon-common.sh
source "$COMMON_SH"

PROBE_LOG="${PROBE_LOG:-/var/log/aviationwx/upload-probe.log}"
PROBE_TMP_DIR="${PROBE_TMP_DIR:-/tmp/aviationwx-upload-probe}"
PROBE_FILE_PREFIX="${UPLOAD_HEALTH_PROBE_FILE_PREFIX:-aviationwx-probe-}"
PROBE_REMOTE_FILENAME="${PROBE_FILE_PREFIX}healthcheck.txt"
PROBE_NETRC_FILE=""

log_probe() {
    local level="$1"
    shift
    local ts
    ts="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
    echo "[$ts] [$level] $*" >>"$PROBE_LOG"
}

write_heartbeat() {
    local json="$1"
    local tmp="${UPLOAD_PROBE_STATE_FILE}.tmp.$$"
    mkdir -p "$(dirname "$UPLOAD_PROBE_STATE_FILE")" 2>/dev/null || true
    printf '%s\n' "$json" >"$tmp"
    chmod 600 "$tmp" 2>/dev/null || true
    mv -f "$tmp" "$UPLOAD_PROBE_STATE_FILE"
    chmod 600 "$UPLOAD_PROBE_STATE_FILE" 2>/dev/null || true
}

read_config_json() {
    php_as_www_data -r 'require_once "/var/www/html/lib/config.php"; echo json_encode(getUploadHealthProbeSettings(), JSON_UNESCAPED_SLASHES);' 2>/dev/null || echo ''
}

write_disabled_heartbeat() {
    local now_epoch now_iso interval stale_sec heartbeat
    now_epoch="$(date +%s)"
    now_iso="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
    interval="$(read_probe_interval_from_config)"
    if ! [[ "$interval" =~ ^[0-9]+$ ]]; then
        interval="$UPLOAD_PROBE_INTERVAL_SEC"
    fi
    stale_sec="$(php_as_www_data -r 'require_once "/var/www/html/lib/config.php"; echo (int) getUploadHealthProbeSettings()["stale_sec"];' 2>/dev/null | tr -d '[:space:]' || echo "$((interval * 2 + 15))")"
    heartbeat="$(jq -n \
        --arg ts "$now_iso" \
        --argjson epoch "$now_epoch" \
        --argjson interval "$interval" \
        --argjson stale_sec "$stale_sec" \
        '{ts: $ts, epoch: $epoch, interval_sec: $interval, stale_sec: $stale_sec, ftps: {ok: true, skipped: true, duration_sec: 0, detail: "disabled"}, sftp: {ok: true, skipped: true, duration_sec: 0, detail: "disabled"}}')"
    write_heartbeat "$heartbeat"
}

probe_netrc_cleanup() {
    if [ -n "$PROBE_NETRC_FILE" ] && [ -f "$PROBE_NETRC_FILE" ]; then
        rm -f "$PROBE_NETRC_FILE"
    fi
    PROBE_NETRC_FILE=""
}

probe_setup_netrc() {
    local host="$1" user="$2" pass="$3"
    probe_netrc_cleanup
    mkdir -p "$PROBE_TMP_DIR"
    PROBE_NETRC_FILE="$(mktemp "${PROBE_TMP_DIR}/curl-netrc.XXXXXX")"
    chmod 600 "$PROBE_NETRC_FILE"
    {
        printf 'machine %s\n' "$host"
        printf 'login %s\n' "$user"
        printf 'password %s\n' "$pass"
    } >"$PROBE_NETRC_FILE"
}

ensure_sftp_known_hosts() {
    local host="$1"
    local port="$2"
    case "$host" in
        localhost|127.0.0.1|::1) ;;
        *)
            return 0
            ;;
    esac
    if ! command -v ssh-keyscan >/dev/null 2>&1; then
        return 0
    fi
    local ssh_dir="${HOME:-/root}/.ssh"
    local known_hosts="${ssh_dir}/known_hosts"
    mkdir -p "$ssh_dir"
    chmod 700 "$ssh_dir" 2>/dev/null || true
    touch "$known_hosts"
    chmod 600 "$known_hosts" 2>/dev/null || true
    if grep -qF "[${host}]:${port}" "$known_hosts" 2>/dev/null; then
        return 0
    fi
    # Port 22 entries are often stored as "host keytype ..." without [host]:port.
    if [ "$port" = "22" ] && grep -qF "${host} " "$known_hosts" 2>/dev/null; then
        return 0
    fi
    ssh-keyscan -T 5 -p "$port" "$host" >>"$known_hosts" 2>/dev/null || true
}

vsftpd_ssl_enabled() {
    local conf="${VSFTPD_CONF:-/etc/vsftpd/vsftpd.conf}"
    if [ ! -f "$conf" ]; then
        return 1
    fi
    if grep -qE '^[[:space:]]*ssl_enable[[:space:]]*=[[:space:]]*YES' "$conf" 2>/dev/null; then
        return 0
    fi
    return 1
}

run_ftps_probe() {
    local host="$1" port="$2" user="$3" pass="$4"
    local file_name base_url local_file start_sec end_sec elapsed use_tls ok_detail
    local -a curl_tls_args=()
    file_name="$PROBE_REMOTE_FILENAME"
    local_file="${PROBE_TMP_DIR}/${file_name}"
    # ftp:// with --ftp-ssl-reqd uses explicit TLS (AUTH); vsftpd does not use implicit FTPS.
    base_url="ftp://${host}:${port}/"
    if vsftpd_ssl_enabled; then
        use_tls="true"
        curl_tls_args=(--ftp-ssl-reqd)
        ok_detail="ok"
    else
        use_tls="false"
        ok_detail="ok (plain ftp, ssl_enable=NO)"
    fi
    mkdir -p "$PROBE_TMP_DIR"
    printf 'aviationwx upload probe %s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" >"$local_file"
    probe_setup_netrc "$host" "$user" "$pass"
    trap probe_netrc_cleanup RETURN
    start_sec="$(date +%s 2>/dev/null || echo 0)"
    if ! curl -sS --netrc-file "$PROBE_NETRC_FILE" --netrc "${curl_tls_args[@]}" --ftp-pasv \
        --connect-timeout 10 --max-time 45 \
        --upload-file "$local_file" "${base_url}${file_name}" >/dev/null 2>&1; then
        rm -f "$local_file"
        if [ "$use_tls" = "true" ]; then
            echo "false|0|ftps upload failed"
        else
            echo "false|0|ftp upload failed"
        fi
        return 1
    fi
    if ! curl -sS --netrc-file "$PROBE_NETRC_FILE" --netrc "${curl_tls_args[@]}" --ftp-pasv \
        --connect-timeout 10 --max-time 30 \
        --fail -X "DELE ${file_name}" "$base_url" >/dev/null 2>&1; then
        if [ "$use_tls" = "true" ]; then
            log_probe "WARN" "FTPS upload ok but delete failed for ${file_name}"
        else
            log_probe "WARN" "FTP upload ok but delete failed for ${file_name}"
        fi
    fi
    rm -f "$local_file"
    end_sec="$(date +%s 2>/dev/null || echo 0)"
    elapsed=$((end_sec - start_sec))
    if [ "$elapsed" -lt 0 ]; then
        elapsed=0
    fi
    echo "true|${elapsed}|${ok_detail}"
}

run_sftp_probe() {
    local host="$1" port="$2" user="$3" pass="$4"
    local file_name remote_path base_url local_file start_sec end_sec elapsed
    file_name="$PROBE_REMOTE_FILENAME"
    remote_path="files/${file_name}"
    local_file="${PROBE_TMP_DIR}/${file_name}"
    base_url="sftp://${host}:${port}/"
    mkdir -p "$PROBE_TMP_DIR"
    printf 'aviationwx upload probe %s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" >"$local_file"
    ensure_sftp_known_hosts "$host" "$port"
    probe_setup_netrc "$host" "$user" "$pass"
    trap probe_netrc_cleanup RETURN
    start_sec="$(date +%s 2>/dev/null || echo 0)"
    if ! curl -sS --netrc-file "$PROBE_NETRC_FILE" --netrc \
        --connect-timeout 10 --max-time 45 \
        --upload-file "$local_file" "${base_url}${remote_path}" >/dev/null 2>&1; then
        rm -f "$local_file"
        echo "false|0|sftp upload failed"
        return 1
    fi
    # SFTP: curl -X DELE is FTP-only; stable PROBE_REMOTE_FILENAME is overwritten each run.
    rm -f "$local_file"
    end_sec="$(date +%s 2>/dev/null || echo 0)"
    elapsed=$((end_sec - start_sec))
    if [ "$elapsed" -lt 0 ]; then
        elapsed=0
    fi
    echo "true|${elapsed}|ok"
}

main() {
    local config enabled connect_host ftp_port sftp_port interval stale_sec
    local ftps_user ftps_pass sftp_user sftp_pass
    local ftps_ok ftps_duration_sec ftps_detail sftp_ok sftp_duration_sec sftp_detail
    local now_iso now_epoch heartbeat ftps_skipped sftp_skipped

    if ! command -v jq >/dev/null 2>&1; then
        log_probe "ERROR" "jq required for upload probe"
        log_upload_health_app "error" "upload probe cannot run: jq missing" '{}'
        exit 1
    fi

    config="$(read_config_json)"
    if [ -z "$config" ] || ! echo "$config" | jq -e . >/dev/null 2>&1; then
        log_probe "ERROR" "could not read upload health probe config"
        log_upload_health_app "error" "upload probe config unreadable" '{}'
        write_disabled_heartbeat
        exit 1
    fi

    enabled="$(echo "$config" | jq -r '.enabled // false')"
    now_epoch="$(date +%s)"
    now_iso="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
    interval="$(echo "$config" | jq -r '.interval_sec // 30')"
    stale_sec="$(echo "$config" | jq -r '.stale_sec // 75')"

    if [ "$enabled" != "true" ]; then
        write_disabled_heartbeat
        exit 0
    fi

    connect_host="$(echo "$config" | jq -r '.connect_host // .upload_hostname // empty')"
    ftp_port="$(echo "$config" | jq -r '.ftp_port')"
    sftp_port="$(echo "$config" | jq -r '.sftp_port')"

    ftps_user="$(echo "$config" | jq -r '.ftps.username // empty')"
    ftps_pass="$(echo "$config" | jq -r '.ftps.password // empty')"
    sftp_user="$(echo "$config" | jq -r '.sftp.username // empty')"
    sftp_pass="$(echo "$config" | jq -r '.sftp.password // empty')"

    ftps_ok="true"
    ftps_duration_sec=0
    ftps_detail="skipped"
    sftp_ok="true"
    sftp_duration_sec=0
    sftp_detail="skipped"
    ftps_skipped="false"
    sftp_skipped="false"

    if [ -n "$ftps_user" ] && [ -n "$ftps_pass" ]; then
        IFS='|' read -r ftps_ok ftps_duration_sec ftps_detail < <(run_ftps_probe "$connect_host" "$ftp_port" "$ftps_user" "$ftps_pass" || echo "false|0|ftps failed")
        log_probe "INFO" "FTPS probe ok=${ftps_ok} duration_sec=${ftps_duration_sec} detail=${ftps_detail} host=${connect_host}"
        if [ "$ftps_ok" != "true" ]; then
            log_upload_health_app "error" "FTPS upload health probe failed" \
                "$(jq -n --arg detail "$ftps_detail" --arg host "$connect_host" '{detail: $detail, connect_host: $host}')"
        fi
    else
        ftps_skipped="true"
        ftps_detail="no credentials"
    fi

    if [ -n "$sftp_user" ] && [ -n "$sftp_pass" ]; then
        IFS='|' read -r sftp_ok sftp_duration_sec sftp_detail < <(run_sftp_probe "$connect_host" "$sftp_port" "$sftp_user" "$sftp_pass" || echo "false|0|sftp failed")
        log_probe "INFO" "SFTP probe ok=${sftp_ok} duration_sec=${sftp_duration_sec} detail=${sftp_detail} host=${connect_host}"
        if [ "$sftp_ok" != "true" ]; then
            log_upload_health_app "error" "SFTP upload health probe failed" \
                "$(jq -n --arg detail "$sftp_detail" --arg host "$connect_host" '{detail: $detail, connect_host: $host}')"
        fi
    else
        sftp_skipped="true"
        sftp_detail="no credentials"
    fi

    heartbeat="$(jq -n \
        --arg ts "$now_iso" \
        --argjson epoch "$now_epoch" \
        --argjson interval "$interval" \
        --argjson stale_sec "$stale_sec" \
        --argjson ftps_ok "$( [ "$ftps_ok" = "true" ] && echo true || echo false )" \
        --argjson ftps_skipped "$( [ "$ftps_skipped" = "true" ] && echo true || echo false )" \
        --argjson ftps_duration_sec "${ftps_duration_sec:-0}" \
        --arg ftps_detail "$ftps_detail" \
        --argjson sftp_ok "$( [ "$sftp_ok" = "true" ] && echo true || echo false )" \
        --argjson sftp_skipped "$( [ "$sftp_skipped" = "true" ] && echo true || echo false )" \
        --argjson sftp_duration_sec "${sftp_duration_sec:-0}" \
        --arg sftp_detail "$sftp_detail" \
        '{ts: $ts, epoch: $epoch, interval_sec: $interval, stale_sec: $stale_sec, ftps: {ok: $ftps_ok, skipped: $ftps_skipped, duration_sec: $ftps_duration_sec, detail: $ftps_detail}, sftp: {ok: $sftp_ok, skipped: $sftp_skipped, duration_sec: $sftp_duration_sec, detail: $sftp_detail}}')"

    write_heartbeat "$heartbeat"

    if [ "$ftps_ok" = "true" ] && [ "$sftp_ok" = "true" ]; then
        exit 0
    fi
    exit 1
}

main "$@"
