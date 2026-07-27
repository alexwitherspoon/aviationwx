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

if [ -x /usr/local/bin/php ]; then
    APP_PHP=/usr/local/bin/php
else
    APP_PHP="${APP_PHP:-php}"
fi

read_probe_settings() {
    CONFIG_PATH="$CONFIG_PATH" VALIDATE_UPLOAD_HOST="$VALIDATE_UPLOAD_HOST" "$APP_PHP" -r '
        require_once "/var/www/html/lib/config.php";
        require_once "/var/www/html/lib/proftpd-auth.php";

        $settings = getUploadHealthProbeSettings();
        $user = $settings["ftps"]["username"] ?? "";
        $pass = $settings["ftps"]["password"] ?? "";
        $credentialSource = "probe";

        if ($user === "" || $pass === "") {
            $parsed = parseProftpdPasswdFile();
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
            "credential_source" => $credentialSource,
            "tls_enabled" => is_readable("/etc/proftpd/conf.d/tls.conf")
                && str_contains((string) file_get_contents("/etc/proftpd/conf.d/tls.conf"), "TLSEngine                      on"),
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
credential_source="$(echo "$settings" | python3 -c 'import json,sys; print(json.load(sys.stdin)["credential_source"])')"
tls_enabled="$(echo "$settings" | python3 -c 'import json,sys; print("true" if json.load(sys.stdin)["tls_enabled"] else "false")')"

if [ -z "$user" ] || [ -z "$pass" ]; then
    fail "no FTP credentials available (run sync-push-config.php first)"
fi

modes=(pasv-plain epsv-plain)
if [ "$tls_enabled" = "true" ]; then
    modes+=(pasv-ftps epsv-ftps)
fi

echo "validate-upload-daemon: host=${host} port=${port} credential_source=${credential_source} modes=${modes[*]}"
for mode in "${modes[@]}"; do
    result="$(python3 "$PASV_PROBE" --host "$host" --port "$port" --user "$user" --password "$pass" --mode "$mode" 2>&1)" || {
        rc=$?
        if [ "$rc" -eq 2 ]; then
            fail "bad PASV (0,0,0,0) in mode ${mode}"
        fi
        fail "probe failed for mode ${mode}"
    }
    pasv_ip="$(echo "$result" | python3 -c 'import json,sys; j=json.load(sys.stdin); print(j.get("pasv_ip") or j.get("response") or "")')"
    echo "  ${mode}: ok pasv_ip=${pasv_ip}"
done

# STOR: plain FTP upload (same path upload-probe uses for health checks).
stor_file="/tmp/aviationwx-validate-upload-$$.txt"
printf 'aviationwx validate upload %s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" >"$stor_file"
stor_remote="aviationwx-validate-upload.txt"
if ! curl -sS --ftp-pasv --connect-timeout 10 --max-time 45 \
    --user "${user}:${pass}" \
    --upload-file "$stor_file" \
    "ftp://${host}:${port}/${stor_remote}" >/dev/null 2>&1; then
    rm -f "$stor_file"
    fail "STOR upload failed"
fi
rm -f "$stor_file"
echo "  stor: ok remote=${stor_remote}"

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
