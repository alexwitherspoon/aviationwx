#!/bin/bash
set -e

# Ensure config directory exists (needed for other config files)
CONFIG_DIR="/var/www/html/config"
if [ ! -d "${CONFIG_DIR}" ]; then
    echo "Creating config directory: ${CONFIG_DIR}"
    mkdir -p "${CONFIG_DIR}"
    chown www-data:www-data "${CONFIG_DIR}" 2>/dev/null || true
fi

# Ensure secrets directory exists (needed for airports.json mount via CONFIG_PATH)
SECRETS_DIR="/var/www/html/secrets"
if [ ! -d "${SECRETS_DIR}" ]; then
    echo "Creating secrets directory: ${SECRETS_DIR}"
    mkdir -p "${SECRETS_DIR}"
    chown www-data:www-data "${SECRETS_DIR}" 2>/dev/null || true
fi

# For backward compatibility, check if airports.json exists in config directory
# (fallback for environments that don't use CONFIG_PATH)
# Prefer CONFIG_PATH when set (production docker-compose sets CONFIG_PATH=/var/www/html/config/airports.json)
CONFIG_FILE="${CONFIG_DIR}/airports.json"
if [ -n "${CONFIG_PATH:-}" ]; then
    CONFIG_FILE="${CONFIG_PATH}"
fi

# Handle airports.json fallback for local development
# Production: airports.json is mounted from /home/aviationwx/airports.json (must exist)
# Local: If airports.json doesn't exist, create it from fallback sources (example > test fixture)
if [ ! -f "${CONFIG_FILE}" ]; then
    echo "airports.json not found in config directory, checking for fallback sources..."
    
    # Try to copy from example file (for local development)
    EXAMPLE_FILE="/var/www/html/config/airports.json.example"
    TEST_FILE="/var/www/html/tests/Fixtures/airports.json.test"
    
    if [ -f "${EXAMPLE_FILE}" ]; then
        echo "Copying airports.json from example file..."
        cp "${EXAMPLE_FILE}" "${CONFIG_FILE}" 2>/dev/null || {
            echo "⚠️  Warning: Could not write to ${CONFIG_FILE} (read-only mount?)"
            echo "  Falling back to test fixture..."
        }
    fi
    
    # If still doesn't exist (either example wasn't found or copy failed), try test fixture
    if [ ! -f "${CONFIG_FILE}" ] && [ -f "${TEST_FILE}" ]; then
        echo "Copying airports.json from test fixture..."
        cp "${TEST_FILE}" "${CONFIG_FILE}" 2>/dev/null || {
            echo "⚠️  Warning: Could not write to ${CONFIG_FILE} (read-only mount?)"
        }
    fi
    
    # Verify file was created
    if [ -f "${CONFIG_FILE}" ]; then
        chmod 640 "${CONFIG_FILE}" 2>/dev/null || true
        chown root:www-data "${CONFIG_FILE}" 2>/dev/null || true
        echo "✓ Created airports.json from fallback source"
    else
        echo "⚠️  Warning: airports.json not found and could not create from fallback sources"
        echo "  Expected locations:"
        echo "    - ${CONFIG_FILE} (mounted or should be created)"
        echo "    - ${EXAMPLE_FILE} (fallback)"
        echo "    - ${TEST_FILE} (fallback)"
        echo "  Container will continue, but application may fail without valid config"
    fi
fi

# Secure airports.json permissions (prevents SFTP users from reading sensitive config)
# airports.json contains API keys, passwords, and other secrets
# Permissions: 640 (root read/write, www-data read, others none)
echo "Securing config file permissions..."
# Include resolved CONFIG_FILE first (CONFIG_PATH may point outside default paths)
SECURE_CONFIG_FILES=(
    "${CONFIG_FILE}"
    "/var/www/html/config/airports.json"
    "/var/www/html/secrets/airports.json"
    "/home/aviationwx/airports.json"
)
for config_file in "${SECURE_CONFIG_FILES[@]}"; do
    if [ -f "$config_file" ]; then
        chmod 640 "$config_file" 2>/dev/null || true
        chown root:www-data "$config_file" 2>/dev/null || true
    fi
done
echo "✓ Config files secured (640 root:www-data)"

# TCP port map: optional config.network_ports (defaults match deploy-configure-firewall.sh).
# Applies to ProFTPD, sshd (SFTP), and fail2ban inside this container.
HOST_HTTP_PORT=80
HOST_HTTPS_PORT=443
FTP_CONTROL_PORT=2121
FTPS_EXPLICIT_TLS_PORT=2122
SFTP_PORT=2222
FTP_PASSIVE_MIN=50000
FTP_PASSIVE_MAX=51000
SSH_PORT=22

validate_tcp_port() {
    local p="$1"
    local name="$2"
    if ! [[ "$p" =~ ^[0-9]+$ ]] || [ "$p" -lt 1 ] || [ "$p" -gt 65535 ]; then
        echo "⚠️  Warning: invalid $name ($p), must be 1-65535 - using default"
        return 1
    fi
    return 0
}

load_network_ports_from_config() {
    local config="$1"
    if [ ! -f "$config" ]; then
        echo "✓ Network ports: using defaults (config file missing: $config)"
        return 0
    fi
    if ! command -v jq >/dev/null 2>&1; then
        echo "⚠️  Warning: jq missing; network ports use stock Docker defaults"
        return 0
    fi
    if jq -e '(.config | has("host_firewall"))' "$config" >/dev/null 2>&1; then
        echo "❌ config.host_firewall is not a valid key; TCP ports are configured in config.network_ports" >&2
        exit 1
    fi
    if ! jq -e '(.config.network_ports != null)' "$config" >/dev/null 2>&1; then
        echo "✓ Network ports: not set - using defaults"
        return 0
    fi
    if ! jq -e '(.config.network_ports | type == "object")' "$config" >/dev/null 2>&1; then
        echo "❌ config.network_ports must be a JSON object" >&2
        exit 1
    fi
    if ! jq -e '(.config.network_ports | to_entries | map(select(.value != null and (.value | type != "number"))) | length == 0)' "$config" >/dev/null 2>&1; then
        echo "❌ config.network_ports must use JSON numbers for port fields (not strings)" >&2
        exit 1
    fi
    local merged='(.config.network_ports // {})'
    local http https fc ft sftp pmin pmax sshp
    http=$(jq -r "${merged} | .http // 80" "$config")
    https=$(jq -r "${merged} | .https // 443" "$config")
    fc=$(jq -r "${merged} | .ftp_control // 2121" "$config")
    ft=$(jq -r "${merged} | .ftps_explicit_tls // 2122" "$config")
    sftp=$(jq -r "${merged} | .sftp // 2222" "$config")
    pmin=$(jq -r "${merged} | .ftp_passive_min // 50000" "$config")
    pmax=$(jq -r "${merged} | .ftp_passive_max // 51000" "$config")
    sshp=$(jq -r "${merged} | .ssh // 22" "$config")

    if validate_tcp_port "$http" "network_ports.http"; then HOST_HTTP_PORT="$http"; fi
    if validate_tcp_port "$https" "network_ports.https"; then HOST_HTTPS_PORT="$https"; fi
    if validate_tcp_port "$fc" "network_ports.ftp_control"; then FTP_CONTROL_PORT="$fc"; fi
    if validate_tcp_port "$ft" "network_ports.ftps_explicit_tls"; then FTPS_EXPLICIT_TLS_PORT="$ft"; fi
    if validate_tcp_port "$sftp" "network_ports.sftp"; then SFTP_PORT="$sftp"; fi
    if validate_tcp_port "$pmin" "network_ports.ftp_passive_min"; then FTP_PASSIVE_MIN="$pmin"; fi
    if validate_tcp_port "$pmax" "network_ports.ftp_passive_max"; then FTP_PASSIVE_MAX="$pmax"; fi
    if validate_tcp_port "$sshp" "network_ports.ssh"; then SSH_PORT="$sshp"; fi

    if [ "$FTP_PASSIVE_MIN" -ge "$FTP_PASSIVE_MAX" ]; then
        echo "⚠️  Warning: network_ports passive range invalid (min >= max) - using 50000-51000"
        FTP_PASSIVE_MIN=50000
        FTP_PASSIVE_MAX=51000
    fi

    echo "✓ Network ports from config: http=${HOST_HTTP_PORT} https=${HOST_HTTPS_PORT} ftp_control=${FTP_CONTROL_PORT} ftps_explicit_tls=${FTPS_EXPLICIT_TLS_PORT} sftp=${SFTP_PORT} passive=${FTP_PASSIVE_MIN}-${FTP_PASSIVE_MAX} ssh=${SSH_PORT}"
    if [ "$FTP_CONTROL_PORT" -eq "$FTPS_EXPLICIT_TLS_PORT" ]; then
        echo "  (ftp_control equals ftps_explicit_tls: one ProFTPD control listener; fail2ban uses that port set)"
    else
        echo "  ⚠️  ftps_explicit_tls differs from ftp_control: ProFTPD listens on ftp_control only."
        echo "     UFW/fail2ban still track both values from network_ports; use NAT (e.g. ftps_alt) if clients need another inbound control port."
    fi
}

load_network_ports_from_config "$CONFIG_FILE"

# Start cron daemon in background
echo "Starting cron daemon..."
cron

# Reload cron configuration to ensure it picks up crontab files
# This is especially important on macOS Docker where cron may not auto-reload
sleep 2
if pgrep -x cron > /dev/null; then
    # Send SIGHUP to reload cron configuration
    pkill -HUP cron 2>/dev/null || true
    echo "✓ Cron daemon started and configuration reloaded"
else
    echo "⚠️  Warning: Cron daemon may not have started properly"
fi

# Initialize cache directory with correct permissions
# This is critical after reboots when /tmp is cleared and the mount point
# may be created with wrong ownership/permissions
echo "Initializing cache directory..."
# Subdirectories must match lib/cache-paths.php ensureAllCacheDirs() (except /var/sftp: chroot parent is set in libexec set-cache-permissions with cache/FTP).
CACHE_DIR="/var/www/html/cache"
WEBCAM_CACHE_DIR="${CACHE_DIR}/webcams"
WEATHER_CACHE_DIR="${CACHE_DIR}/weather"
FTP_CACHE_DIR="${CACHE_DIR}/ftp"
METRICS_DIR="${CACHE_DIR}/metrics"
METRICS_HOURLY_DIR="${METRICS_DIR}/hourly"
METRICS_DAILY_DIR="${METRICS_DIR}/daily"
METRICS_WEEKLY_DIR="${METRICS_DIR}/weekly"
METRICS_SPILL_DIR="${METRICS_DIR}/spill"
PEAK_GUSTS_DIR="${CACHE_DIR}/peak_gusts"
TEMP_EXTREMES_DIR="${CACHE_DIR}/temp_extremes"
RUNWAYS_DIR="${CACHE_DIR}/runways"
OURAIRPORTS_DIR="${CACHE_DIR}/ourairports"
NASR_DIR="${CACHE_DIR}/nasr"
NOTAM_DIR="${CACHE_DIR}/notam"
STATION_POWER_DIR="${CACHE_DIR}/station-power"
PARTNERS_DIR="${CACHE_DIR}/partners"
PARTNERS_LUM_DIR="${PARTNERS_DIR}/lum"
RATE_LIMITS_DIR="${CACHE_DIR}/rate_limits"
MAP_TILES_DIR="${CACHE_DIR}/map_tiles"

# runuser (util-linux) is required: cache dirs and the scheduler must be created/run as
# www-data, never as root, so webcam cache permissions stay consistent with Apache.
if ! command -v runuser >/dev/null 2>&1; then
    echo "ERROR: runuser is required in this image (package util-linux) but was not found." >&2
    exit 1
fi

# Create cache dirs as www-data first. Production bind-mounts cache from the host
# (often owned by www-data). Under rootless Docker, container "root" maps to an
# unprivileged host UID and cannot mkdir inside that tree; www-data can.
# CI (docker-compose.yml) may bind-mount a host dir root-owned; container root can chown the mount
# root so www-data can mkdir. If that still fails, fall back to root mkdir once, then
# libexec set-cache-permissions fixes ownership.
ensure_cache_subdirs() {
    local dirs=(
        "${CACHE_DIR}"
        "${PEAK_GUSTS_DIR}"
        "${TEMP_EXTREMES_DIR}"
        "${RUNWAYS_DIR}"
        "${OURAIRPORTS_DIR}"
        "${NASR_DIR}"
        "${WEATHER_CACHE_DIR}"
        "${WEATHER_CACHE_DIR}/history"
        "${WEBCAM_CACHE_DIR}"
        "${FTP_CACHE_DIR}"
        "${NOTAM_DIR}"
        "${STATION_POWER_DIR}"
        "${PARTNERS_DIR}"
        "${PARTNERS_LUM_DIR}"
        "${RATE_LIMITS_DIR}"
        "${METRICS_DIR}"
        "${METRICS_HOURLY_DIR}"
        "${METRICS_DAILY_DIR}"
        "${METRICS_WEEKLY_DIR}"
        "${METRICS_SPILL_DIR}"
        "${MAP_TILES_DIR}"
    )
    if [ -d "${CACHE_DIR}" ] && ! runuser -u www-data -- test -w "${CACHE_DIR}" 2>/dev/null; then
        chown www-data:www-data "${CACHE_DIR}" 2>/dev/null || true
        chmod 755 "${CACHE_DIR}" 2>/dev/null || true
    fi
    if runuser -u www-data -- mkdir -p "${dirs[@]}"; then
        return 0
    fi
    echo "Warning: www-data could not mkdir cache subdirs (bind mount ownership?). Using root once; set-cache-permissions.sh will align ownership." >&2
    mkdir -p "${dirs[@]}"
}

echo "Ensuring cache subdirectories exist..."
ensure_cache_subdirs

# Cache/webcams/FTP ownership plus SFTP chroot repair (set-cache-permissions.sh; same as 01:00 root cron).
if [ ! -x /usr/local/libexec/aviationwx/set-cache-permissions.sh ]; then
    echo "ERROR: /usr/local/libexec/aviationwx/set-cache-permissions.sh missing or not executable." >&2
    exit 1
fi
if ! /usr/local/libexec/aviationwx/set-cache-permissions.sh; then
    echo "ERROR: set-cache-permissions.sh failed (SFTP chroot repair or cache ownership)." >&2
    exit 1
fi

if [ -d "${CACHE_DIR}" ]; then
    echo "✓ Cache directory initialized"
    
    # Clear circuit breaker state on container startup
    # This ensures fresh circuit breaker state after code deployments
    # that may change circuit breaker logic
    echo "Clearing circuit breaker state..."
    if [ -f /var/www/html/scripts/deploy-clear-circuit-breakers.php ]; then
        if php /var/www/html/scripts/deploy-clear-circuit-breakers.php 2>&1; then
            echo "✓ Circuit breakers cleared successfully"
        else
            echo "⚠️  Warning: Circuit breaker clearing script returned non-zero exit code"
            echo "   Continuing startup anyway..."
        fi
    else
        # Fallback: manually clear the main backoff.json file
        if [ -f "${CACHE_DIR}/backoff.json" ]; then
            rm -f "${CACHE_DIR}/backoff.json" && echo "✓ Cleared circuit breaker state (fallback)"
        fi
    fi
else
    echo "⚠️  Warning: Cache directory does not exist and could not be created"
fi

# Stale drain markers on the shared cache volume would leave a new scheduler paused.
# Basenames must match DEPLOY_DRAIN_*_BASENAME in lib/constants.php.
if [ -d "${CACHE_DIR}" ]; then
    rm -f "${CACHE_DIR}/deploy-drain.flag" "${CACHE_DIR}/deploy-drain.done" 2>/dev/null || true
fi

# Persist deploy SHA for CLI/cron: health-check restarts inherit crontab env, not compose GIT_SHA.
# Refresh every start; remove when unset so a shared cache mount cannot keep a prior container SHA.
DEPLOY_GIT_SHA_FILE="${CACHE_DIR}/.deploy-git-sha"
if [ -d "${CACHE_DIR}" ]; then
    if [ -n "${GIT_SHA:-}" ]; then
        if printf '%s\n' "${GIT_SHA}" > "${DEPLOY_GIT_SHA_FILE}"; then
            chmod 644 "${DEPLOY_GIT_SHA_FILE}" 2>/dev/null || true
            echo "✓ Persisted deploy GIT_SHA to ${DEPLOY_GIT_SHA_FILE}"
            if runuser -u www-data -- /usr/local/bin/php /var/www/html/scripts/repair-notam-map-build-token.php; then
                :
            else
                echo "⚠️  NOTAM map build-token repair skipped or failed" >&2
            fi
        else
            echo "⚠️  Failed to persist deploy GIT_SHA to ${DEPLOY_GIT_SHA_FILE}" >&2
        fi
    else
        rm -f "${DEPLOY_GIT_SHA_FILE}" 2>/dev/null || true
        echo "⚠️  GIT_SHA unset - removed ${DEPLOY_GIT_SHA_FILE} if present"
    fi
fi

# Scheduler: initial start authority is this entrypoint (one daemon after cache is ready).
# Cron runs scripts/scheduler-health-check.php every minute as a watchdog only (confirm lock/PID,
# start a replacement when missing or unhealthy). It must not duplicate a healthy daemon.
# Scheduler runs as www-data so cache and worker subprocesses match Apache (see ProcessPool).
# Must start after cache permissions (including webcams setgid) are applied.
# Use POSIX sh (not bash -c): non-interactive sh exits right after backgrounding; avoids a lingering bash parent.
echo "Starting scheduler daemon..."
runuser -u www-data -- /bin/sh -c 'cd /var/www/html && nohup /usr/local/bin/php /var/www/html/scripts/scheduler.php >/dev/null 2>&1 &'
echo "✓ Scheduler started as www-data"

# Initialize log directory with correct permissions
# This directory stores file-based logs for cron jobs and Apache
echo "Initializing log directory..."
LOG_DIR="/var/log/aviationwx"

# Create log directory if it doesn't exist
if [ ! -d "${LOG_DIR}" ]; then
    echo "Creating log directory: ${LOG_DIR}"
    mkdir -p "${LOG_DIR}"
fi

# Set ownership to www-data:www-data for most logs
# Some cron jobs run as root, but we'll allow both to write
if [ -d "${LOG_DIR}" ]; then
    # Try to change ownership - may fail if not running as root, but that's OK
    chown -R www-data:www-data "${LOG_DIR}" 2>/dev/null || {
        echo "Warning: Could not change ownership of log directory (may already be correct)"
    }
    
    # Set permissions: 755 for directory, allow group write for cron (root) and www-data
    chmod 755 "${LOG_DIR}" 2>/dev/null || true
    
    # Create initial log files with proper permissions (align with config/crontab + Apache)
    touch "${LOG_DIR}/cron-heartbeat.log" \
          "${LOG_DIR}/scheduler-health-check.log" \
          "${LOG_DIR}/memory-sampler.log" \
          "${LOG_DIR}/cleanup-push-upload-debris.log" \
          "${LOG_DIR}/cleanup-cache.log" \
          "${LOG_DIR}/apache-access.log" \
          "${LOG_DIR}/apache-error.log" \
          "${LOG_DIR}/sshd.log" \
          "${LOG_DIR}/service-watchdog.log" \
          "${LOG_DIR}/upload-probe.log" \
          "${LOG_DIR}/app.log" \
          "${LOG_DIR}/user.log" 2>/dev/null || true
    
    # Set ownership: www-data for most logs, root for system logs
    # Use 775 permissions on directory to allow both www-data and root to write
    chmod 775 "${LOG_DIR}" 2>/dev/null || true
    chown www-data:www-data "${LOG_DIR}"/*.log 2>/dev/null || true
    chmod 644 "${LOG_DIR}"/*.log 2>/dev/null || true
    # System logs owned by root (nightly set-cache-permissions log lives under /var/lib/aviationwx; see config/crontab)
    chown root:root "${LOG_DIR}/sshd.log" "${LOG_DIR}/service-watchdog.log" "${LOG_DIR}/upload-probe.log" 2>/dev/null || true
    chmod 644 "${LOG_DIR}/sshd.log" "${LOG_DIR}/service-watchdog.log" "${LOG_DIR}/upload-probe.log" 2>/dev/null || true
    # Ensure heartbeat log is writable by both www-data and root
    chmod 666 "${LOG_DIR}/cron-heartbeat.log" 2>/dev/null || true
    
    echo "✓ Log directory initialized"
else
    echo "⚠️  Warning: Log directory does not exist and could not be created"
fi

# FTP/SFTP parent dirs: libexec set-cache-permissions (called with cache init above)

# Configure and start ProFTPD (dual-stack; MasqueradeAddress + TLS in conf.d)
PROFTPD_PID=""
if [ -f /usr/local/bin/configure-proftpd.sh ]; then
    # shellcheck source=/dev/null
    source /usr/local/bin/configure-proftpd.sh
    CONFIG_FILE="$CONFIG_FILE" configure_and_start_proftpd || true
elif [ -f /var/www/html/scripts/configure-proftpd.sh ]; then
    # shellcheck source=/dev/null
    source /var/www/html/scripts/configure-proftpd.sh
    CONFIG_FILE="$CONFIG_FILE" configure_and_start_proftpd || true
else
    echo "⚠️  Warning: configure-proftpd.sh not found - FTP service will not be available"
fi

sleep 1

# Configure and start rsyslog for sshd dedicated logging
if command -v rsyslogd >/dev/null 2>&1; then
    # Ensure rsyslog config directory exists
    mkdir -p /etc/rsyslog.d
    # Create rsyslog config for sshd if it doesn't exist
    if [ ! -f /etc/rsyslog.d/20-sshd.conf ]; then
        cat > /etc/rsyslog.d/20-sshd.conf << 'EOF'
# rsyslog configuration for sshd
# Routes sshd logs (LOCAL0 facility) to dedicated log file
if $programname == 'sshd' or $syslogfacility-text == 'local0' then /var/log/aviationwx/sshd.log
& stop
EOF
    fi
    # Ensure log directory exists for sshd logs (should already exist from earlier init)
    mkdir -p /var/log/aviationwx
    touch /var/log/aviationwx/sshd.log
    chown root:root /var/log/aviationwx/sshd.log
    chmod 644 /var/log/aviationwx/sshd.log
    # Start rsyslog in background (non-blocking)
    echo "Starting rsyslog for sshd logging..."
    rsyslogd -n &
    sleep 1
    if pgrep -x rsyslogd > /dev/null; then
        echo "✓ rsyslog started for sshd logging"
    else
        echo "⚠️  Warning: rsyslog failed to start, sshd will log to auth.log"
    fi
fi

# Set sshd Port to network_ports.sftp (container SFTP listener)
if [ -f /etc/ssh/sshd_config ]; then
    if grep -qE '^Port[[:space:]]' /etc/ssh/sshd_config; then
        sed -i "s/^Port .*/Port ${SFTP_PORT}/" /etc/ssh/sshd_config
    elif grep -qE '^#Port[[:space:]]' /etc/ssh/sshd_config; then
        sed -i "s/^#Port .*/Port ${SFTP_PORT}/" /etc/ssh/sshd_config
    else
        sed -i "/^Match /i Port ${SFTP_PORT}" /etc/ssh/sshd_config
    fi
    echo "✓ sshd Port=${SFTP_PORT}"
else
    echo "⚠️  Warning: /etc/ssh/sshd_config not found - SFTP port not applied"
fi

# Start sshd when SFTP uploads are enabled (upload_capabilities.sftp)
if [ -x /usr/local/bin/php ]; then
    APP_PHP=/usr/local/bin/php
else
    APP_PHP=php
fi
SFTP_UPLOAD_ENABLED="$("$APP_PHP" -r 'require_once "/var/www/html/lib/config.php"; echo getUploadCapabilities()["sftp"] ? "1" : "0";' 2>/dev/null || echo 1)"
UPLOAD_IPV4_ENABLED="$("$APP_PHP" -r 'require_once "/var/www/html/lib/config.php"; echo getUploadCapabilities()["ipv4"] ? "1" : "0";' 2>/dev/null || echo 1)"
UPLOAD_IPV6_ENABLED="$("$APP_PHP" -r 'require_once "/var/www/html/lib/config.php"; echo getUploadCapabilities()["ipv6"] ? "1" : "0";' 2>/dev/null || echo 1)"

if [ -f /etc/ssh/sshd_config ] && { [ "$UPLOAD_IPV4_ENABLED" = "1" ] || [ "$UPLOAD_IPV6_ENABLED" = "1" ]; }; then
    sed -i '/^ListenAddress[[:space:]]/d' /etc/ssh/sshd_config
    # Insert before Match (Match extends to EOF; appending ListenAddress breaks sshd).
    if [ "$UPLOAD_IPV6_ENABLED" = "1" ]; then
        sed -i "/^Match /i ListenAddress ::" /etc/ssh/sshd_config
    fi
    if [ "$UPLOAD_IPV4_ENABLED" = "1" ]; then
        sed -i "/^Match /i ListenAddress 0.0.0.0" /etc/ssh/sshd_config
    fi
fi

if [ "$SFTP_UPLOAD_ENABLED" = "1" ]; then
    echo "Starting sshd..."
    service ssh start || {
        echo "Error: sshd failed to start"
        exit 1
    }
else
    echo "SFTP uploads disabled via upload_capabilities.sftp"
fi

# Verify ProFTPD is running (non-fatal - web service is more critical)
if [ -n "${PROFTPD_PID:-}" ]; then
    if ! kill -0 "$PROFTPD_PID" 2>/dev/null; then
        echo "⚠️  Warning: ProFTPD is not running (non-fatal)"
        PROFTPD_PID=""
    fi
fi

if [ "$SFTP_UPLOAD_ENABLED" = "1" ]; then
    if ! pgrep -x sshd > /dev/null; then
        echo "Error: sshd is not running"
        exit 1
    fi
fi

# Verify ports are listening (give services a moment to bind)
sleep 2
if ! netstat -tuln 2>/dev/null | grep -qE ":${FTP_CONTROL_PORT}|:${SFTP_PORT}"; then
    echo "Warning: FTP/SFTP ports may not be listening yet (expect ${FTP_CONTROL_PORT}, ${SFTP_PORT})"
fi

echo "All services started successfully"

# Sync FTP/SFTP/FTPS configuration before upload probes (probe accounts live in /etc).
# Runs in background so Apache startup is not blocked; upload-probe-runner waits for completion.
echo "Syncing FTP/SFTP/FTPS configuration (background)..."
: > /tmp/sync-push-config.log || echo "Warning: could not truncate /tmp/sync-push-config.log"
(cd /var/www/html && timeout 30 /usr/local/bin/php scripts/sync-push-config.php >> /tmp/sync-push-config.log 2>&1 && \
    echo "✓ FTP/SFTP/FTPS configuration synced successfully" >> /tmp/sync-push-config.log || \
    echo "⚠️  Warning: FTP/SFTP/FTPS configuration sync failed or timed out (check /tmp/sync-push-config.log)" >> /tmp/sync-push-config.log) &
SYNC_PID=$!

# Start upload probe runner (30s) and service watchdog (50s loop) in background
echo "Starting upload health probe runner..."
/usr/local/libexec/aviationwx/upload-probe-runner.sh &
PROBE_RUNNER_PID=$!

echo "Starting service watchdog..."
/usr/local/bin/service-watchdog.sh &
WATCHDOG_PID=$!

# Trap signals to clean up background monitors on exit
trap "kill $PROBE_RUNNER_PID $WATCHDOG_PID 2>/dev/null || true" EXIT

# Write fail2ban jail and sshd-sftp filter for the configured FTP/SFTP ports
echo "Configuring fail2ban ports..."
FTP_FAIL2BAN_PORTS="${FTP_CONTROL_PORT}"
if [ "$FTP_CONTROL_PORT" -ne "$FTPS_EXPLICIT_TLS_PORT" ]; then
    FTP_FAIL2BAN_PORTS="${FTP_CONTROL_PORT},${FTPS_EXPLICIT_TLS_PORT}"
fi
cat > /etc/fail2ban/jail.d/aviationwx.conf << EOF
# Fail2ban jail for AviationWX camera uploads (ports from config.network_ports at container start)

[DEFAULT]
bantime = 3600
findtime = 3600
maxretry = 10
backend = auto
destemail = root@localhost
sendername = Fail2Ban
action = %(action_)s

[proftpd]
enabled = true
port = ${FTP_FAIL2BAN_PORTS}
filter = proftpd
logpath = /var/log/proftpd.log
maxretry = 10
findtime = 3600
bantime = 3600
action = iptables-multiport[name=PROFTPD, port="${FTP_FAIL2BAN_PORTS}", protocol=tcp]

[sshd-sftp]
enabled = true
port = ${SFTP_PORT}
filter = sshd-sftp
logpath = /var/log/auth.log
maxretry = 10
findtime = 3600
bantime = 3600
action = iptables-multiport[name=SSHD-SFTP, port="${SFTP_PORT}", protocol=tcp]
EOF
cat > /etc/fail2ban/filter.d/sshd-sftp.conf << EOF
# Fail2ban filter for sshd SFTP (jail listens on config.network_ports.sftp = ${SFTP_PORT}).
# auth.log "port N" is the client ephemeral port, not the server listen port; match any digits.

[Definition]
failregex = ^.*Failed password for .* from <HOST> port [0-9]+.*$
            ^.*Invalid user .* from <HOST> port [0-9]+.*$
            ^.*Connection closed by authenticating user .* <HOST> port [0-9]+.*$
            ^.*Connection reset by authenticating user .* <HOST> port [0-9]+.*$

ignoreregex =
EOF
echo "✓ fail2ban: proftpd ports=${FTP_FAIL2BAN_PORTS} sshd-sftp port=${SFTP_PORT}"

# Start fail2ban
echo "Starting fail2ban..."
# Ensure log files exist
touch /var/log/proftpd.log /var/log/auth.log
chmod 644 /var/log/proftpd.log /var/log/auth.log

# Start fail2ban server in background
# Use systemd service if available, otherwise start directly
if command -v systemctl >/dev/null 2>&1 && systemctl is-system-running >/dev/null 2>&1; then
    systemctl start fail2ban || fail2ban-server -x &
else
    # Start fail2ban server directly in background
    fail2ban-server -x &
fi
FAIL2BAN_PID=$!

# Wait a moment for fail2ban to start
sleep 3

# Verify fail2ban is running
if pgrep -x fail2ban-server > /dev/null || pgrep -f "fail2ban-server" > /dev/null; then
    echo "✓ fail2ban started successfully"
    # Wait a bit more for jails to initialize
    sleep 2
    # Show active jails
    fail2ban-client status 2>/dev/null | grep -A 10 "Jail list" || echo "  (jails initializing...)"
else
    echo "⚠️  Warning: fail2ban may not have started properly"
    echo "This is non-fatal - services will continue without fail2ban protection"
fi

# Trap signals to clean up fail2ban on exit
trap "kill $WATCHDOG_PID $FAIL2BAN_PID 2>/dev/null || true" EXIT

# Configure Apache port and bind address based on environment
# Production (APP_ENV=production): Listen on 127.0.0.1:8080 (internal, behind nginx)
# Local/CI: Listen on 0.0.0.0:80 (bridge networking with port mapping)
if [ "${APP_ENV:-}" = "production" ]; then
    echo "Configuring Apache for production (127.0.0.1:8080)..."
    # Configure Apache to listen on 127.0.0.1:8080 (localhost only, not 0.0.0.0)
    # This ensures port 8080 is only accessible from localhost (nginx can proxy)
    # Try exact match first, then fallback to more specific pattern
    if grep -q "^Listen 80$" /etc/apache2/ports.conf 2>/dev/null; then
        sed -i 's/^Listen 80$/Listen 127.0.0.1:8080/' /etc/apache2/ports.conf
    elif grep -q "^Listen " /etc/apache2/ports.conf 2>/dev/null; then
        # Only replace the first Listen directive (default one)
        sed -i '0,/^Listen /s/^Listen .*/Listen 127.0.0.1:8080/' /etc/apache2/ports.conf
    else
        echo "Listen 127.0.0.1:8080" >> /etc/apache2/ports.conf
    fi
    
    # Configure VirtualHost - try exact match first
    if grep -q "<VirtualHost \*:80>" /etc/apache2/sites-available/000-default.conf 2>/dev/null; then
        sed -i 's/<VirtualHost \*:80>/<VirtualHost 127.0.0.1:8080>/' /etc/apache2/sites-available/000-default.conf
    elif grep -q "<VirtualHost \*:" /etc/apache2/sites-available/000-default.conf 2>/dev/null; then
        # Only replace the first VirtualHost directive (default one)
        sed -i '0,/<VirtualHost \*:/s/<VirtualHost \*:[0-9]*>/<VirtualHost 127.0.0.1:8080>/' /etc/apache2/sites-available/000-default.conf
    fi
    
    # Validate configuration
    if apache2ctl configtest > /dev/null 2>&1; then
        echo "✓ Apache configured for production (127.0.0.1:8080)"
    else
        echo "⚠️  Warning: Apache configuration test failed, but continuing..."
        apache2ctl configtest 2>&1 | head -5 || true
    fi
else
    echo "Configuring Apache for local/CI (0.0.0.0:80)..."
    # Ensure Apache listens on 0.0.0.0:80 for bridge networking
    # Try exact match first (from production config)
    if grep -q "^Listen 127\.0\.0\.1:8080$" /etc/apache2/ports.conf 2>/dev/null; then
        sed -i 's/^Listen 127\.0\.0\.1:8080$/Listen 80/' /etc/apache2/ports.conf
    elif grep -q "^Listen " /etc/apache2/ports.conf 2>/dev/null; then
        # Only replace the first Listen directive
        sed -i '0,/^Listen /s/^Listen .*/Listen 80/' /etc/apache2/ports.conf
    else
        echo "Listen 80" >> /etc/apache2/ports.conf
    fi
    
    # Configure VirtualHost - try exact match first (from production config)
    if grep -q "<VirtualHost 127\.0\.0\.1:8080>" /etc/apache2/sites-available/000-default.conf 2>/dev/null; then
        sed -i 's/<VirtualHost 127\.0\.0\.1:8080>/<VirtualHost *:80>/' /etc/apache2/sites-available/000-default.conf
    elif grep -q "<VirtualHost \*:" /etc/apache2/sites-available/000-default.conf 2>/dev/null; then
        # Only replace the first VirtualHost directive
        sed -i '0,/<VirtualHost \*:/s/<VirtualHost \*:[0-9]*>/<VirtualHost *:80>/' /etc/apache2/sites-available/000-default.conf
    fi
    
    # Validate configuration
    if apache2ctl configtest > /dev/null 2>&1; then
        echo "✓ Apache configured for local/CI (0.0.0.0:80)"
    else
        echo "⚠️  Warning: Apache configuration test failed, but continuing..."
        apache2ctl configtest 2>&1 | head -5 || true
    fi
fi

# Execute Apache entrypoint (starts Apache in foreground)
# Use docker-php-entrypoint if available, otherwise call apache2-foreground directly
if command -v docker-php-entrypoint >/dev/null 2>&1; then
    exec docker-php-entrypoint apache2-foreground
else
    exec apache2-foreground
fi

