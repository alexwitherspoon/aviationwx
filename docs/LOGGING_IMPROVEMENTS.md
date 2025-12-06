# Logging Improvements Summary

This document summarizes the improvements made to make Docker logs easier to parse and filter.

## Problem

Docker logs were noisy and difficult to parse because:
- Access logs and application logs were mixed together
- Different log formats (JSON, plain text) made parsing difficult
- No easy way to identify log sources
- Hard to filter access logs from internal application logs

## Solutions Implemented

### 1. Structured Logging with Source Identification

**PHP Application Logs** (`lib/logger.php`):
- Added `source` field to all log entries (`app`, `web`, or `cli`)
- Logs are already in JSONL format for easy parsing
- Logs include `log_type` field to distinguish user activity from system messages

**Example:**
```json
{"ts":"2024-01-01T12:00:00+00:00","level":"info","request_id":"abc123","message":"Weather fetched","context":{},"log_type":"app","source":"web"}
```

### 2. JSON-Formatted Nginx Access Logs

**Nginx Configuration** (`docker/nginx-main.conf` + `docker/nginx.conf`):
- Created custom `json_access` log format in `http` context
- All Nginx access logs now output as JSON with `source: "nginx_access"` field
- Makes it easy to filter and parse access logs separately

**Example:**
```json
{"time":"2024-01-01T12:00:00+00:00","remote_addr":"1.2.3.4","request":"GET / HTTP/1.1","status":200,"source":"nginx_access"}
```

### 3. Apache Access Log Prefixing

**Apache Configuration** (`docker/Dockerfile`):
- Added `[apache_access]` prefix to Apache access logs
- Makes it easy to identify and filter Apache access logs using `grep`

**Example:**
```
[apache_access] 1.2.3.4 - - [01/Jan/2024:12:00:00 +0000] "GET / HTTP/1.1" 200 1234
```

### 4. Docker Log Tags

**Docker Compose** (`docker/docker-compose.yml`, `docker/docker-compose.prod.yml`):
- Added log tags to all services: `tag: "{{.Name}}/{{.ID}}"`
- Makes it easier to identify which container generated each log entry

### 5. Log Filtering Utilities

**Helper Script** (`scripts/filter-logs.sh`):
- Created utility script to easily filter logs by type
- Supports filtering by: `access`, `app`, `error`, `nginx`, `apache`, `all`
- Automatically detects and uses `jq` for JSON parsing when available

**Usage:**
```bash
./scripts/filter-logs.sh access    # Show all access logs
./scripts/filter-logs.sh app       # Show application logs
./scripts/filter-logs.sh error     # Show error logs
./scripts/filter-logs.sh nginx     # Show Nginx logs
```

**Documentation** (`docs/LOG_FILTERING.md`):
- Comprehensive guide on filtering and parsing logs
- Examples for common filtering scenarios
- Advanced filtering with `jq` for JSON logs

## Benefits

1. **Easy Filtering**: Access logs can be easily separated from application logs
2. **Structured Data**: JSON format makes parsing and analysis straightforward
3. **Source Identification**: Every log entry identifies its source
4. **Better Debugging**: Easier to trace issues by filtering specific log types
5. **Performance Monitoring**: JSON access logs make it easy to analyze request patterns

## Usage Examples

### Filter Access Logs Only
```bash
# Nginx access logs (JSON)
docker compose logs nginx | grep -o '{"source":"nginx_access"[^}]*}' | jq .

# Apache access logs (plain text)
docker compose logs web | grep '\[apache_access\]'
```

### Filter Application Logs Only
```bash
# PHP application logs (JSON)
docker compose logs web | grep -E '^\{"ts":' | jq 'select(.source == "web")'
```

### Filter Errors Only
```bash
# All errors
docker compose logs | grep -i error

# PHP errors only
docker compose logs web | grep -E '^\{"ts":' | jq 'select(.level == "error")'
```

### Use Helper Script
```bash
./scripts/filter-logs.sh access    # All access logs
./scripts/filter-logs.sh app web   # Application logs from web container
./scripts/filter-logs.sh error     # All error logs
```

## Files Changed

1. `lib/logger.php` - Added `source` field to log entries
2. `docker/nginx-main.conf` - New file with JSON log format definition
3. `docker/nginx.conf` - Updated to use `json_access` format
4. `docker/Dockerfile` - Added prefix to Apache access logs
5. `docker/docker-compose.yml` - Added log tags
6. `docker/docker-compose.prod.yml` - Added log tags and nginx-main.conf mount
7. `scripts/filter-logs.sh` - New utility script
8. `docs/LOG_FILTERING.md` - New documentation
9. `docs/LOGGING_IMPROVEMENTS.md` - This file

## Migration Notes

- **No breaking changes**: All changes are backward compatible
- **Nginx JSON logs**: Requires `docker/nginx-main.conf` to be mounted (already configured in prod)
- **jq recommended**: For best experience, install `jq` for JSON parsing (`brew install jq` or `apt-get install jq`)

## Future Improvements

Potential enhancements for the future:
1. Log aggregation service (ELK, Loki, CloudWatch)
2. Real-time log streaming with filtering
3. Log sampling for high-volume access logs
4. Automated alerting based on error patterns
5. Log retention policies per log type

