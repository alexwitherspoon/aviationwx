# Config Hotload Mechanism

## Overview

The application supports automatic reloading of `airports.json` configuration changes without requiring a Docker container restart. However, there are some limitations and best practices to understand.

## How It Works

### 1. APCu Cache (Web Requests)

**Mechanism:**
- Web requests use `loadConfig(true)` which checks APCu cache
- Cache key: `aviationwx_config` (config data)
- Cache key: `aviationwx_config_mtime` (file modification time)
- When file mtime changes, APCu cache is automatically invalidated
- Shared across all PHP-FPM workers

**Cache TTL:** 1 hour (but invalidated immediately on file change)

**Detection:** Compares cached mtime with current file mtime on every request

### 2. Scheduler Reload

**Mechanism:**
- Scheduler daemon checks for config changes every `scheduler_config_reload_seconds` (default: 60s)
- Calls `loadConfig(false)` which bypasses APCu but checks mtime
- When config change is detected, scheduler:
  - Clears APCu cache (so web requests pick up changes immediately)
  - Reloads config
  - Reinitializes ProcessPools with new config

**Detection:** Tracks file mtime and compares on each check interval

## Limitations

### 1. Scheduler Delay (Up to 60 seconds)

**Issue:** Scheduler only checks every 60 seconds (configurable via `scheduler_config_reload_seconds`)

**Impact:** Config changes may not be detected for up to 60 seconds

**Workaround:** 
- Manually clear APCu cache: Visit `/admin/cache-clear.php`
- Or wait for scheduler to detect change (up to 60s)

### 2. File Modification Time (mtime)

**Issue:** Config hotload relies on file modification time (mtime) to detect changes

**When mtime might not update:**
- File edited in place (depends on filesystem/editor)
- File copied/moved (mtime might be preserved)
- File edited via certain editors that preserve mtime

**Workaround:**
- After editing config, run: `touch /path/to/airports.json` to update mtime
- Or manually clear cache: `/admin/cache-clear.php`

### 3. PHP-FPM Static Cache

**Issue:** Each PHP-FPM worker has its own static cache (per-request lifetime)

**Impact:** Minimal - static cache is per-request and checks mtime

**Note:** APCu cache is shared across all workers, so this is not a major issue

## Best Practices

### Immediate Reload (Recommended)

After updating `airports.json`:

1. **Update mtime** (if needed):
   ```bash
   touch /path/to/airports.json
   ```

2. **Clear APCu cache**:
   - Visit: `http://localhost:8080/admin/cache-clear.php`
   - Or use curl: `curl http://localhost:8080/admin/cache-clear.php`

3. **Verify reload**:
   - Check scheduler logs for "config reloaded" message
   - Visit diagnostic page: `/admin/config-hotload-diagnostic.php`

### Automatic Reload (Wait for Scheduler)

If you don't need immediate reload:

1. **Just wait** - Scheduler will detect change within 60 seconds (default)
2. **Check scheduler status** - View `/admin/diagnostics.php` or scheduler lock file

### Configuration

Adjust reload interval in `airports.json`:

```json
{
  "config": {
    "scheduler_config_reload_seconds": 30  // Check every 30s instead of 60s
  }
}
```

**Note:** Lower values increase scheduler overhead but reduce delay

## Troubleshooting

### Config Changes Not Detected

1. **Check file mtime:**
   ```bash
   stat /path/to/airports.json
   ```

2. **Check APCu cache status:**
   - Visit: `/admin/config-hotload-diagnostic.php`
   - Look for mtime mismatch warnings

3. **Check scheduler status:**
   ```bash
   cat /tmp/scheduler.lock
   ```
   - Verify `config_last_reload` timestamp
   - Verify scheduler is running

4. **Manual cache clear:**
   - Visit: `/admin/cache-clear.php`
   - This forces immediate reload on next request

### Scheduler Not Running

If scheduler is not running, config changes won't be detected automatically:

1. **Check if running:**
   ```bash
   ps aux | grep scheduler.php
   ```

2. **Start scheduler:**
   ```bash
   nohup php scripts/scheduler.php > /dev/null 2>&1 &
   ```

3. **Or use health check script:**
   - Cron job should auto-restart: `scripts/scheduler-health-check.php`

## Diagnostic Tools

### `/admin/config-hotload-diagnostic.php`

Comprehensive diagnostic tool that shows:
- Config file path and mtime
- APCu cache status and mtime
- Scheduler status and last reload time
- Config change detection status
- Recommendations for fixing issues

### `/admin/cache-clear.php`

Manually clear APCu cache to force immediate reload.

### `/admin/diagnostics.php`

General diagnostics including config cache status.

## Technical Details

### Cache Invalidation Flow

1. **File Updated** → mtime changes
2. **Next Web Request** → `loadConfig()` checks mtime
3. **mtime Mismatch** → APCu cache cleared, config reloaded
4. **Scheduler Check** (every 60s) → Detects mtime change
5. **Scheduler** → Clears APCu cache, reloads config, reinitializes pools

### Why Docker Restart Works

Docker restart clears:
- All APCu cache (shared memory cleared)
- All PHP-FPM static caches (processes restarted)
- Scheduler process (restarts with fresh config)

This is why restart always works, but it's not necessary if hotload is working correctly.

## Summary

**Hotload works automatically** but has a delay (up to 60s). For immediate reload:
1. Update config file
2. Ensure mtime is updated (`touch` if needed)
3. Clear APCu cache via `/admin/cache-clear.php`

**No Docker restart needed** if hotload is working correctly.

