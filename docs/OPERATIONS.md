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
| `scheduler-health-check.log` | Scheduler health checks |

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

### Health Endpoints

| Endpoint | Purpose |
|----------|---------|
| `/health/health.php` | Simple health check (returns `{"status":"ok"}`) |
| `/admin/diagnostics.php` | Detailed system info |
| `/admin/metrics.php` | Prometheus-format metrics |

### Scheduler Verification

```bash
# Check scheduler is running
docker compose -f docker/docker-compose.prod.yml exec web ps aux | grep scheduler

# Check lock file (shows PID and start time)
docker compose -f docker/docker-compose.prod.yml exec web cat /tmp/scheduler.lock | jq

# Force restart scheduler (auto-restarts within 60s)
docker compose -f docker/docker-compose.prod.yml exec web pkill -f scheduler.php
```

### Metrics System

Metrics tracked in APCu, flushed to JSON files every 5 minutes:
- **Hourly**: `cache/metrics/hourly/YYYY-MM-DD-HH.json`
- **Daily**: `cache/metrics/daily/YYYY-MM-DD.json`

**Tracked metrics:**
- Airport page views
- Weather API requests
- Webcam serves (by format and size)
- Map tile serves (by source: OpenWeatherMap, RainViewer)
- Browser format support
- Cache hit/miss rates

Manual flush: `curl https://aviationwx.org/health/metrics-flush.php`

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

# Check vsftpd jail (FTP/FTPS on ports 2121/2122)
docker compose -f docker/docker-compose.prod.yml exec web fail2ban-client status vsftpd

# Check sshd-sftp jail (SFTP on port 2222)
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

---

## Related Documentation

- [Deployment Guide](DEPLOYMENT.md) - Production deployment
- [Architecture](ARCHITECTURE.md) - System design
- [Configuration](CONFIGURATION.md) - Airport configuration
- [Testing](TESTING.md) - Test strategy
