# Operations Guide

Guide for logging, monitoring, and troubleshooting AviationWX.org in production.

## Table of Contents

1. [Logging Overview](#logging-overview)
2. [Viewing Logs](#viewing-logs)
3. [Filtering Logs](#filtering-logs)
4. [Monitoring](#monitoring)
5. [Troubleshooting](#troubleshooting)

## Logging Overview

All logs are written to files in `/var/log/aviationwx/`. Log rotation is handled by logrotate (7 days retention, 100MB max per file).

### Log Files

| File | Description |
|------|-------------|
| `app.log` | PHP application logs (JSONL format) |
| `user.log` | User activity logs (JSONL format) |
| `apache-access.log` | Apache HTTP access logs |
| `apache-error.log` | Apache HTTP error logs |
| `php-error.log` | PHP runtime errors |
| `cron-heartbeat.log` | Cron daemon heartbeat |
| `cron-push-webcams.log` | Push webcam processing |
| `scheduler-health-check.log` | Scheduler health checks |
| `cleanup-cache.log` | Cache cleanup operations |
| `service-watchdog.log` | Service watchdog logs |

### Log Formats

#### PHP Application Logs (JSONL)
```json
{"ts":"2024-01-01T12:00:00+00:00","level":"info","request_id":"abc123","message":"Weather data fetched","context":{"airport":"KSPB","source":"web"},"log_type":"app","source":"web"}
```

#### Apache Access Logs (Plain Text)
```
[apache_access] 1.2.3.4 - - [01/Jan/2024:12:00:00 +0000] "GET / HTTP/1.1" 200 1234 "-" "Mozilla/5.0..."
```

## Viewing Logs

### View Logs Inside Container

```bash
# View application logs
docker compose -f docker/docker-compose.prod.yml exec web tail -f /var/log/aviationwx/app.log

# View Apache access logs
docker compose -f docker/docker-compose.prod.yml exec web tail -f /var/log/aviationwx/apache-access.log

# View Apache error logs
docker compose -f docker/docker-compose.prod.yml exec web tail -f /var/log/aviationwx/apache-error.log

# View all logs
docker compose -f docker/docker-compose.prod.yml exec web tail -f /var/log/aviationwx/*.log
```

### View Logs by Type

```bash
# Application logs (PHP)
docker compose -f docker/docker-compose.prod.yml exec web cat /var/log/aviationwx/app.log | jq .

# Cron job logs
docker compose -f docker/docker-compose.prod.yml exec web cat /var/log/aviationwx/cron-heartbeat.log

# Push webcam processing logs
docker compose -f docker/docker-compose.prod.yml exec web cat /var/log/aviationwx/cron-push-webcams.log
```

## Filtering Logs

### Filter Application Logs

#### PHP Application Logs (JSONL)

```bash
# Extract all PHP application logs
docker compose -f docker/docker-compose.prod.yml exec web cat /var/log/aviationwx/app.log | jq .

# Filter by log level
docker compose -f docker/docker-compose.prod.yml exec web cat /var/log/aviationwx/app.log | jq 'select(.level == "error")'

# Filter by source
docker compose -f docker/docker-compose.prod.yml exec web cat /var/log/aviationwx/app.log | jq 'select(.source == "web")'

# Filter by log type
docker compose -f docker/docker-compose.prod.yml exec web cat /var/log/aviationwx/app.log | jq 'select(.log_type == "app")'

# Filter errors and warnings
docker compose -f docker/docker-compose.prod.yml exec web cat /var/log/aviationwx/app.log | jq 'select(.level == "error" or .level == "warning")'
```

#### Apache Access Logs

```bash
# View Apache access logs
docker compose -f docker/docker-compose.prod.yml exec web cat /var/log/aviationwx/apache-access.log

# Filter by status code (4xx and 5xx errors)
docker compose -f docker/docker-compose.prod.yml exec web grep ' 4[0-9][0-9] \| 5[0-9][0-9] ' /var/log/aviationwx/apache-access.log

# Filter by request path
docker compose -f docker/docker-compose.prod.yml exec web grep '/api/' /var/log/aviationwx/apache-access.log
```

### Filter Error Logs

```bash
# All PHP errors
docker compose -f docker/docker-compose.prod.yml exec web cat /var/log/aviationwx/app.log | jq 'select(.level == "error")'

# Apache errors
docker compose -f docker/docker-compose.prod.yml exec web cat /var/log/aviationwx/apache-error.log

# PHP runtime errors
docker compose -f docker/docker-compose.prod.yml exec web cat /var/log/aviationwx/php-error.log
```

### Advanced Filtering with jq

```bash
# Count errors by message
docker compose -f docker/docker-compose.prod.yml exec web cat /var/log/aviationwx/app.log | jq 'select(.level == "error") | .message' | sort | uniq -c | sort -rn

# Error rate over time (by date)
docker compose -f docker/docker-compose.prod.yml exec web cat /var/log/aviationwx/app.log | jq 'select(.level == "error") | .ts' | cut -d'T' -f1 | uniq -c

# Recent errors (last 20)
docker compose -f docker/docker-compose.prod.yml exec web tail -100 /var/log/aviationwx/app.log | jq 'select(.level == "error")' | tail -20
```

### Using Helper Script

A helper script is available for easy log filtering:

```bash
# Show all access logs
./scripts/dev-filter-logs.sh access

# Show application logs
./scripts/dev-filter-logs.sh app

# Show error logs
./scripts/dev-filter-logs.sh error

# Show Nginx logs
./scripts/dev-filter-logs.sh nginx
```

## Monitoring

### Status Page

Visit the status page (`/status.php` or `status.aviationwx.org` in production) for real-time system health:
- System components (Configuration, Cache, APCu, Logging, Error Rate)
- Per-airport status (Weather API, Webcams)
- Status indicators (Green/Yellow/Red)
- Timestamps showing when each component status last changed

### Health Checks

```bash
# Health check endpoint
curl http://localhost:8080/health/health.php

# Diagnostics endpoint (shows detailed system info)
curl http://localhost:8080/admin/diagnostics.php
```

**Note**: In production, replace `localhost:8080` with your domain (e.g., `https://aviationwx.org`).

### Container Health

```bash
# Check container status
docker compose -f docker/docker-compose.prod.yml ps

# Check container health
docker compose -f docker/docker-compose.prod.yml ps --format "table {{.Name}}\t{{.Status}}\t{{.Health}}"
```

### Cron Jobs

Verify cron jobs are running inside the container:

```bash
# Check cron is running
docker compose -f docker/docker-compose.prod.yml exec web ps aux | grep cron

# Check scheduler status
docker compose -f docker/docker-compose.prod.yml exec web cat /tmp/scheduler.lock | jq

# Manually test webcam fetcher (worker mode - single airport/camera)
docker compose -f docker/docker-compose.prod.yml exec -T web php scripts/fetch-webcam.php --worker kspb 0

# Manually test weather fetcher (worker mode - single airport)
docker compose -f docker/docker-compose.prod.yml exec -T web php scripts/fetch-weather.php --worker kspb

# Restart scheduler if needed
docker compose -f docker/docker-compose.prod.yml exec web pkill -f scheduler.php
# Scheduler will be restarted automatically by health check within 60 seconds
```

## Troubleshooting

### Common Issues

#### Containers Not Starting

```bash
# Check container status
docker compose -f docker/docker-compose.prod.yml ps

# View container logs (startup issues)
docker compose -f docker/docker-compose.prod.yml logs web

# Restart containers
docker compose -f docker/docker-compose.prod.yml restart
```

#### High Error Rate

```bash
# View recent errors
docker compose -f docker/docker-compose.prod.yml exec web tail -100 /var/log/aviationwx/app.log | jq 'select(.level == "error")'

# Count errors by type
docker compose -f docker/docker-compose.prod.yml exec web cat /var/log/aviationwx/app.log | jq 'select(.level == "error") | .message' | sort | uniq -c | sort -rn
```

#### Missing Webcam Images

```bash
# Check cron is running
docker compose -f docker/docker-compose.prod.yml exec web ps aux | grep cron

# Check scheduler is running
docker compose -f docker/docker-compose.prod.yml exec web ps aux | grep scheduler

# Manually trigger webcam update for specific airport/camera (worker mode)
docker compose -f docker/docker-compose.prod.yml exec -T web php scripts/fetch-webcam.php --worker kspb 0

# Check cache directory (location depends on deployment)
ls -lh cache/webcams/*/
```

#### Configuration Issues

```bash
# Clear configuration cache
curl http://localhost:8080/admin/cache-clear.php

# Check configuration file (production path)
docker compose -f docker/docker-compose.prod.yml exec web cat /var/www/html/config/airports.json | jq .
```

**Note**: In production, replace `localhost:8080` with your domain.

### Log Parsing Tools

#### Install jq (Recommended)

```bash
# macOS
brew install jq

# Ubuntu/Debian
apt-get install jq

# CentOS/RHEL
yum install jq
```

#### Extract Logs to File

```bash
# Copy log files from container to host
docker compose -f docker/docker-compose.prod.yml exec web cat /var/log/aviationwx/app.log > app.log
docker compose -f docker/docker-compose.prod.yml exec web cat /var/log/aviationwx/apache-access.log > apache-access.log
docker compose -f docker/docker-compose.prod.yml exec web cat /var/log/aviationwx/apache-error.log > apache-error.log
```

## Best Practices

1. **Use structured logging** - All application logs use JSON format for easy parsing
2. **Filter at source** - Use `grep` and `jq` to filter logs before processing
3. **Monitor error rates** - Set up alerts for high error rates
4. **Separate concerns** - Access logs for traffic analysis, application logs for debugging
5. **Use log aggregation** - For production, consider tools like ELK, Loki, or CloudWatch

## Log Rotation

Logrotate handles log rotation with the following settings:
- **Retention**: 7 days
- **Max size**: 100MB per log file
- **Compression**: Older logs are compressed with gzip
- **Location**: `/var/log/aviationwx/`

Configuration is in `/etc/logrotate.d/aviationwx`.

## Performance Considerations

For large log volumes:
- Use `tail -f` for real-time monitoring instead of `cat`
- Filter before processing: `cat logfile | grep pattern | jq ...`
- Use log aggregation tools for production environments
- Consider log sampling for high-volume access logs

## Client Version Management

The site includes a "dead man's switch" mechanism to handle rare cases where client browsers get stuck on old cached versions (particularly iOS Safari).

### How It Works

On each page load, the client JavaScript:
1. Checks if a service worker update has occurred in the last 7 days
2. Fetches `/api/v1/version.php` to compare versions (non-blocking)
3. Triggers a full cleanup if the client is stuck

### Emergency Client Cleanup

If you need to force ALL clients to clear their caches and reload:

1. Edit `config/version.json` on the production server:
   ```json
   {
       "hash": "abc123",
       "hash_full": "abc123...",
       "timestamp": 1735142400,
       "deploy_date": "2025-12-25T12:00:00Z",
       "force_cleanup": true,
       "max_no_update_days": 7,
       "stuck_client_cleanup": false
   }
   ```

2. Set `force_cleanup` to `true`

3. On next visit, all clients will:
   - Clear all Cache API caches
   - Clear localStorage and sessionStorage
   - Unregister all service workers
   - Force reload from network

4. After the issue is resolved, set `force_cleanup` back to `false`

### Version File

The `config/version.json` file is automatically generated during deployment by `scripts/deploy-update-cache-version.sh`. It contains:
- Git hash of the current deployment
- Deploy timestamp
- Configuration for the dead man's switch (sourced from `airports.json`)

**Note**: This file is gitignored and generated at deploy time. Configuration values are read from `airports.json` global config section.

### Configuration in airports.json

Add these to the `config` section of `airports.json` to control version management:

```json
{
  "config": {
    "dead_man_switch_days": 7,
    "force_cleanup": false,
    "stuck_client_cleanup": false,
    ...
  }
}
```

- **dead_man_switch_days**: Days without SW update before cleanup triggers (default: 7, set to 0 to disable)
- **force_cleanup**: Emergency flag to force ALL clients to cleanup immediately (default: false)
- **stuck_client_cleanup**: Inject cleanup for clients stuck on old code (default: false)

### Stuck Client Cleanup

The `stuck_client_cleanup` flag controls server-side injection of cleanup scripts for clients stuck on old code:

- **`false`** (default): Disabled. Safe default for normal operation.
- **`true`**: Injects cleanup scripts for clients missing the version cookie but having other aviationwx cookies.

**When to enable:**
Set `stuck_client_cleanup: true` in `airports.json` temporarily after major deployments to catch clients stuck on old code. After 30-60 days, set it back to `false`.

### Monitoring

Watch for `[Version]` prefixed console messages in browser dev tools:
- `[Version] Performing full cleanup...` - Cleanup triggered
- `[Version] SW controller changed...` - Normal SW update
- `[Version] API check failed...` - Version API unreachable (network issue)

## Related Documentation

- [Deployment Guide](DEPLOYMENT.md) - Production deployment
- [Architecture](ARCHITECTURE.md) - System design
- [Configuration Guide](CONFIGURATION.md) - Airport configuration
