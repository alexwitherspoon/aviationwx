# Operations Guide

Guide for logging, monitoring, and troubleshooting AviationWX.org in production.

## Table of Contents

1. [Logging Overview](#logging-overview)
2. [Viewing Logs](#viewing-logs)
3. [Filtering Logs](#filtering-logs)
4. [Monitoring](#monitoring)
5. [Troubleshooting](#troubleshooting)

## Logging Overview

All logs are captured by Docker and written to stdout/stderr. Docker automatically handles log rotation (10MB files, 10 files = 100MB total per container).

### Log Sources

The application generates logs from multiple sources:

1. **Nginx Access Logs** - JSON format, source: `nginx_access`
2. **Nginx Error Logs** - Plain text, warnings and errors
3. **Apache Access Logs** - Plain text, prefixed with `[apache_access]`
4. **Apache Error Logs** - Plain text, PHP errors and warnings
5. **PHP Application Logs** - JSONL format, source: `app`, `web`, or `cli`

### Log Formats

#### Nginx Access Logs (JSON)
```json
{"time":"2024-01-01T12:00:00+00:00","remote_addr":"1.2.3.4","request":"GET / HTTP/1.1","request_method":"GET","request_uri":"/","status":200,"body_bytes_sent":1234,"http_referer":"","http_user_agent":"Mozilla/5.0...","http_x_forwarded_for":"","request_time":0.001,"upstream_response_time":"0.001","source":"nginx_access"}
```

#### PHP Application Logs (JSONL)
```json
{"ts":"2024-01-01T12:00:00+00:00","level":"info","request_id":"abc123","message":"Weather data fetched","context":{"airport":"KSPB","source":"web"},"log_type":"app","source":"web"}
```

#### Apache Access Logs (Plain Text)
```
[apache_access] 1.2.3.4 - - [01/Jan/2024:12:00:00 +0000] "GET / HTTP/1.1" 200 1234 "-" "Mozilla/5.0..."
```

## Viewing Logs

### View All Logs

```bash
# All containers
docker compose -f docker/docker-compose.prod.yml logs -f

# Follow logs in real-time
docker compose -f docker/docker-compose.prod.yml logs -f
```

### View Logs by Container

```bash
# Web application logs
docker compose -f docker/docker-compose.prod.yml logs -f web

# Nginx logs
docker compose -f docker/docker-compose.prod.yml logs -f nginx

# VPN server logs (if VPN is configured)
docker compose -f docker/docker-compose.prod.yml logs -f vpn-server
```

## Filtering Logs

### Filter Access Logs Only

#### Nginx Access Logs (JSON)

```bash
# Extract only Nginx access logs
docker compose -f docker/docker-compose.prod.yml logs nginx | grep -o '{"source":"nginx_access"[^}]*}'

# Pretty print JSON access logs
docker compose -f docker/docker-compose.prod.yml logs nginx | grep -o '{"source":"nginx_access"[^}]*}' | jq .

# Filter by status code
docker compose -f docker/docker-compose.prod.yml logs nginx | grep -o '{"source":"nginx_access"[^}]*}' | jq 'select(.status >= 400)'

# Filter by request URI
docker compose -f docker/docker-compose.prod.yml logs nginx | grep -o '{"source":"nginx_access"[^}]*}' | jq 'select(.request_uri | contains("/api"))'
```

#### Apache Access Logs (Plain Text)

```bash
# Extract only Apache access logs
docker compose -f docker/docker-compose.prod.yml logs web | grep '\[apache_access\]'

# Filter by status code
docker compose -f docker/docker-compose.prod.yml logs web | grep '\[apache_access\]' | grep ' 4[0-9][0-9] \| 5[0-9][0-9] '

# Filter by request path
docker compose -f docker/docker-compose.prod.yml logs web | grep '\[apache_access\]' | grep '/api/'
```

### Filter Application Logs Only

#### PHP Application Logs (JSONL)

```bash
# Extract only PHP application logs (JSON format)
docker compose -f docker/docker-compose.prod.yml logs web | grep -E '^\{"ts":' | jq .

# Filter by log level
docker compose -f docker/docker-compose.prod.yml logs web | grep -E '^\{"ts":' | jq 'select(.level == "error")'

# Filter by source
docker compose -f docker/docker-compose.prod.yml logs web | grep -E '^\{"ts":' | jq 'select(.source == "web")'

# Filter by log type
docker compose -f docker/docker-compose.prod.yml logs web | grep -E '^\{"ts":' | jq 'select(.log_type == "app")'

# Filter errors and warnings
docker compose -f docker/docker-compose.prod.yml logs web | grep -E '^\{"ts":' | jq 'select(.level == "error" or .level == "warning")'
```

### Filter Error Logs

```bash
# All errors (Nginx, Apache, PHP)
docker compose -f docker/docker-compose.prod.yml logs | grep -i error

# PHP errors only
docker compose -f docker/docker-compose.prod.yml logs web | grep -E '^\{"ts":' | jq 'select(.level == "error")'

# Nginx errors only
docker compose -f docker/docker-compose.prod.yml logs nginx | grep -v '{"source":"nginx_access"'
```

### Advanced Filtering with jq

```bash
# Count requests by status code
docker compose -f docker/docker-compose.prod.yml logs nginx | grep -o '{"source":"nginx_access"[^}]*}' | jq -s 'group_by(.status) | map({status: .[0].status, count: length})'

# Top requested URIs
docker compose -f docker/docker-compose.prod.yml logs nginx | grep -o '{"source":"nginx_access"[^}]*}' | jq -r '.request_uri' | sort | uniq -c | sort -rn | head -10

# Error rate over time
docker compose -f docker/docker-compose.prod.yml logs web | grep -E '^\{"ts":' | jq 'select(.level == "error") | .ts' | cut -d'T' -f1 | uniq -c

# Requests taking longer than 1 second
docker compose -f docker/docker-compose.prod.yml logs nginx | grep -o '{"source":"nginx_access"[^}]*}' | jq 'select(.request_time > 1.0)'
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

Visit the [Status Page](../status.php) or `status.aviationwx.org` for real-time system health:
- System components (Configuration, Cache, APCu, Logging, Error Rate)
- Per-airport status (Weather API, Webcams)
- Status indicators (Green/Yellow/Red)
- Timestamps showing when each component status last changed

### Health Checks

```bash
# Health check endpoint
curl https://aviationwx.org/health.php

# Diagnostics endpoint (shows detailed system info)
curl https://aviationwx.org/diagnostics.php
```

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

# Manually test webcam fetcher
docker compose -f docker/docker-compose.prod.yml exec -T web php scripts/fetch-webcam.php

# Manually test weather fetcher
docker compose -f docker/docker-compose.prod.yml exec -T web php scripts/fetch-weather.php
```

## Troubleshooting

### Common Issues

#### Containers Not Starting

```bash
# Check logs for errors
docker compose -f docker/docker-compose.prod.yml logs

# Check container status
docker compose -f docker/docker-compose.prod.yml ps

# Restart containers
docker compose -f docker/docker-compose.prod.yml restart
```

#### High Error Rate

```bash
# View recent errors
docker compose -f docker/docker-compose.prod.yml logs web | grep -E '^\{"ts":' | jq 'select(.level == "error")' | tail -20

# Count errors by type
docker compose -f docker/docker-compose.prod.yml logs web | grep -E '^\{"ts":' | jq 'select(.level == "error") | .message' | sort | uniq -c | sort -rn
```

#### Slow Requests

```bash
# Find slow requests (>1 second)
docker compose -f docker/docker-compose.prod.yml logs nginx | grep -o '{"source":"nginx_access"[^}]*}' | jq 'select(.request_time > 1.0)'

# Average response time
docker compose -f docker/docker-compose.prod.yml logs nginx | grep -o '{"source":"nginx_access"[^}]*}' | jq -s 'map(.request_time) | add / length'
```

#### Missing Webcam Images

```bash
# Check cron is running
docker compose -f docker/docker-compose.prod.yml exec web ps aux | grep cron

# Manually fetch webcam images
docker compose -f docker/docker-compose.prod.yml exec -T web php scripts/fetch-webcam.php

# Check cache directory
ls -lh /tmp/aviationwx-cache/webcams/
```

#### Configuration Issues

```bash
# Clear configuration cache
curl https://aviationwx.org/clear-cache.php

# Check configuration file
docker compose -f docker/docker-compose.prod.yml exec web cat /var/www/html/config/airports.json | jq .
```

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
# Extract Nginx access logs
docker compose -f docker/docker-compose.prod.yml logs nginx | grep -o '{"source":"nginx_access"[^}]*}' > nginx_access.log

# Extract Apache access logs
docker compose -f docker/docker-compose.prod.yml logs web | grep '\[apache_access\]' > apache_access.log

# Extract PHP application logs
docker compose -f docker/docker-compose.prod.yml logs web | grep -E '^\{"ts":' > app.log
```

## Best Practices

1. **Use structured logging** - All application logs use JSON format for easy parsing
2. **Filter at source** - Use `grep` and `jq` to filter logs before processing
3. **Monitor error rates** - Set up alerts for high error rates
4. **Separate concerns** - Access logs for traffic analysis, application logs for debugging
5. **Use log aggregation** - For production, consider tools like ELK, Loki, or CloudWatch

## Log Rotation

Docker automatically rotates logs based on configuration:
- **Max size**: 10MB per log file
- **Max files**: 10 files per container
- **Total**: ~100MB per container

Logs are stored in:
- **Linux**: `/var/lib/docker/containers/<container-id>/<container-id>-json.log`
- **macOS/Windows**: Docker Desktop manages log storage

## Performance Considerations

For large log volumes:
- Use `tail -f` instead of `docker compose logs -f` for real-time monitoring
- Filter before processing: `docker compose logs | grep pattern | jq ...`
- Use log aggregation tools for production environments
- Consider log sampling for high-volume access logs

## Related Documentation

- [Deployment Guide](DEPLOYMENT.md) - Production deployment
- [Architecture](ARCHITECTURE.md) - System design
- [Configuration Guide](CONFIGURATION.md) - Airport configuration

