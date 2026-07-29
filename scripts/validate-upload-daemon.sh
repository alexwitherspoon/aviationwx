#!/bin/bash
# Local validation gate for ProFTPD upload daemon (PASV matrix + optional STOR).
# Run inside the web container after sync-push-config has provisioned users.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
COMMON_SH="${SCRIPT_DIR}/upload-daemon-common.sh"
if [ ! -f "$COMMON_SH" ]; then
    COMMON_SH="/usr/local/libexec/aviationwx/upload-daemon-common.sh"
fi
# shellcheck source=upload-daemon-common.sh
source "$COMMON_SH"

PASV_PROBE="${SCRIPT_DIR}/pasv-probe.py"
if [ ! -f "$PASV_PROBE" ]; then
    PASV_PROBE="/var/www/html/scripts/pasv-probe.py"
fi
ISOLATION_PROBE="${SCRIPT_DIR}/ftp-isolation-probe.py"
if [ ! -f "$ISOLATION_PROBE" ]; then
    ISOLATION_PROBE="/var/www/html/scripts/ftp-isolation-probe.py"
fi

CONFIG_PATH="${CONFIG_PATH:-/var/www/html/config/airports.json}"
VALIDATE_UPLOAD_HOST="${VALIDATE_UPLOAD_HOST:-127.0.0.1}"
PASV_PROBE_PASSWORD_ENV="$(normalize_pasv_probe_password_env "${PASV_PROBE_PASSWORD_ENV:-AVIATIONWX_FTP_PROBE_PASSWORD}")"

probe_skips_tls_verify() {
    local probe_host="$1"
    case "$probe_host" in
        localhost|127.0.0.1|::1)
            return 0
            ;;
    esac
    if [[ "$probe_host" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
        return 0
    fi
    if [[ "$probe_host" == *:* ]]; then
        return 0
    fi
    return 1
}

if [ -x /usr/local/bin/php ]; then
    APP_PHP=/usr/local/bin/php
else
    APP_PHP="${APP_PHP:-php}"
fi

PROFTPD_RUNTIME_CONF="${PROFTPD_RUNTIME_CONF:-/etc/proftpd/conf.d/runtime.conf}"

read_proftpd_runtime_expectations() {
    CONFIG_PATH="$CONFIG_PATH" "$APP_PHP" -r '
        require_once "/var/www/html/lib/config.php";
        $config = loadConfig();
        $np = $config["config"]["network_ports"] ?? [];
        $daemon = $config["config"]["upload_daemon"] ?? [];
        echo json_encode([
            "port" => (int) ($np["ftp_control"] ?? 2121),
            "passive_min" => (int) ($np["ftp_passive_min"] ?? 50000),
            "passive_max" => (int) ($np["ftp_passive_max"] ?? 51000),
            "max_instances" => (int) ($daemon["max_instances"] ?? 50),
            "max_clients" => (int) ($daemon["max_clients"] ?? 40),
            "max_clients_per_user" => (int) ($daemon["max_clients_per_user"] ?? 2),
        ], JSON_UNESCAPED_SLASHES);
    ' 2>/dev/null
}

read_proftpd_runtime_directive() {
    local key="$1"
    awk -v k="$key" '$1 == k { $1=""; sub(/^ +/, ""); print; exit }' "$PROFTPD_RUNTIME_CONF"
}

assert_runtime_conf_matches_config() {
    if [ ! -f "$PROFTPD_RUNTIME_CONF" ]; then
        fail "missing ${PROFTPD_RUNTIME_CONF}"
    fi
    if [ ! -r "$PROFTPD_RUNTIME_CONF" ]; then
        fail "cannot read ${PROFTPD_RUNTIME_CONF}"
    fi

    local expected
    expected="$(read_proftpd_runtime_expectations)"
    if [ -z "$expected" ]; then
        fail "could not read ProFTPD runtime expectations from config"
    fi

    local exp_port exp_pasv_min exp_pasv_max exp_max_inst exp_max_clients exp_max_per_user
    exp_port="$(echo "$expected" | python3 -c 'import json,sys; print(json.load(sys.stdin)["port"])')"
    exp_pasv_min="$(echo "$expected" | python3 -c 'import json,sys; print(json.load(sys.stdin)["passive_min"])')"
    exp_pasv_max="$(echo "$expected" | python3 -c 'import json,sys; print(json.load(sys.stdin)["passive_max"])')"
    exp_max_inst="$(echo "$expected" | python3 -c 'import json,sys; print(json.load(sys.stdin)["max_instances"])')"
    exp_max_clients="$(echo "$expected" | python3 -c 'import json,sys; print(json.load(sys.stdin)["max_clients"])')"
    exp_max_per_user="$(echo "$expected" | python3 -c 'import json,sys; print(json.load(sys.stdin)["max_clients_per_user"])')"

    local actual_port actual_pasv actual_pasv_min actual_pasv_max
    local actual_max_inst actual_max_clients actual_max_per_user
    actual_port="$(read_proftpd_runtime_directive Port | awk '{print $1}')"
    actual_pasv="$(read_proftpd_runtime_directive PassivePorts)"
    actual_pasv_min="$(echo "$actual_pasv" | awk '{print $1}')"
    actual_pasv_max="$(echo "$actual_pasv" | awk '{print $2}')"
    actual_max_inst="$(read_proftpd_runtime_directive MaxInstances | awk '{print $1}')"
    actual_max_clients="$(read_proftpd_runtime_directive MaxClients | awk '{print $1}')"
    actual_max_per_user="$(read_proftpd_runtime_directive MaxClientsPerUser | awk '{print $1}')"

    if [ "$actual_port" != "$exp_port" ]; then
        fail "runtime Port ${actual_port} (expected ${exp_port})"
    fi
    if [ "$actual_pasv_min" != "$exp_pasv_min" ] || [ "$actual_pasv_max" != "$exp_pasv_max" ]; then
        fail "runtime PassivePorts ${actual_pasv_min}-${actual_pasv_max} (expected ${exp_pasv_min}-${exp_pasv_max})"
    fi
    if [ "$actual_max_inst" != "$exp_max_inst" ]; then
        fail "runtime MaxInstances ${actual_max_inst} (expected ${exp_max_inst})"
    fi
    if [ "$actual_max_clients" != "$exp_max_clients" ]; then
        fail "runtime MaxClients ${actual_max_clients} (expected ${exp_max_clients})"
    fi
    if [ "$actual_max_per_user" != "$exp_max_per_user" ]; then
        fail "runtime MaxClientsPerUser ${actual_max_per_user} (expected ${exp_max_per_user})"
    fi

    echo "  runtime: ok Port=${actual_port} PassivePorts=${actual_pasv_min}-${actual_pasv_max} MaxInstances=${actual_max_inst} MaxClients=${actual_max_clients} MaxClientsPerUser=${actual_max_per_user}"
}

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
            $config = loadConfig();
            foreach (array_keys($parsed["users"]) as $candidate) {
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

assert_runtime_conf_matches_config

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
if probe_skips_tls_verify "$host"; then
    export AVWX_FTPS_SKIP_HOSTNAME_VERIFY=1
fi
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
stor_file="$(mktemp /tmp/aviationwx-validate-upload.XXXXXX)"
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
    unset AVWX_FTPS_SKIP_HOSTNAME_VERIFY 2>/dev/null || true
    rm -f "$stor_file"
    fail "STOR upload failed"
fi
unset "${PASV_PROBE_PASSWORD_ENV}"
unset AVWX_FTPS_SKIP_HOSTNAME_VERIFY 2>/dev/null || true
rm -f "$stor_file"
echo "  stor: ok remote=${stor_remote}"

# Session containment: user A must not reach another camera inbox (SFTP-style jail).
isolation_pair="$(CONFIG_PATH="$CONFIG_PATH" "$APP_PHP" -r '
    require_once "/var/www/html/lib/config.php";
    require_once "/var/www/html/lib/proftpd-auth.php";
    $parsed = parseProftpdPasswdFile();
    $cameras = [];
    foreach (loadConfig()["airports"] ?? [] as $airport) {
        foreach ($airport["webcams"] ?? [] as $cam) {
            $push = $cam["push_config"] ?? null;
            if (!is_array($push)) {
                continue;
            }
            $candidate = $push["username"] ?? "";
            $candidatePass = $push["password"] ?? "";
            if ($candidate === "" || $candidatePass === "") {
                continue;
            }
            $home = $parsed["users"][$candidate]["home"] ?? "";
            if ($home === "") {
                continue;
            }
            $cameras[] = ["user" => $candidate, "home" => $home];
        }
    }
    if (count($cameras) < 2) {
        echo "";
        exit(0);
    }
    echo json_encode([
        "user_b" => $cameras[1]["user"],
        "home_b" => $cameras[1]["home"],
    ], JSON_UNESCAPED_SLASHES);
' 2>/dev/null || echo "")"
if [ -n "$isolation_pair" ] && [ -f "$ISOLATION_PROBE" ]; then
  user_b="$(echo "$isolation_pair" | python3 -c 'import json,sys; print(json.load(sys.stdin)["user_b"])')"
  home_b="$(echo "$isolation_pair" | python3 -c 'import json,sys; print(json.load(sys.stdin)["home_b"])')"
  export "${PASV_PROBE_PASSWORD_ENV}=${pass}"
  if ! isolation_out="$(python3 "$ISOLATION_PROBE" --host "$host" --port "$port" \
      --user-a "$user" --user-b "$user_b" --home-b "$home_b" \
      --password-env "$PASV_PROBE_PASSWORD_ENV" --json 2>&1)"; then
    unset "${PASV_PROBE_PASSWORD_ENV}"
    fail "FTP session isolation failed for ${user} vs ${user_b}: ${isolation_out}"
  fi
  unset "${PASV_PROBE_PASSWORD_ENV}"
  echo "  isolation: ok user=${user} cannot reach ${user_b}"
elif [ ! -f "$ISOLATION_PROBE" ]; then
  echo "  isolation: skipped (ftp-isolation-probe.py not found)"
else
  echo "  isolation: skipped (need two push cameras in config)"
fi

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
