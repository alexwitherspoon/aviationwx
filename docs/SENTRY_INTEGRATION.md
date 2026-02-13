# Sentry Integration

## Overview

AviationWX uses [Sentry.io](https://sentry.io) for production error tracking, performance monitoring, and operational metrics. This document describes the integration architecture, configuration, and best practices.

## Architecture

### Deployment Model

**Backend: PHP SDK in Docker**

The Sentry PHP SDK runs inside the Docker web container, initialized early in the bootstrap process. This provides:

- **Automatic error capture** for all PHP errors/exceptions
- **Performance tracing** for critical operations (weather/webcam fetching, metrics aggregation)
- **Custom metrics** for system health (APCu memory, disk space, aggregation issues)
- **Service tagging** for filtering by component (web, scheduler, workers)
- **Cron monitoring** for background jobs (heartbeat, health checks, cleanup)

**Frontend: JavaScript SDK via CDN**

The Sentry Browser SDK is loaded from Sentry's CDN and initialized on all public pages. This provides:

- **Automatic error capture** for JavaScript exceptions and promise rejections
- **Performance monitoring** for page loads, navigation, and AJAX calls
- **Service Worker error tracking** for offline functionality issues
- **User context** with privacy-conscious hashed IP addresses
- **Airport tagging** for filtering errors by specific airport dashboards

Both SDKs share the same DSN and environment configuration, providing a unified view of frontend and backend errors in the Sentry dashboard.

### Configuration

Sentry is configured via environment variables loaded from `/home/aviationwx/.env.production` on the production host:

| Variable | Purpose | Example |
|----------|---------|---------|
| `SENTRY_DSN` | Sentry project DSN (secret) | `https://...@sentry.io/...` |
| `SENTRY_ENVIRONMENT` | Environment tag | `production` |
| `SENTRY_SAMPLE_RATE_ERRORS` | Error sampling (0.0-1.0) | `1.0` (100%) |
| `SENTRY_SAMPLE_RATE_TRACES` | Trace sampling (0.0-1.0) | `0.05` (5%) |
| `SENTRY_RELEASE` | Git SHA for release tracking | `abc1234` |

**Secret Management:**
- `SENTRY_DSN` is stored as a GitHub Actions secret
- During deployment, the workflow SSH's to the host and creates `/home/aviationwx/.env.production`
- Docker Compose mounts this file via `env_file` directive
- File permissions: `0600` (owner read/write only)

### Initialization

Sentry is initialized in `lib/sentry.php`, which is loaded early in:
- `index.php` (web requests)
- `scripts/scheduler.php` (scheduler daemon)
- `scripts/fetch-weather.php` (weather workers)
- `scripts/unified-webcam-worker.php` (webcam workers)

Initialization is automatic and silent:
- Only activates in production with valid DSN
- No initialization in test mode (`APP_ENV=testing`)
- Failures to initialize don't break the app

## Integration Points

### 1. Error Logging (`lib/logger.php`)

All calls to `aviationwx_log()` with severity `error`, `critical`, `alert`, or `emergency` automatically send events to Sentry:

```php
aviationwx_log('error', 'weather refresh failed', [
    'airport' => 'kspb',
    'http_code' => 500
], 'app');
// → Sent to Sentry with tags: log_type=app, airport_id=kspb
```

**What's sent:**
- Log message as event message
- Full context as additional data
- Tags: `log_type`, `log_source`, `airport_id`, `weather_source`
- Breadcrumbs for context trail

**What's NOT sent:**
- `info` and `debug` level events (local logs only)
- Events are filtered by `before_send` hook

### 2. Performance Tracing

Critical operations are wrapped in Sentry transactions for performance monitoring:

```php
$transaction = sentryStartTransaction('worker.weather', "fetch_weather_kspb", [
    'airport_id' => 'kspb',
    'trigger' => 'scheduler',
]);

// ... perform operation ...

sentryFinishTransaction($transaction);
```

**Traced operations:**
- Daily metrics aggregation (`metrics.aggregate`)
- Weekly metrics aggregation (`metrics.aggregate`)
- Weather fetching (`worker.weather`)
- Webcam fetching (`worker.webcam`)

**Sampling:**
- Only 5% of traces are sent (configurable via `SENTRY_SAMPLE_RATE_TRACES`)
- Reduces overhead and Sentry quota usage

### 3. Custom Metrics (`lib/metrics.php`)

The scheduler calls `metrics_report_to_sentry()` every 5 minutes to report:

**APCu Memory Pressure:**
```
Severity: warning (>80%), error (>90%)
Event: "APCu memory pressure: 91% used"
Context: used_bytes, total_bytes, used_percent, fragmentation
```

**Disk Space Warnings:**
```
Severity: warning (>90%), critical (>95%)
Event: "Disk space critical: 96% used (1.2GB free)"
Context: free_bytes, used_percent, is_low, is_critical
```

**Aggregation Issues:**
```
Severity: warning
Event: "Suspiciously low metrics for 2026-02-10: 3 total views"
Context: date, total_views
```

**Image Processing Performance:**
```
Severity: warning (p95 > 2000ms)
Event: "Slow image processing: p95=2456ms (threshold: 2000ms)"
Context: avg_ms, p50_ms, p95_ms, sample_count, last_hour_count
```

**Page Render Performance:**
```
Severity: warning (p95 > 500ms)
Event: "Slow page renders: p95=678ms (threshold: 500ms)"
Context: avg_ms, p50_ms, p95_ms, sample_count, last_hour_count
```

**Circuit Breaker Status:**
```
Severity: warning (>10% airports with open breakers)
Event: "Multiple weather circuit breakers open: 15.2% of airports"
Context: open_count, open_percent, airports (first 10)
```

**Variant Health (Image Pipeline):**
```
Severity: warning (success rate < 90%)
Event: "Low image variant generation success rate: 87.3%"
Context: success, failures, success_rate
```

**Scheduler Health:**
```
Severity: error (unhealthy status)
Event: "Scheduler unhealthy: degraded - High memory usage"
Context: health, last_error, loop_count, uptime, pid

Severity: warning (slow loops > 10s)
Event: "Slow scheduler loops: 12.5s per iteration (threshold: 10s)"
Context: loop_duration_seconds, loop_count, uptime
```

### 4. Service Context

Each service sets its context on initialization:

```php
// Scheduler
sentrySetServiceContext('scheduler', ['process' => 'daemon']);

// Weather worker
sentrySetServiceContext('worker-weather', ['airport_id' => 'kspb']);

// Webcam worker
sentrySetServiceContext('worker-webcam', [
    'airport_id' => 'kspb',
    'camera_index' => 0,
]);
```

This enables filtering in Sentry dashboard by service type and airport.

### 5. JavaScript SDK Integration

All public-facing pages load the Sentry Browser SDK from CDN for frontend error tracking.

**Integrated Pages:**
- Airport dashboard (`pages/airport.php`)
- Homepage (`pages/homepage.php`)
- Airport directory (`pages/airports.php`)
- Status page (`pages/status.php`)

**Features:**
- Automatic error capture (uncaught exceptions, promise rejections)
- Performance monitoring (page load, navigation, XHR/fetch)
- Service Worker error tracking
- User context with hashed IP (privacy-conscious)
- Airport tagging for dashboard pages

**SDK Loading:**
```html
<script src="https://browser.sentry-cdn.com/8.47.0/bundle.min.js" 
        integrity="sha384-..." crossorigin="anonymous"></script>
```

**Configuration:**
```javascript
Sentry.init({
    dsn: "...",
    environment: "production",
    release: "git-sha",
    tracesSampleRate: 0.05,  // 5% performance traces
    
    // Filter out browser extensions and known noise
    ignoreErrors: [
        'chrome-extension://',
        'moz-extension://',
        'NetworkError',
        'Failed to fetch',
    ],
});
```

**Privacy:**
- User ID: Hashed IP address (SHA-256, first 16 chars)
- No PII collected (no usernames, emails, session data)
- Full URLs retained (essential for debugging routing)
- User-Agent retained (essential for browser compatibility)

**What's Tracked:**
- ✅ JavaScript errors (uncaught exceptions)
- ✅ Promise rejections (async errors)
- ✅ Service Worker errors (offline functionality)
- ✅ AJAX failures (fetch/XHR errors)
- ✅ Page load performance (5% sampled)
- ✅ Navigation timing (SPA-style transitions)
- ❌ Session replays (disabled - high quota usage)

### 6. Cron Monitoring

A single cron monitor captures overall system health:

**Scheduler Health Check (`scheduler-health-check.php`):**
```
Schedule: * * * * * (every minute)
Monitor: scheduler-health-check
Max Runtime: 2 minutes
Grace Period: 2 minutes
```

The scheduler health check runs every minute, validates the scheduler daemon is running and healthy, and restarts it if needed. This single monitor provides the best signal for system health since the scheduler drives all weather and webcam updates.

**What Sentry Tracks:**
- ✅ Check-in received (job executed)
- ✅ Execution duration
- ✅ Success/failure status
- ✅ Missed executions (alerts if job doesn't run)
- ✅ Long-running jobs (alerts if exceeds max runtime)

**Alerting:**
Sentry automatically alerts when:
- Job doesn't execute within grace period
- Job runs longer than max runtime
- Job reports error status

## Privacy & Data Handling

### What's Collected

AviationWX is a public service with minimal PII. Sentry collects:

- **IP addresses** - Essential for debugging geographic/network issues
- **User-Agent strings** - Essential for debugging browser compatibility
- **Full URLs** - Essential for debugging request routing
- **Error context** - Airport IDs, weather sources, camera indices
- **Performance traces** - Operation timing and success/failure

### What's NOT Scrubbed

The `before_send` hook does **not** scrub IP addresses, User-Agent strings, or URLs. These are operational data essential for debugging and monitoring a public service.

### Data Retention

Sentry stores **error events** (exceptions, `aviationwx_log` errors, `captureMessage` at error severity), not application logs. Local logs remain in `/var/log/aviationwx/` and are not sent to Sentry.

Sentry automatically ages out data based on your organization's plan:
- Free plan: 30 days
- Team plan: 90 days
- Business plan: Custom

No manual data deletion is implemented.

## Monitoring & Alerting

### Cron Monitors

Sentry automatically creates cron monitors for all check-ins. View them at:
**Crons → Monitors** in your Sentry project dashboard.

**Configured Monitors:**
1. **scheduler-health-check** - Validates scheduler daemon health (every minute)

**Default Alerts:**
Sentry automatically alerts (via email) when:
- Monitor misses a check-in (grace period exceeded)
- Monitor exceeds max runtime
- Monitor reports error status

**Customizing Alerts:**
1. Go to **Crons → Monitors** in Sentry dashboard
2. Click a monitor name
3. Configure alert rules and notification channels

### Recommended Alerts

Configure alerts in Sentry dashboard for:

1. **High Error Rate**
   - Trigger: >10 errors/hour
   - Notification: Email, Slack
   - Action: Investigate logs, check external APIs

2. **APCu Memory Pressure**
   - Trigger: "APCu memory pressure" event
   - Notification: Email
   - Action: Restart container, increase APCu memory limit

3. **Disk Space Critical**
   - Trigger: "Disk space critical" event
   - Notification: Email, SMS (critical)
   - Action: Clean up old files, expand volume

4. **Slow Aggregations**
   - Trigger: `metrics.aggregate` transaction >10s
   - Notification: Email
   - Action: Check for missing hourly files, disk I/O issues

### Dashboard Views

Create custom dashboards for:
- Error rate by service (`service` tag)
- Error rate by airport (`airport_id` tag)
- Weather source reliability (`weather_source` tag)
- Aggregation performance (transaction duration)
- APCu memory trends (custom context)

## Environments

Sentry supports multiple environments with separate event streams:

### Production Environment
- **When:** `APP_ENV=production` with valid `SENTRY_DSN`
- **Tag:** `environment=production`
- **Purpose:** Real production errors and metrics
- **Data:** All production traffic

### CI Environment  
- **When:** GitHub Actions with valid `SENTRY_DSN`
- **Tag:** `environment=ci`
- **Purpose:** Validate Sentry integration in CI before deployment
- **Data:** Test events from CI runs (low volume)

**Benefits of CI Environment:**
- Catches Sentry integration bugs before production
- Validates SDK API usage (transaction creation, tagging, etc.)
- Separate from production events in Sentry dashboard
- The `setTag()` production bug would have been caught by this

### Local Development
- **When:** Local dev, test mode, no DSN configured
- **Behavior:** Sentry disabled (silent skip)
- **Purpose:** Fast local development, no pollution of production data

**Environment Separation in Sentry Dashboard:**
- Filter by environment tag to see only prod or CI events
- Set up separate alert rules for each environment
- CI events help validate integration, prod events track real issues

## Testing

The integration is automatically tested via existing tests:
- `lib/sentry.php` initialization is called but no-ops in test mode
- `aviationwx_log()` calls work normally (Sentry just isn't initialized)
- No Sentry-specific unit tests required

## Deployment Checklist

1. **GitHub Secrets:**
   - Add `SENTRY_DSN` to repository secrets
   - Get DSN from Sentry project settings

2. **First Deployment:**
   - Workflow creates `/home/aviationwx/.env.production` on host
   - Docker Compose loads environment variables
   - Container restarts with Sentry active

3. **Verification:**
   - Check Sentry dashboard for incoming events
   - Trigger a test error: `docker exec aviationwx-web php -r "trigger_error('Test Sentry');"`
   - Verify event appears in Sentry within 1 minute
   - Check **Crons → Monitors** for cron jobs (appears after first execution)

4. **Configure Alerts:**
   - Set up alert rules in Sentry dashboard
   - Test notifications (Sentry has built-in test alerts)

## Troubleshooting

### No Events in Sentry

1. Check DSN is set: `docker exec aviationwx-web printenv SENTRY_DSN`
2. Check initialization: `docker exec aviationwx-web grep "SENTRY_INITIALIZED" /var/www/html/lib/sentry.php`
3. Check logs: `docker exec aviationwx-web tail -100 /var/log/aviationwx/app.log | grep -i sentry`

### High Quota Usage

1. Reduce error sampling: Set `SENTRY_SAMPLE_RATE_ERRORS=0.5` (50%)
2. Reduce trace sampling: Set `SENTRY_SAMPLE_RATE_TRACES=0.01` (1%)
3. Add more `before_send` filters for noisy errors

### Performance Impact

Sentry overhead is minimal:
- Errors: <1ms per event (async)
- Traces: ~2-5ms per transaction (5% sampled)
- Health checks: ~10ms every 5 minutes

If performance degrades:
1. Reduce trace sampling to 1%
2. Disable tracing entirely: `SENTRY_SAMPLE_RATE_TRACES=0`
3. Keep error tracking active (critical for monitoring)

## Future Enhancements

Potential improvements (not currently implemented):

1. **User Feedback:**
   - Capture user-reported issues via Sentry widget
   - Link error IDs to user reports

2. **Release Health:**
   - Track crash-free sessions per release
   - Automatic rollback on high crash rate

3. **Webhook Integrations:**
   - Auto-create GitHub issues for critical errors
   - Post to Slack on deployment success/failure

4. **Advanced Tracing:**
   - Trace full HTTP request lifecycle
   - Trace database queries (when DB is added)
   - Distributed tracing across services

## References

- [Sentry PHP SDK Documentation](https://docs.sentry.io/platforms/php/)
- [Performance Monitoring Guide](https://docs.sentry.io/product/performance/)
- [Custom Context](https://docs.sentry.io/platforms/php/enriching-events/context/)
- [Sampling](https://docs.sentry.io/platforms/php/configuration/sampling/)
