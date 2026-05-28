# Operations Guide

Production operations for AviationWX.org: logging, monitoring, and troubleshooting.

## Quick Reference

### Essential Commands

```bash
# View logs
docker compose -f docker/docker-compose.prod.yml exec web tail -f /var/log/aviationwx/app.log

# Check container health
docker compose -f docker/docker-compose.prod.yml ps

# Restart application
docker compose -f docker/docker-compose.prod.yml restart

# Check scheduler status
docker compose -f docker/docker-compose.prod.yml exec web cat /tmp/scheduler.lock | jq

# Clear config cache
curl https://aviationwx.org/admin/cache-clear.php
```

---

## Logging

### Log Files

All logs are in `/var/log/aviationwx/` inside the container (mounted from `/var/aviationwx/logs` on host, persists across reboots):

| File | Description |
|------|-------------|
| `app.log` | PHP application logs (JSONL) |
| `apache-access.log` | HTTP access logs |
| `apache-error.log` | HTTP error logs |
| `php-error.log` | PHP runtime errors |
| `cron-heartbeat.log` | Cron daemon status |
| `cleanup-push-upload-debris.log` | Hourly push FTP/SFTP inbox debris cleanup (stdout) |
| `cleanup-cache.log` | Daily full cache cleanup (stdout) |
| `scheduler-health-check.log` | Scheduler health checks |

Root-only cron logs under `/var/lib/aviationwx/` (not in the table above):

| File | Description |
|------|-------------|
| `set-cache-permissions.log` | Nightly cache/FTP/SFTP chroot permission repair (`set-cache-permissions.sh`, 01:00 UTC) |

**Log rotation**: 1 rotated file, 100MB max per file.

### Viewing Logs

```bash
# Live tail application logs
docker compose -f docker/docker-compose.prod.yml exec web tail -f /var/log/aviationwx/app.log

# View with JSON formatting
docker compose -f docker/docker-compose.prod.yml exec web cat /var/log/aviationwx/app.log | jq .

# Filter errors only
docker compose -f docker/docker-compose.prod.yml exec web cat /var/log/aviationwx/app.log | jq 'select(.level == "error")'

# Filter by airport
docker compose -f docker/docker-compose.prod.yml exec web cat /var/log/aviationwx/app.log | jq 'select(.context.airport == "kspb")'
```

### Log Format

Application logs use JSONL format:

```json
{"ts":"2024-01-01T12:00:00+00:00","level":"info","request_id":"abc123","message":"Weather data fetched","context":{"airport":"KSPB"},"source":"web"}
```

---

## Monitoring

### Status Page

**URL**: `https://status.aviationwx.org` or `/status.php`

Shows:
- System health (Config, Cache, APCu, Logging, Error Rate)
- Per-airport status (Weather API, Webcams)
- Usage metrics (Views, API requests, Map tiles served)

**Interpreting signals**

- **Weather Data Fetching** (system row): aggregate **HTTP fetch success** for weather sources over the last hour. It is **not** a guarantee that every airport has fresh observations.
- **Per-airport weather**: based on observation timestamps in the weather cache (same family of values as the public API).
- **Per-airport webcams**: freshness uses **last completed frame** time on disk (aligned with the image pipeline and API), not the `current.jpg` symlink mtime alone.

**Schedulers and missing sensors**

When an airport has no `weather_sources` or a webcam slot has no acquisition settings, schedulers **skip those workers** on purpose. Cron noise goes down, but you should still watch **staleness on the dashboard** and **status page per-airport rows**. Silence from `fetch-weather.php` / unified webcam workers does not prove observations are fresh; it means nothing was queued for that slot.

### Health Endpoints

| Endpoint | Purpose |
|----------|---------|
| `/health/health.php` | Simple health check (returns `{"status":"ok"}`) |
| `/admin/diagnostics.php` | Detailed system info |
| `/admin/metrics.php` | Prometheus-format metrics |

### External probes (GitHub Actions)

Scheduled checks from GitHub Actions runners:

- **Weekly Link Check** (`weekly-link-check.yml`) - sitemap, airport dashboards, and external links (`scripts/link-check.php`). See [LINK_CHECK.md](LINK_CHECK.md).
- **Weekly upstream API probes** - third-party weather API reachability.
- **Built-in aviation links** - `scripts/check-builtin-aviation-links.php` (also in the weekly link workflow; **`make check-builtin-aviation-links`** locally).

Post-deploy verification runs **on the production host over SSH** (container health, `curl` to internal routes). That is the authoritative deploy gate, not runner-origin HTTPS probes.

### Scheduler Verification

**Startup model:** the `web` container **entrypoint** starts one `scripts/scheduler.php` process after cache permissions are ready. **Cron** runs `scripts/scheduler-health-check.php` every minute as a **watchdog**: it checks the lock file and `/proc`, and starts a replacement only when recovery is needed (for example missing PID, lock health not healthy, or stale lock with no live daemon). It is not meant to compete with the entrypoint for a routine second start when one healthy daemon is already running.

```bash
# Check scheduler is running
docker compose -f docker/docker-compose.prod.yml exec web ps aux | grep scheduler

# Check lock file (shows PID and start time)
docker compose -f docker/docker-compose.prod.yml exec web cat /tmp/scheduler.lock | jq

# Force restart scheduler (auto-restarts within 60s)
docker compose -f docker/docker-compose.prod.yml exec web pkill -f scheduler.php
```

**Duplicate daemons:** if `app.log` reports multiple scheduler processes, gather facts before sending signals (killing the wrong PID can leave the lock path pointing at the wrong inode). Read-only summary:

```bash
docker compose -f docker/docker-compose.prod.yml exec -T web php scripts/diagnose-scheduler-duplicates.php
```

After a code fix, prefer a single clean `web` container restart so only the entrypoint-started daemon remains.

### Metrics System

Live counters live in APCu (per PHP-FPM worker). Each worker writes spill snapshots after requests (unique filenames under `cache/metrics/spill/{YYYY-MM-DD-HH}/{pid}_{hex}.json`) so unconsumed shards are not overwritten. The scheduler merges those shards into canonical hourly files:

- **Hourly**: `cache/metrics/hourly/YYYY-MM-DD-HH.json`
- **Daily**: `cache/metrics/daily/YYYY-MM-DD.json`
- **Spill root**: `cache/metrics/spill/`
- **Aggregator telemetry**: `cache/metrics/aggregator_last_run.json`

Merge cadence is `METRICS_SPILL_MERGE_INTERVAL_SECONDS` (scheduler invokes `scripts/aggregate-metrics-spills.php` via CLI). After a successful merge, the scheduler calls `metrics_status_bundle_mirror_refresh_via_http()` so PHP-FPM rebuilds the status bundle from disk and repopulates the APCu mirror (`METRICS_STATUS_BUNDLE_MIRROR_TTL_SECONDS`). Web requests also warm the mirror on a cold read. Variant-health APCu counters are flushed over HTTP on `METRICS_FLUSH_INTERVAL_SECONDS` because CLI cannot see FPM APCu.

**Tracked metrics:** (unchanged) airport page views, weather requests, webcam serves, map tiles, browser format support, cache hit/miss, etc.

Manual variant-health flush (localhost only; same security model as before):

```bash
curl -sS -H 'X-Scheduler-Request: 1' 'http://127.0.0.1:8080/health/variant-health-flush.php'
```

Expect JSON with `"success":true` (boolean) and `"results":{"variant_health_flush":true}`. The scheduler uses `getInternalApacheBaseUrl()` (`WEATHER_REFRESH_URL`, typically `http://localhost:8080` in Docker). When `WEATHER_REFRESH_URL` is unset, the fallback is `http://localhost:` plus `APP_PORT`, then `PORT`, then `8080`.

#### Internal endpoint (`health/variant-health-flush.php`)

Security: only `127.0.0.1` and `::1` (`REMOTE_ADDR`; not `X-Forwarded-For`).

**Success (HTTP 200):**

| Field | Type | Notes |
|-------|------|--------|
| `success` | bool | `true` only if variant health flush succeeds. |
| `timestamp` | int | Unix time. |
| `results.variant_health_flush` | bool | |

**Uncaught exception:**

| Field | Type | Notes |
|-------|------|--------|
| `success` | bool | `false` |
| `timestamp` | int | |
| `results.error` | string | Exception message (duplicate of `flush_endpoint_error`). |
| `results.flush_endpoint_error` | string | PHP exception message before variant flush finished. |

Manual spill merge (operators; runs the same merge pass as the scheduler):

```bash
php scripts/aggregate-metrics-spills.php
```

Manual status-bundle mirror refresh (localhost only; invalidates APCu mirror, rebuilds from `cache/metrics/*`, re-stores APCu):

```bash
curl -sS -H 'X-Scheduler-Request: 1' 'http://127.0.0.1:8080/health/status-bundle-mirror-refresh.php'
```

Expect `"success":true`. Same `WEATHER_REFRESH_URL` / Apache requirement as other internal health HTTP calls. On failure (PHP exception during rebuild) the endpoint returns HTTP **500** with JSON `"success":false` and `"error"` carrying the exception message.

### Metrics spill merge failing (status page gaps, logs show aggregator issues)

1. **Permissions on the cache bind mount** (host): `cache/metrics`, `cache/metrics/spill`, and `cache/metrics/hourly` must be writable by `www-data`. Quick check inside the container: `ls -la /var/www/html/cache/metrics`.
2. **Internal URL (variant health and status bundle mirror refresh)**: `WEATHER_REFRESH_URL` must point at Apache in the same container (same as weather refresh). Wrong port or host means `variant_health_flush_via_http()` and `metrics_status_bundle_mirror_refresh_via_http()` never reach PHP-FPM.
3. **Aggregator lock**: only one merge process should hold `cache/metrics/aggregator.lock`. If merges stall, inspect `aggregator_last_run.json` and spill shard ages under `cache/metrics/spill/`.
4. **Application log**: `grep -E 'metrics|variant health|aggregator|status bundle mirror' /var/log/aviationwx/app.log` (paths may vary; see logging section above).

---

## Troubleshooting

### Container Issues

```bash
# Check container status
docker compose -f docker/docker-compose.prod.yml ps

# View startup logs
docker compose -f docker/docker-compose.prod.yml logs web

# Full restart
docker compose -f docker/docker-compose.prod.yml down && docker compose -f docker/docker-compose.prod.yml up -d
```

### Missing Webcam Images

```bash
# Check scheduler and cron
docker compose -f docker/docker-compose.prod.yml exec web ps aux | grep -E "(scheduler|cron)"

# Manually run unified webcam worker for single camera
docker compose -f docker/docker-compose.prod.yml exec -T web php scripts/unified-webcam-worker.php --worker kspb 0

# Check cache directory
docker compose -f docker/docker-compose.prod.yml exec web ls -la /var/www/html/cache/webcams/kspb/0/

# Check for orphaned staging files (indicates crashed workers)
docker compose -f docker/docker-compose.prod.yml exec web find /var/www/html/cache/webcams -name "staging_*.tmp" -ls

# Check worker lock files
docker compose -f docker/docker-compose.prod.yml exec web find /var/www/html/cache/webcams -name "worker.lock" -ls
```

### Stale Weather Data

```bash
# Check weather cache age
docker compose -f docker/docker-compose.prod.yml exec web ls -la /var/www/html/cache/weather/

# Force weather refresh
docker compose -f docker/docker-compose.prod.yml exec -T web php scripts/fetch-weather.php --worker kspb
```

### Configuration Issues

```bash
# Clear config cache
curl https://aviationwx.org/admin/cache-clear.php

# Validate config (inside container)
docker compose -f docker/docker-compose.prod.yml exec web php -r "require 'lib/config.php'; var_dump(validateAirportsJsonStructure(loadAirportsConfig()));"
```

### High Error Rate

```bash
# Count errors by type
docker compose -f docker/docker-compose.prod.yml exec web cat /var/log/aviationwx/app.log | jq 'select(.level == "error") | .message' | sort | uniq -c | sort -rn

# Recent errors (last 20)
docker compose -f docker/docker-compose.prod.yml exec web tail -100 /var/log/aviationwx/app.log | jq 'select(.level == "error")' | tail -20
```

### FTPS alternate control port (NAT redirect)

Some client networks block non-standard FTP control ports. With host networking, vsftpd binds `config.network_ports.ftp_control` on the host (default 2121). Add a second inbound TCP port that netfilter REDIRECTs to `ftp_control` before traffic reaches vsftpd so logs and fail2ban keep the real client source IP (unlike a userspace forward to `127.0.0.1`).

Hostname, credentials, TLS, and passive data ports (`ftp_passive_min` / `ftp_passive_max`) stay as configured; the alternate inbound port is only for the control connection.

**Declarative setup:** On the production host, `~/airports.json` (same file as `CONFIG_PATH`) may include `config.network_ports` (see [Configuration](CONFIGURATION.md#network-configuration)). Set `ftps_alt` to the extra inbound control port; NAT targets `ftp_control`. Each deploy runs `scripts/deploy-configure-firewall.sh` after rsync to apply UFW/iptables and runs `production-ftps-alt-port-nat.sh ensure` with `VSFTPD_LISTEN_PORT` equal to `ftp_control`. If `~/airports.json` is missing, deploy uses built-in port defaults and skips NAT reconciliation.

**Manual (host, not inside Docker):**

```bash
sudo ./scripts/production-ftps-alt-port-nat.sh install 8021   # one-time style; prefer ensure + config for CD
sudo ./scripts/production-ftps-alt-port-nat.sh ensure 8021      # idempotent
sudo ./scripts/production-ftps-alt-port-nat.sh status
sudo ./scripts/production-ftps-alt-port-nat.sh remove
```

The script inserts a marked block into `/etc/ufw/before.rules` and `/etc/ufw/before6.rules`, then runs `ufw reload`, so the rules persist across reloads.

If UFW’s `before.rules` has no `*nat` table (some minimal installs ship filter-only), the script appends a minimal `*nat` block so PREROUTING REDIRECT rules can load. If that still fails, ensure `/etc/ufw/before.rules` is writable and UFW is installed (`sudo ufw status`).

After REDIRECT, vsftpd still listens on `ftp_control` inside the container. When `ftps_alt` is set, `deploy-configure-firewall.sh` adds `ufw allow` for that port so stale-rule cleanup does not drop it.

From another machine (substitute your hostname and port):

```bash
openssl s_client -connect upload.example.org:8021 -starttls ftp -servername upload.example.org </dev/null
```

---

## Client Version Management

Handles rare cases where browser clients get stuck on old cached versions.

### Version File

`config/version.json` is generated during deployment:

```json
{
  "hash": "abc123",
  "timestamp": 1735142400,
  "force_cleanup": false,
  "max_no_update_days": 7
}
```

### Emergency Client Cleanup

Force ALL clients to clear caches and reload:

1. Edit `airports.json` on production server
2. Set `"force_cleanup": true` in the `config` section
3. All clients will clear caches on next visit
4. After resolved, set `"force_cleanup": false`

### Configuration Options (in `airports.json`)

| Option | Default | Description |
|--------|---------|-------------|
| `dead_man_switch_days` | 7 | Days without SW update before cleanup (0 = disabled) |
| `force_cleanup` | false | Emergency flag to force all client cleanup |
| `stuck_client_cleanup` | false | Inject cleanup for clients stuck on old code |

---

## Fail2ban Management

AviationWX uses dual fail2ban instances for defense in depth. See [Security Guide](SECURITY.md#fail2ban-brute-force-protection) for architecture details.

### Host-Level Fail2ban (SSH Protection)

Protects server SSH on port 22 with strict policies (5 failures = 7 day ban).

```bash
# Check all jails status
sudo fail2ban-client status

# Check SSH jail specifically
sudo fail2ban-client status sshd

# View currently banned IPs
sudo fail2ban-client status sshd | grep "Banned IP"

# Unban an IP (if needed)
sudo fail2ban-client set sshd unbanip <IP_ADDRESS>

# View fail2ban logs
sudo tail -f /var/log/fail2ban.log

# Restart fail2ban service
sudo systemctl restart fail2ban

# Check fail2ban service status
sudo systemctl status fail2ban
```

### Container-Level Fail2ban (Camera Upload Protection)

Protects FTP/SFTP camera uploads with forgiving policies (10 failures in 1 hour = 1 hour ban).

```bash
# Check all container jails
docker compose -f docker/docker-compose.prod.yml exec web fail2ban-client status

# Check vsftpd jail (ports match config.network_ports.ftp_control / ftps_explicit_tls; defaults 2121/2122)
docker compose -f docker/docker-compose.prod.yml exec web fail2ban-client status vsftpd

# Check sshd-sftp jail (port matches config.network_ports.sftp; default 2222)
docker compose -f docker/docker-compose.prod.yml exec web fail2ban-client status sshd-sftp

# View currently banned IPs for vsftpd
docker compose -f docker/docker-compose.prod.yml exec web fail2ban-client status vsftpd | grep "Banned IP"

# Unban a camera IP from vsftpd
docker compose -f docker/docker-compose.prod.yml exec web fail2ban-client set vsftpd unbanip <IP_ADDRESS>

# Unban a camera IP from sshd-sftp
docker compose -f docker/docker-compose.prod.yml exec web fail2ban-client set sshd-sftp unbanip <IP_ADDRESS>

# View vsftpd authentication log
docker compose -f docker/docker-compose.prod.yml exec web tail -100 /var/log/vsftpd.log | grep -i "fail\|denied"
```

### Troubleshooting Camera Bans

If a legitimate camera is banned:

1. **Check if banned:**
   ```bash
   docker compose -f docker/docker-compose.prod.yml exec web fail2ban-client status vsftpd
   ```

2. **Unban the IP:**
   ```bash
   docker compose -f docker/docker-compose.prod.yml exec web fail2ban-client set vsftpd unbanip <CAMERA_IP>
   ```

3. **Fix camera configuration:**
   - Verify FTP/SFTP credentials in `airports.json`
   - Check camera's upload settings
   - Confirm network connectivity

4. **Monitor logs:**
   ```bash
   docker compose -f docker/docker-compose.prod.yml exec web tail -f /var/log/vsftpd.log
   ```

**Note:** With forgiving policies (10 failures/hour = 1 hour ban), most configuration issues self-heal quickly.

### Bridge / SFTP uploads fail (chroot permissions)

Symptoms on the AviationWX Bridge: `ssh: unexpected packet in response to channel open` after password auth. On the server, `/var/log/aviationwx/sshd.log` shows `Accepted password` followed by:

```text
fatal: bad ownership or modes for chroot directory "/var/sftp/{username}"
```

**Cause:** Each SFTP chroot directory (`/var/sftp/{username}/`) must be `root:root` mode `755`. Uploads go to `files/` (`ftp:www-data` `2775`). A recursive `chown` on the host cache tree (for example `chown -R www-data /tmp/aviationwx-cache`) also changes `sftp/{user}/` and breaks sshd chroot.

**Verify:**

```bash
docker exec aviationwx-web bash -lc 'namei -l /var/sftp/kspbcam1 /var/sftp/kspbcam1/files'
docker exec aviationwx-web bash -lc 'grep -i "bad ownership\|kspbcam" /var/log/aviationwx/sshd.log | tail -20'
```

**Repair (run as root in the web container):**

```bash
docker exec -u root aviationwx-web /usr/local/libexec/aviationwx/repair-sftp-chroot-permissions.sh
# or full push sync (also repairs chroots, then syncs users if config changed):
docker exec -u root aviationwx-web php /var/www/html/scripts/sync-push-config.php
```

**Prevention:** Deploy applies `www-data` ownership only to cache data directories, not `sftp/{user}/`. Nightly `set-cache-permissions.sh` and every `sync-push-config.php` run call the repair script; both exit non-zero if repair fails (check `set-cache-permissions.log` or deploy sync output). Do not run `chown -R www-data` on `/tmp/aviationwx-cache` on the host without re-running repair afterward.

### Upload health probe and service watchdog

Production can run functional FTPS/SFTP upload probes and restart wedged daemons automatically.

| Component | Interval | Role |
|-----------|----------|------|
| `upload-probe-runner.sh` | `config.upload_health_probe.interval_sec` (default 30s) | Passive upload test, writes heartbeat |
| `service-watchdog.sh` | 50s loop | Reads heartbeat, restarts vsftpd or container sshd when unhealthy |

**Enable:** Set `config.upload_health_probe.enabled` to `true` in production `airports.json` with a **dedicated** probe user (must not match any `push_config.username`). See [Configuration](CONFIGURATION.md#upload-health-probe).

**State and logs:**

| Path | Purpose |
|------|---------|
| `/var/lib/aviationwx/upload-probe.json` | Last probe heartbeat (mode 600) |
| `/var/log/aviationwx/upload-probe.log` | Probe runner output |
| `/var/log/aviationwx/service-watchdog.log` | Watchdog and restart actions |
| `app.log` | Structured `upload health` events (failures, restarts, throttling) |

**Recovery policy:** Two consecutive failed or stale probe evaluations per protocol, then at most one daemon restart per 30 minutes (shared throttle). Process death uses the same throttle. Missing `jq` or a corrupt heartbeat is treated as unhealthy (fail closed).

**Hairpin NAT:** If probes fail while cameras upload, set `config.upload_health_probe.probe_connect_host` (for example `127.0.0.1`) so the probe connects without looping through the public IP.

```bash
# Heartbeat and recent probe log
docker exec aviationwx-web cat /var/lib/aviationwx/upload-probe.json | jq .
docker exec aviationwx-web tail -50 /var/log/aviationwx/upload-probe.log

# Watchdog / restarts
docker exec aviationwx-web tail -50 /var/log/aviationwx/service-watchdog.log
sudo grep -i 'upload health\|upload probe' /var/aviationwx/logs/app.log | tail -20
```

---

## Related Documentation

- [Deployment Guide](DEPLOYMENT.md) - Production deployment
- [Architecture](ARCHITECTURE.md) - System design
- [Configuration](CONFIGURATION.md) - Airport configuration
- [Testing](TESTING.md) - Test strategy
