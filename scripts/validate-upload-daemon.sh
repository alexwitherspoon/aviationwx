#!/bin/bash
# Local validation gate for ProFTPD upload daemon (PASV matrix + optional STOR).
# Run inside the web container after sync-push-config has provisioned users.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PASV_PROBE="${SCRIPT_DIR}/pasv-probe.py"
if [ ! -f "$PASV_PROBE" ]; then
    PASV_PROBE="/var/www/html/scripts/pasv-probe.py"
fi

CONFIG_PATH="${CONFIG_PATH:-/var/www/html/config/airports.json}"
VALIDATE_UPLOAD_HOST="${VALIDATE_UPLOAD_HOST:-127.0.0.1}"
PASV_PROBE_PASSWORD_ENV="${PASV_PROBE_PASSWORD_ENV:-AVIATIONWX_FTP_PROBE_PASSWORD}"

if [ -x /usr/local/bin/php ]; then
    APP_PHP=/usr/local/bin/php
else
    APP_PHP="${APP_PHP:-php}"
fi

read_probe_settings() {
    CONFIG_PATH="$CONFIG_PATH" VALIDATE_UPLOAD_HOST="$VALIDATE_UPLOAD_HOST" "$APP_PHP" -r '
        require_once "/var/www/html/lib/config.php";
        require_once "/var/www/html/lib/proftpd-auth.php";
        require_once "/var/www/html/lib/upload-endpoints.php";

        $settings = getUploadHealthProbeSettings();
        $parsed = parseProftpdPasswdFile();
        $user = $settings["ftps"]["username"] ?? "";
        $pass = $settings["ftps"]["password"] ?? "";
        $credentialSource = "probe";

        if ($user === "" || $pass === "") {
            foreach (array_keys($parsed["users"]) as $candidate) {
                $config = loadConfig();
                foreach ($config["airports"] ?? [] as $airport) {
                    foreach ($airport["webcams"] ?? [] as $cam) {
                        $push = $cam["push_config"] ?? null;
                        if (!is_array($push)) {
                            continue;
                        }
                        if (($push["username"] ?? "") === $candidate && ($push["password"] ?? "") !== "") {
                            $user = $candidate;
                            $pass = $push["password"];
                            $credentialSource = "camera";
                            break 3;
                        }
                    }
                }
            }
        }

        echo json_encode([
            "connect_host" => getenv("VALIDATE_UPLOAD_HOST") ?: "127.0.0.1",
            "ftp_port" => (int) ($settings["ftp_port"] ?? 2121),
            "ftps_user" => $user,
            "ftps_pass" => $pass,
            "ftp_home" => $parsed["users"][$user]["home"] ?? "",
            "credential_source" => $credentialSource,
            "tls_enabled" => isProftpdTlsEnabled(),
            "cached_ipv4" => (readUploadEndpointsCache() ?? [])["ipv4"] ?? null,
        ], JSON_UNESCAPED_SLASHES);
    ' 2>/dev/null
}

fail() {
    echo "validate-upload-daemon: $*" >&2
    exit 1
}

command -v python3 >/dev/null 2>&1 || fail "python3 required"

if ! pgrep -x proftpd >/dev/null 2>&1; then
    fail "proftpd is not running"
fi

settings="$(read_probe_settings)"
if [ -z "$settings" ]; then
    fail "could not read validation settings"
fi

host="$(echo "$settings" | python3 -c 'import json,sys; print(json.load(sys.stdin)["connect_host"])')"
port="$(echo "$settings" | python3 -c 'import json,sys; print(json.load(sys.stdin)["ftp_port"])')"
user="$(echo "$settings" | python3 -c 'import json,sys; print(json.load(sys.stdin)["ftps_user"])')"
pass="$(echo "$settings" | python3 -c 'import json,sys; print(json.load(sys.stdin)["ftps_pass"])')"
upload_home="$(echo "$settings" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("ftp_home") or "")')"
credential_source="$(echo "$settings" | python3 -c 'import json,sys; print(json.load(sys.stdin)["credential_source"])')"
tls_enabled="$(echo "$settings" | python3 -c 'import json,sys; print("true" if json.load(sys.stdin)["tls_enabled"] else "false")')"
cached_ipv4="$(echo "$settings" | python3 -c 'import json,sys; v=json.load(sys.stdin).get("cached_ipv4"); print(v if v else "")')"

if [ -z "$user" ] || [ -z "$pass" ]; then
    fail "no FTP credentials available (run sync-push-config.php first)"
fi

modes=(pasv-plain epsv-plain)
if [ "$tls_enabled" = "true" ]; then
    modes+=(pasv-ftps epsv-ftps)
fi

echo "validate-upload-daemon: host=${host} port=${port} credential_source=${credential_source} modes=${modes[*]}"
export "${PASV_PROBE_PASSWORD_ENV}=${pass}"
for mode in "${modes[@]}"; do
    result="$(python3 "$PASV_PROBE" --host "$host" --port "$port" --user "$user" \
        --password-env "$PASV_PROBE_PASSWORD_ENV" --mode "$mode" 2>&1)" || {
        rc=$?
        if [ "$rc" -eq 2 ]; then
            fail "bad PASV (0,0,0,0) in mode ${mode}"
        fi
        fail "probe failed for mode ${mode}"
    }
    pasv_ip="$(echo "$result" | python3 -c 'import json,sys; j=json.load(sys.stdin); print(j.get("pasv_ip") or j.get("response") or "")')"
    if [ -n "$cached_ipv4" ] && [ "$mode" = "pasv-plain" ] && [ "$pasv_ip" != "$cached_ipv4" ]; then
        fail "PASV ip ${pasv_ip} does not match endpoint cache ${cached_ipv4}"
    fi
    echo "  ${mode}: ok pasv_ip=${pasv_ip}"
done

# STOR: use ftplib (same as cameras). curl STOR against ProFTPD can create root-owned files.
stor_file="/tmp/aviationwx-validate-upload-$$.txt"
stor_remote="aviationwx-validate-upload.txt"
printf 'aviationwx validate upload %s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" >"$stor_file"
stor_mode="pasv-plain"
if [ "$tls_enabled" = "true" ]; then
    stor_mode="pasv-ftps"
fi
if ! python3 "$PASV_PROBE" --host "$host" --port "$port" --user "$user" \
    --password-env "$PASV_PROBE_PASSWORD_ENV" \
    --mode "$stor_mode" --stor "$stor_remote" --stor-data "$(cat "$stor_file")" >/dev/null 2>&1; then
    unset "${PASV_PROBE_PASSWORD_ENV}"
    rm -f "$stor_file"
    fail "STOR upload failed"
fi
unset "${PASV_PROBE_PASSWORD_ENV}"
rm -f "$stor_file"
echo "  stor: ok remote=${stor_remote}"

# Permission contract: inbox ftp:www-data 2775; uploaded file ftp:www-data 664 (Umask 002).
upload_home="$(printf '%s' "${upload_home}" | tr -d '[:space:]')"
if [ -z "${upload_home}" ]; then
    fail "could not resolve ProFTPD homedir for ${user}"
fi
remote_path="${upload_home}/${stor_remote}"
if [ ! -f "${remote_path}" ]; then
    fail "STOR file missing at ${remote_path}"
fi
dir_owner="$(stat -c '%U:%G' "${upload_home}")"
dir_mode="$(stat -c '%a' "${upload_home}")"
file_owner="$(stat -c '%U:%G' "${remote_path}")"
file_mode="$(stat -c '%a' "${remote_path}")"
if [ "${dir_owner}" != "ftp:www-data" ] || [ "${dir_mode}" != "2775" ]; then
    fail "inbox permissions ${dir_owner} ${dir_mode} (expected ftp:www-data 2775) at ${upload_home}"
fi

# Docker Desktop virtiofs and some bind mounts report root:root for files created as ftp.
ownership_probe="${upload_home}/.aviationwx-ownership-probe-$$"
bind_mount_masks_ownership=false
if runuser -u ftp -- touch "${ownership_probe}" 2>/dev/null; then
    probe_owner="$(stat -c '%U:%G' "${ownership_probe}" 2>/dev/null || echo '')"
    if [ "${probe_owner}" = "root:root" ]; then
        bind_mount_masks_ownership=true
    fi
fi
rm -f "${ownership_probe}"

if [ "${bind_mount_masks_ownership}" = true ]; then
    echo "  permissions: ok inbox=${dir_owner} ${dir_mode} (file owner/mode skipped: bind mount masks ftp ownership)"
elif [ "${file_owner}" != "ftp:www-data" ] || [ "${file_mode}" != "664" ]; then
    fail "upload file permissions ${file_owner} ${file_mode} (expected ftp:www-data 664) at ${remote_path}"
else
    echo "  permissions: ok inbox=${dir_owner} ${dir_mode} file=${file_owner} ${file_mode}"
fi
if ! runuser -u www-data -- test -r "${remote_path}"; then
    fail "www-data cannot read uploaded file at ${remote_path}"
fi
if ! runuser -u www-data -- test -w "${remote_path}"; then
    fail "www-data cannot write uploaded file at ${remote_path}"
fi

# SIGHUP reload must not drop the daemon (auth/runtime refresh path).
if [ -f /var/run/proftpd.pid ]; then
    pid="$(tr -d '[:space:]' </var/run/proftpd.pid)"
    if [[ "$pid" =~ ^[0-9]+$ ]] && kill -HUP "$pid" 2>/dev/null; then
        sleep 1
        if ! pgrep -x proftpd >/dev/null 2>&1; then
            fail "proftpd exited after SIGHUP reload"
        fi
        echo "  reload: ok (SIGHUP)"
    fi
fi

echo "validate-upload-daemon: all probes passed"
