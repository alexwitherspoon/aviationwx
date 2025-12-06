# Log Filtering Guide

This guide explains how to filter and parse Docker logs to separate access logs from application logs.

## Configuration

To enable JSON-formatted Nginx access logs, the `docker/nginx-main.conf` file must be mounted as `/etc/nginx/nginx.conf` in the nginx container. This is already configured in `docker-compose.prod.yml`.

## Log Sources

The application generates logs from multiple sources:

1. **Nginx Access Logs** - JSON format, source: `nginx_access`
2. **Nginx Error Logs** - Plain text, warnings and errors
3. **Apache Access Logs** - Plain text, prefixed with `[apache_access]`
4. **Apache Error Logs** - Plain text, PHP errors and warnings
5. **PHP Application Logs** - JSONL format, source: `app`, `web`, or `cli`

## Log Formats

### Nginx Access Logs (JSON)
```json
{"time":"2024-01-01T12:00:00+00:00","remote_addr":"1.2.3.4","request":"GET / HTTP/1.1","request_method":"GET","request_uri":"/","status":200,"body_bytes_sent":1234,"http_referer":"","http_user_agent":"Mozilla/5.0...","http_x_forwarded_for":"","request_time":0.001,"upstream_response_time":"0.001","source":"nginx_access"}
```

### PHP Application Logs (JSONL)
```json
{"ts":"2024-01-01T12:00:00+00:00","level":"info","request_id":"abc123","message":"Weather data fetched","context":{"airport":"KSPB","source":"web"},"log_type":"app","source":"web"}
```

### Apache Access Logs (Plain Text)
```
[apache_access] 1.2.3.4 - - [01/Jan/2024:12:00:00 +0000] "GET / HTTP/1.1" 200 1234 "-" "Mozilla/5.0..."
```

## Filtering Logs

### View All Logs
```bash
docker compose logs -f
```

### View Logs by Container
```bash
# Web application logs
docker compose logs -f web

# Nginx logs
docker compose logs -f nginx

# VPN server logs
docker compose logs -f vpn-server
```

### Filter Access Logs Only

#### Nginx Access Logs (JSON)
```bash
# Extract only Nginx access logs
docker compose logs nginx | grep -o '{"source":"nginx_access"[^}]*}'

# Pretty print JSON access logs
docker compose logs nginx | grep -o '{"source":"nginx_access"[^}]*}' | jq .

# Filter by status code
docker compose logs nginx | grep -o '{"source":"nginx_access"[^}]*}' | jq 'select(.status >= 400)'

# Filter by request URI
docker compose logs nginx | grep -o '{"source":"nginx_access"[^}]*}' | jq 'select(.request_uri | contains("/api"))'
```

#### Apache Access Logs (Plain Text)
```bash
# Extract only Apache access logs
docker compose logs web | grep '\[apache_access\]'

# Filter by status code
docker compose logs web | grep '\[apache_access\]' | grep ' 4[0-9][0-9] \| 5[0-9][0-9] '

# Filter by request path
docker compose logs web | grep '\[apache_access\]' | grep '/api/'
```

### Filter Application Logs Only

#### PHP Application Logs (JSONL)
```bash
# Extract only PHP application logs (JSON format)
docker compose logs web | grep -E '^\{"ts":' | jq .

# Filter by log level
docker compose logs web | grep -E '^\{"ts":' | jq 'select(.level == "error")'

# Filter by source
docker compose logs web | grep -E '^\{"ts":' | jq 'select(.source == "web")'

# Filter by log type
docker compose logs web | grep -E '^\{"ts":' | jq 'select(.log_type == "app")'

# Filter errors and warnings
docker compose logs web | grep -E '^\{"ts":' | jq 'select(.level == "error" or .level == "warning")'
```

### Filter Error Logs

```bash
# All errors (Nginx, Apache, PHP)
docker compose logs | grep -i error

# PHP errors only
docker compose logs web | grep -E '^\{"ts":' | jq 'select(.level == "error")'

# Nginx errors only
docker compose logs nginx | grep -v '{"source":"nginx_access"'
```

### Advanced Filtering with jq

```bash
# Count requests by status code
docker compose logs nginx | grep -o '{"source":"nginx_access"[^}]*}' | jq -s 'group_by(.status) | map({status: .[0].status, count: length})'

# Top requested URIs
docker compose logs nginx | grep -o '{"source":"nginx_access"[^}]*}' | jq -r '.request_uri' | sort | uniq -c | sort -rn | head -10

# Error rate over time
docker compose logs web | grep -E '^\{"ts":' | jq 'select(.level == "error") | .ts' | cut -d'T' -f1 | uniq -c

# Requests taking longer than 1 second
docker compose logs nginx | grep -o '{"source":"nginx_access"[^}]*}' | jq 'select(.request_time > 1.0)'
```

## Log Parsing Scripts

### Extract Access Logs to File
```bash
#!/bin/bash
# Extract Nginx access logs
docker compose logs nginx | grep -o '{"source":"nginx_access"[^}]*}' > nginx_access.log

# Extract Apache access logs
docker compose logs web | grep '\[apache_access\]' > apache_access.log

# Extract PHP application logs
docker compose logs web | grep -E '^\{"ts":' > app.log
```

### Parse JSON Logs
```bash
#!/bin/bash
# Parse and analyze PHP application logs
docker compose logs web | grep -E '^\{"ts":' | jq -r '
  select(.level == "error") |
  "\(.ts) [\(.level)] \(.message) \(.context // {})"
'
```

## Log Rotation

Docker automatically rotates logs based on the configuration in `docker-compose.yml`:
- Max size: 10MB per log file
- Max files: 10 files per container
- Total: ~100MB per container

Logs are stored in:
- Linux: `/var/lib/docker/containers/<container-id>/<container-id>-json.log`
- macOS/Windows: Docker Desktop manages log storage

## Best Practices

1. **Use structured logging** - All application logs use JSON format for easy parsing
2. **Filter at source** - Use `grep` and `jq` to filter logs before processing
3. **Monitor error rates** - Set up alerts for high error rates
4. **Separate concerns** - Access logs for traffic analysis, application logs for debugging
5. **Use log aggregation** - For production, consider tools like ELK, Loki, or CloudWatch

## Troubleshooting

### Too many access logs
If access logs are too noisy, you can:
1. Reduce Nginx log level: `error_log /dev/stderr warn;` (already configured)
2. Filter health check requests: `grep -v '/health.php\|/ready.php'`
3. Use log sampling in Nginx (not currently configured)

### Parsing JSON logs
If `jq` is not available:
```bash
# Install jq
# macOS: brew install jq
# Linux: apt-get install jq or yum install jq
```

### Performance
For large log volumes, consider:
- Using `tail -f` instead of `docker compose logs -f` for real-time monitoring
- Filtering before processing: `docker compose logs | grep pattern | jq ...`
- Using log aggregation tools for production environments

