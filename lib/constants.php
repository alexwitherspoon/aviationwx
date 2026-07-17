<?php
/**
 * Application Constants
 * Centralized constants for timeouts, limits, and configuration values
 */

// Default refresh intervals (seconds)
if (!defined('DEFAULT_WEBCAM_REFRESH')) {
    define('DEFAULT_WEBCAM_REFRESH', 60);
}
// Webcam refresh rate bounds (used by scheduler for rate limiting)
// MIN: Prevent excessive resource usage / DoS from misconfiguration
// MAX: Ensure cameras are refreshed at least occasionally
if (!defined('MIN_WEBCAM_REFRESH')) {
    define('MIN_WEBCAM_REFRESH', 10); // 10 seconds minimum (fastest allowed)
}
if (!defined('MAX_WEBCAM_REFRESH')) {
    define('MAX_WEBCAM_REFRESH', 3600); // 1 hour maximum (slowest allowed)
}

// When two or more unique frame timestamps exist, use second-newest only if the top two are within
// this gap (same upload burst / variant generation). If the gap is larger, they are independent
// captures and the newest timestamp is treated as last completed (avoids sparse two-frame false staleness).
if (!defined('WEBCAM_LAST_COMPLETED_PAIR_MAX_GAP_SECONDS')) {
    define('WEBCAM_LAST_COMPLETED_PAIR_MAX_GAP_SECONDS', 600); // 10 minutes
}

if (!defined('DEFAULT_WEATHER_REFRESH')) {
    define('DEFAULT_WEATHER_REFRESH', 60);
}

// Internal api/weather.php JSON `error` when the airport has no `weather_sources`.
// scripts/fetch-weather.php must recognize the same value with HTTP 503 so cron workers do not fail closed.
if (!defined('WEATHER_INTERNAL_API_ERROR_SOURCE_NOT_CONFIGURED')) {
    define('WEATHER_INTERNAL_API_ERROR_SOURCE_NOT_CONFIGURED', 'Weather source not configured');
}

// RTSP/ffmpeg timeouts
if (!defined('RTSP_DEFAULT_TIMEOUT')) {
    define('RTSP_DEFAULT_TIMEOUT', 10);
}
if (!defined('RTSP_MAX_RUNTIME')) {
    define('RTSP_MAX_RUNTIME', 6);
}
if (!defined('RTSP_DEFAULT_RETRIES')) {
    define('RTSP_DEFAULT_RETRIES', 2);
}
// Backoff delays (seconds) before each RTSP attempt
if (!defined('RTSP_BACKOFF_DELAYS')) {
    define('RTSP_BACKOFF_DELAYS', [1, 5, 10]);
}
if (!defined('DEFAULT_TRANSCODE_TIMEOUT')) {
    define('DEFAULT_TRANSCODE_TIMEOUT', 8);
}

// cURL timeouts
if (!defined('CURL_CONNECT_TIMEOUT')) {
    define('CURL_CONNECT_TIMEOUT', 5);
}
if (!defined('CURL_TIMEOUT')) {
    define('CURL_TIMEOUT', 10);
}
if (!defined('CURL_MULTI_OVERALL_TIMEOUT')) {
    define('CURL_MULTI_OVERALL_TIMEOUT', 15);
}

// =============================================================================
// STALENESS THRESHOLDS (3-Tier Model)
// =============================================================================
// Unified staleness model for webcams and weather data:
//   - Warning: Data is older than expected but still useful (yellow indicator)
//   - Error: Data is questionable, shown with strong warning (red indicator)
//   - Failclosed: Data too old to display, hidden from user (placeholder shown)
//
// These are built-in defaults. Can be overridden in airports.json at:
//   - Global level: config.stale_*_seconds
//   - Airport level: airport.stale_*_seconds (webcams/weather only)
//
// METAR and NOTAM have separate global-only thresholds due to their nature.

// --- General staleness (webcams, weather) ---
// Warning tier: Show yellow indicator, data still useful
if (!defined('DEFAULT_STALE_WARNING_SECONDS')) {
    define('DEFAULT_STALE_WARNING_SECONDS', 600); // 10 minutes
}
if (!defined('MIN_STALE_WARNING_SECONDS')) {
    define('MIN_STALE_WARNING_SECONDS', 300); // 5 minutes minimum
}

// Error tier: Show red indicator, data questionable but shown
if (!defined('DEFAULT_STALE_ERROR_SECONDS')) {
    define('DEFAULT_STALE_ERROR_SECONDS', 3600); // 60 minutes
}
if (!defined('MIN_STALE_ERROR_SECONDS')) {
    define('MIN_STALE_ERROR_SECONDS', 3600); // 60 minutes minimum
}

// Failclosed tier: Stop displaying data entirely (show placeholder)
if (!defined('DEFAULT_STALE_FAILCLOSED_SECONDS')) {
    define('DEFAULT_STALE_FAILCLOSED_SECONDS', 10800); // 3 hours
}
if (!defined('MIN_STALE_FAILCLOSED_SECONDS')) {
    define('MIN_STALE_FAILCLOSED_SECONDS', 3600); // 60 minutes minimum
}

// Limited-availability (off-grid/solar/battery) sites: show outage banner sooner than general failclosed
// Default 30 minutes - pilots need to know when local data is unavailable
if (!defined('DEFAULT_LIMITED_AVAILABILITY_OUTAGE_SECONDS')) {
    define('DEFAULT_LIMITED_AVAILABILITY_OUTAGE_SECONDS', 1800); // 30 minutes
}
if (!defined('MIN_LIMITED_AVAILABILITY_OUTAGE_SECONDS')) {
    define('MIN_LIMITED_AVAILABILITY_OUTAGE_SECONDS', 300); // 5 minutes minimum (config override)
}

// --- METAR-specific thresholds (global only) ---
// METAR is published hourly, so longer thresholds are appropriate
if (!defined('DEFAULT_METAR_STALE_WARNING_SECONDS')) {
    define('DEFAULT_METAR_STALE_WARNING_SECONDS', 3600); // 1 hour
}
if (!defined('DEFAULT_METAR_STALE_ERROR_SECONDS')) {
    define('DEFAULT_METAR_STALE_ERROR_SECONDS', 7200); // 2 hours
}
if (!defined('DEFAULT_METAR_STALE_FAILCLOSED_SECONDS')) {
    define('DEFAULT_METAR_STALE_FAILCLOSED_SECONDS', 10800); // 3 hours
}

// --- Stale-while-revalidate for background refresh ---
if (!defined('STALE_WHILE_REVALIDATE_SECONDS')) {
    define('STALE_WHILE_REVALIDATE_SECONDS', 300);
}

// --- Internal API cache TTLs for slow-changing endpoints ---
// NOTAM and station power responses may be shared briefly by browsers and
// the CDN. Their data changes far more slowly than weather: the upstream
// NMS refresh is hourly (NOTAM_CACHE_TTL_DEFAULT) and power samples drift
// over hours, so a one-minute shared window adds nothing meaningful to the
// pipeline latency that the upstream refresh and dashboard polls dominate.
// GET /api/notam-map.php uses the same NOTAM_* values for the airports map.
if (!defined('NOTAM_API_CACHE_TTL_SECONDS')) {
    define('NOTAM_API_CACHE_TTL_SECONDS', 60);
}
if (!defined('NOTAM_API_CACHE_SWR_SECONDS')) {
    define('NOTAM_API_CACHE_SWR_SECONDS', 120);
}
if (!defined('STATION_POWER_API_BROWSER_TTL_SECONDS')) {
    define('STATION_POWER_API_BROWSER_TTL_SECONDS', 30); // half the minimum dashboard poll (60s)
}
if (!defined('STATION_POWER_API_CDN_TTL_SECONDS')) {
    define('STATION_POWER_API_CDN_TTL_SECONDS', 60);
}

// Primary source recovery thresholds (for switching back from backup to primary)
// Both conditions must be met before switching back to primary source
if (!defined('PRIMARY_RECOVERY_CYCLES_THRESHOLD')) {
    define('PRIMARY_RECOVERY_CYCLES_THRESHOLD', 15); // Require 15 successful cycles before switching back
}
if (!defined('PRIMARY_RECOVERY_TIME_SECONDS')) {
    define('PRIMARY_RECOVERY_TIME_SECONDS', 900); // Require 15 minutes (900 seconds) before switching back
}

// Wind group merge tolerance (seconds)
// Maximum time difference allowed when merging wind data from multiple sources
// All merged wind fields must be within this tolerance window
if (!defined('WIND_GROUP_MERGE_TOLERANCE_SECONDS')) {
    define('WIND_GROUP_MERGE_TOLERANCE_SECONDS', 10); // 10 seconds tolerance for wind group merging
}

// Rate limiting defaults
if (!defined('RATE_LIMIT_WEATHER_MAX')) {
    // Increased from 60 to 120 to accommodate legitimate use cases:
    // - Multiple browser tabs (each makes requests)
    // - Page refreshes
    // - Stale data retry logic (5s + 30s delays)
    // - Normal refresh intervals (every 60s)
    // Server caches weather data, so this protects against abuse while allowing normal usage
    define('RATE_LIMIT_WEATHER_MAX', 120);
}
if (!defined('RATE_LIMIT_WEATHER_WINDOW')) {
    define('RATE_LIMIT_WEATHER_WINDOW', 60);
}
if (!defined('RATE_LIMIT_WEBCAM_MAX')) {
    define('RATE_LIMIT_WEBCAM_MAX', 100);
}
if (!defined('RATE_LIMIT_WEBCAM_WINDOW')) {
    define('RATE_LIMIT_WEBCAM_WINDOW', 60);
}

// NOTAM constants
if (!defined('NOTAM_REFRESH_DEFAULT')) {
    define('NOTAM_REFRESH_DEFAULT', 600); // 10 minutes
}
if (!defined('NOTAM_SCHEDULER_STAGGER_WINDOW_FRACTION')) {
    define('NOTAM_SCHEDULER_STAGGER_WINDOW_FRACTION', 10); // spread across interval/10
}
if (!defined('NOTAM_SCHEDULER_MAX_ENQUEUE_PER_LOOP')) {
    define('NOTAM_SCHEDULER_MAX_ENQUEUE_PER_LOOP', 1); // low urgency: one new fetch per scheduler tick
}
if (!defined('NOTAM_CACHE_TTL_DEFAULT')) {
    define('NOTAM_CACHE_TTL_DEFAULT', 3600); // 1 hour
}
if (!defined('NOTAM_TOKEN_EXPIRY_BUFFER')) {
    define('NOTAM_TOKEN_EXPIRY_BUFFER', 60); // Refresh token 1 min before expiry
}
if (!defined('DYACONLIVE_API_BASE_URL')) {
    define('DYACONLIVE_API_BASE_URL', 'https://api.dyacon.net');
}
if (!defined('DYACONLIVE_REPORT_INTERVAL_MINUTES')) {
    define('DYACONLIVE_REPORT_INTERVAL_MINUTES', 10);
}
if (!defined('DYACONLIVE_BUCKET_GRACE_SECONDS')) {
    define('DYACONLIVE_BUCKET_GRACE_SECONDS', 90);
}
if (!defined('DYACONLIVE_TOKEN_DEFAULT_TTL_SECONDS')) {
    define('DYACONLIVE_TOKEN_DEFAULT_TTL_SECONDS', 1800);
}
if (!defined('DYACONLIVE_TOKEN_EXPIRY_BUFFER')) {
    define('DYACONLIVE_TOKEN_EXPIRY_BUFFER', 60);
}
// NOTAM staleness thresholds (global only, 3-tier model)
if (!defined('DEFAULT_NOTAM_STALE_WARNING_SECONDS')) {
    define('DEFAULT_NOTAM_STALE_WARNING_SECONDS', 900); // 15 minutes
}
if (!defined('DEFAULT_NOTAM_STALE_ERROR_SECONDS')) {
    define('DEFAULT_NOTAM_STALE_ERROR_SECONDS', 1800); // 30 minutes
}
if (!defined('DEFAULT_NOTAM_STALE_FAILCLOSED_SECONDS')) {
    define('DEFAULT_NOTAM_STALE_FAILCLOSED_SECONDS', 3600); // 1 hour
}
if (!defined('NOTAM_GEO_QUERY_FEATURE')) {
    define('NOTAM_GEO_QUERY_FEATURE', 'AIRSPACE'); // NMS feature filter for geo TFR queries
}

if (!defined('NOTAM_GEO_RADIUS_DEFAULT')) {
    define('NOTAM_GEO_RADIUS_DEFAULT', 10); // 10 NM default radius for API query
}
if (!defined('NOTAM_RATE_LIMIT_REQUESTS_PER_MINUTE')) {
    define('NOTAM_RATE_LIMIT_REQUESTS_PER_MINUTE', 54); // client-side margin under the 60/min cap
}
if (!defined('NOTAM_RATE_LIMIT_POLL_MICROSECONDS')) {
    define('NOTAM_RATE_LIMIT_POLL_MICROSECONDS', 100_000); // 100ms while waiting for token
}
if (!defined('NOTAM_RATE_LIMIT_MAX_WAIT_SECONDS')) {
    define('NOTAM_RATE_LIMIT_MAX_WAIT_SECONDS', 30); // fail open after this wait
}
if (!defined('NOTAM_GLOBAL_BACKOFF_DEFAULT_SECONDS')) {
    define('NOTAM_GLOBAL_BACKOFF_DEFAULT_SECONDS', 60); // pause all NMS calls after 429 without Retry-After
}
if (!defined('NOTAM_429_RETRY_MAX_WAIT_SECONDS')) {
    define('NOTAM_429_RETRY_MAX_WAIT_SECONDS', 15); // cap in-fetch sleep before one 429 retry
}
// Banner: include upcoming_future NOTAMs whose first restriction window starts within this horizon
if (!defined('NOTAM_BANNER_UPCOMING_FUTURE_HORIZON_SECONDS')) {
    define('NOTAM_BANNER_UPCOMING_FUTURE_HORIZON_SECONDS', 48 * 3600); // 48 hours
}
// FAA NMS AIXM scenario for DOM runway closure events (no Q-code in payload)
if (!defined('NOTAM_FAA_SCENARIO_RUNWAY_CLOSURE')) {
    define('NOTAM_FAA_SCENARIO_RUNWAY_CLOSURE', '86');
}

if (!defined('NOTAM_FETCH_FAILURE_BACKOFF_SECONDS')) {
    define('NOTAM_FETCH_FAILURE_BACKOFF_SECONDS', 300); // 5 minutes between retries after NMS failure
}

// TFR (Temporary Flight Restriction) filtering constants (nautical miles).
// Default radius applies when the NOTAM body has no parseable NM radius.
// Edge buffer applies only to polygon rings in NOTAM TFR filtering, not to stated circle radii.

// Default radius (NM) to assume when TFR radius cannot be parsed from text
if (!defined('TFR_DEFAULT_RADIUS_NM')) {
    define('TFR_DEFAULT_RADIUS_NM', 30);
}

// Buffer (NM) for polygon TFRs only: airports outside the ring but within this distance
// of an edge still match. Parsed circle and legacy point-radius disks do not add this buffer.
if (!defined('TFR_RELEVANCE_BUFFER_NM')) {
    define('TFR_RELEVANCE_BUFFER_NM', 10);
}

// TFR radius parsing bounds (NM) - sanity check for parsed values
// Values outside this range are rejected as parsing errors
if (!defined('TFR_RADIUS_MIN_NM')) {
    define('TFR_RADIUS_MIN_NM', 0.5);
}
if (!defined('TFR_RADIUS_MAX_NM')) {
    define('TFR_RADIUS_MAX_NM', 100);
}
if (!defined('RATE_LIMIT_APCU_TTL_BUFFER')) {
    define('RATE_LIMIT_APCU_TTL_BUFFER', 10);
}

// File operations
if (!defined('FILE_LOCK_STALE_SECONDS')) {
    define('FILE_LOCK_STALE_SECONDS', 300); // 5 minutes
}
// Default max bytes when config.cache_file_max_size_mb is unset (see getCacheFileMaxSizeBytes() in config.php)
if (!defined('CACHE_FILE_MAX_SIZE')) {
    define('CACHE_FILE_MAX_SIZE', 25 * 1024 * 1024); // 25 MiB
}
if (!defined('MJPEG_MAX_SIZE')) {
    define('MJPEG_MAX_SIZE', 2097152); // 2MB
}
if (!defined('MJPEG_MAX_TIME')) {
    define('MJPEG_MAX_TIME', 8); // seconds
}

// Cache TTLs
if (!defined('CONFIG_CACHE_TTL')) {
    define('CONFIG_CACHE_TTL', 3600); // 1 hour
}
if (!defined('PLACEHOLDER_CACHE_TTL')) {
    define('PLACEHOLDER_CACHE_TTL', 60); // 1 minute - short TTL so browsers re-check quickly when webcam becomes available
}
if (!defined('PARTNER_LOGO_CACHE_TTL')) {
    define('PARTNER_LOGO_CACHE_TTL', 2592000); // 30 days
}
// Partner tile contrast: mean opaque-pixel luminance above LIGHT needs a dark
// tile on light backgrounds; below DARK needs a light tile on dark backgrounds.
if (!defined('PARTNER_LOGO_LUMINANCE_LIGHT_THRESHOLD')) {
    define('PARTNER_LOGO_LUMINANCE_LIGHT_THRESHOLD', 0.65);
}
if (!defined('PARTNER_LOGO_LUMINANCE_DARK_THRESHOLD')) {
    define('PARTNER_LOGO_LUMINANCE_DARK_THRESHOLD', 0.35);
}
// Opaque coverage at or above OPAQUE_COVERAGE means a baked-in background; skip hints.
if (!defined('PARTNER_LOGO_OPAQUE_COVERAGE_THRESHOLD')) {
    define('PARTNER_LOGO_OPAQUE_COVERAGE_THRESHOLD', 0.85);
}

// Cloudflare analytics - scheduler pre-warms cache; on-demand fetch is fallback when empty
if (!defined('CLOUDFLARE_ANALYTICS_FETCH_INTERVAL')) {
    define('CLOUDFLARE_ANALYTICS_FETCH_INTERVAL', 900); // 15 minutes
}

// Status page: unified TTL + background refresh (scheduler writes JSON; web reads APCu + stale-ok file)
if (!defined('STATUS_PAGE_CACHE_TTL')) {
    define('STATUS_PAGE_CACHE_TTL', 600); // 10 minutes
}
if (!defined('STATUS_PAGE_BACKGROUND_FETCH_INTERVAL')) {
    define('STATUS_PAGE_BACKGROUND_FETCH_INTERVAL', 120); // 2 minutes - refresh well before TTL
}

// Legacy aliases - same values (health, metrics bundle, performance JSON caches)
if (!defined('STATUS_HEALTH_CACHE_TTL')) {
    define('STATUS_HEALTH_CACHE_TTL', STATUS_PAGE_CACHE_TTL);
}
if (!defined('STATUS_METRICS_CACHE_TTL')) {
    define('STATUS_METRICS_CACHE_TTL', STATUS_PAGE_CACHE_TTL);
}
if (!defined('PERFORMANCE_METRICS_CACHE_TTL')) {
    define('PERFORMANCE_METRICS_CACHE_TTL', STATUS_PAGE_CACHE_TTL);
}
if (!defined('STATUS_HEALTH_FETCH_INTERVAL')) {
    define('STATUS_HEALTH_FETCH_INTERVAL', STATUS_PAGE_BACKGROUND_FETCH_INTERVAL);
}
if (!defined('STATUS_METRICS_FETCH_INTERVAL')) {
    define('STATUS_METRICS_FETCH_INTERVAL', STATUS_PAGE_BACKGROUND_FETCH_INTERVAL);
}
if (!defined('PERFORMANCE_METRICS_FETCH_INTERVAL')) {
    define('PERFORMANCE_METRICS_FETCH_INTERVAL', STATUS_PAGE_BACKGROUND_FETCH_INTERVAL);
}

// Public API GET /v1/operations: scheduler-built snapshot; readers serve up to max age if job stalls
if (!defined('OPERATIONS_SNAPSHOT_BUILD_INTERVAL_SECONDS')) {
    define('OPERATIONS_SNAPSHOT_BUILD_INTERVAL_SECONDS', 600); // 10 minutes
}
if (!defined('OPERATIONS_SNAPSHOT_MAX_AGE_SECONDS')) {
    define('OPERATIONS_SNAPSHOT_MAX_AGE_SECONDS', 1800); // 30 minutes
}

// Runway geometry (FAA + OurAirports) - weekly check, fetch when missing or >30 days old
if (!defined('RUNWAYS_FETCH_CHECK_INTERVAL')) {
    define('RUNWAYS_FETCH_CHECK_INTERVAL', 604800); // 7 days
}
if (!defined('RUNWAYS_CACHE_MAX_AGE')) {
    define('RUNWAYS_CACHE_MAX_AGE', 2592000); // 30 days
}
if (!defined('RUNWAYS_APCU_TTL')) {
    define('RUNWAYS_APCU_TTL', 2592000); // 30 days (match cache max age)
}

// NASR APT subscription (runway length, surface, departure obstructions for US airports)
if (!defined('NASR_APT_SCHEMA_VERSION')) {
    define('NASR_APT_SCHEMA_VERSION', 1);
}
if (!defined('NASR_FETCH_CHECK_INTERVAL')) {
    define('NASR_FETCH_CHECK_INTERVAL', 604800); // 7 days
}
if (!defined('NASR_CACHE_MAX_AGE')) {
    define('NASR_CACHE_MAX_AGE', 3024000); // 35 days (28-day cycle + buffer)
}
if (!defined('NASR_CYCLE_PERIOD_DAYS')) {
    define('NASR_CYCLE_PERIOD_DAYS', 28);
}
if (!defined('NASR_PROBE_DAYS_BEFORE')) {
    define('NASR_PROBE_DAYS_BEFORE', 14);
}
if (!defined('NASR_PROBE_DAYS_AFTER')) {
    define('NASR_PROBE_DAYS_AFTER', 14);
}
if (!defined('NASR_HTTP_MAX_ATTEMPTS')) {
    define('NASR_HTTP_MAX_ATTEMPTS', 3);
}
if (!defined('NASR_HTTP_RETRY_DELAYS_SECONDS')) {
    define('NASR_HTTP_RETRY_DELAYS_SECONDS', [5, 30]);
}
if (!defined('NASR_CONFIG_ELEVATION_TOLERANCE_FT')) {
    define('NASR_CONFIG_ELEVATION_TOLERANCE_FT', 2);
}
if (!defined('NASR_CONFIG_MAGNETIC_TOLERANCE_DEG')) {
    define('NASR_CONFIG_MAGNETIC_TOLERANCE_DEG', 1.0);
}
if (!defined('NASR_DISCOVERY_SMOKE_TIMEOUT_SECONDS')) {
    define('NASR_DISCOVERY_SMOKE_TIMEOUT_SECONDS', 120);
}
if (!defined('NASR_APT_MIN_AIRPORT_COUNT')) {
    define('NASR_APT_MIN_AIRPORT_COUNT', 10000);
}

// Density altitude performance (reference AFM models, not a go/no-go judgment)
if (!defined('DENSITY_ALTITUDE_PERFORMANCE_TIER_CAUTION')) {
    define('DENSITY_ALTITUDE_PERFORMANCE_TIER_CAUTION', 1.20);
}
if (!defined('DENSITY_ALTITUDE_PERFORMANCE_TIER_WARNING')) {
    define('DENSITY_ALTITUDE_PERFORMANCE_TIER_WARNING', 2.40);
}
if (!defined('PERFORMANCE_STRESS_LOW')) {
    define('PERFORMANCE_STRESS_LOW', 0.67);
}
if (!defined('PERFORMANCE_STRESS_HIGH')) {
    define('PERFORMANCE_STRESS_HIGH', 1.33);
}
if (!defined('POH_OBSTACLE_REFERENCE_HEIGHT_FT')) {
    define('POH_OBSTACLE_REFERENCE_HEIGHT_FT', 50);
}
if (!defined('POH_GRASS_GROUND_ROLL_FACTOR')) {
    define('POH_GRASS_GROUND_ROLL_FACTOR', 0.15);
}
if (!defined('DENSITY_ALTITUDE_PERFORMANCE_REFERENCE')) {
    define(
        'DENSITY_ALTITUDE_PERFORMANCE_REFERENCE',
        'Cessna 152/172/182 AFM max gross, 0 kt wind (neutral conservative case); comparable NASR runways'
    );
}
if (!defined('DENSITY_ALTITUDE_PERFORMANCE_REFERENCE_OURAIRPORTS')) {
    define(
        'DENSITY_ALTITUDE_PERFORMANCE_REFERENCE_OURAIRPORTS',
        'Cessna 152/172/182 AFM max gross, 0 kt wind (neutral conservative case); comparable OurAirports runways (no obstruction data)'
    );
}
if (!defined('DENSITY_ALTITUDE_PERFORMANCE_REFERENCE_CONFIG')) {
    define(
        'DENSITY_ALTITUDE_PERFORMANCE_REFERENCE_CONFIG',
        'Cessna 152/172/182 AFM max gross, 0 kt wind (neutral conservative case); operator runway_length_ft override (no obstruction data)'
    );
}
if (!defined('DENSITY_ALTITUDE_PERFORMANCE_REFERENCE_FALLBACK')) {
    define(
        'DENSITY_ALTITUDE_PERFORMANCE_REFERENCE_FALLBACK',
        'Elevation-banded density altitude thresholds; no runway data'
    );
}
if (!defined('DENSITY_ALTITUDE_PERFORMANCE_FALLBACK_TOOLTIP')) {
    define(
        'DENSITY_ALTITUDE_PERFORMANCE_FALLBACK_TOOLTIP',
        'Runway data unavailable. Indicator based on density altitude relative to field elevation only. '
        . 'Verify all performance calculations using your AFM.'
    );
}
if (!defined('DA_PERF_WIND_MIN_OBS')) {
    define('DA_PERF_WIND_MIN_OBS', 3);
}
if (!defined('DA_PERF_WIND_MIN_MEAN_KTS')) {
    define('DA_PERF_WIND_MIN_MEAN_KTS', 5.0);
}
if (!defined('DA_PERF_VARIABLE_WIND_RATIO')) {
    define('DA_PERF_VARIABLE_WIND_RATIO', 2.0);
}
if (!defined('DA_PERF_ASYMMETRIC_SPREAD')) {
    define('DA_PERF_ASYMMETRIC_SPREAD', 1.5);
}

// Airport country resolution (geometry aggregate under CACHE_BASE_DIR; see scripts/refresh-airport-country-resolution.php)
if (!defined('COUNTRY_RESOLUTION_AGGREGATE_SCHEMA_VERSION')) {
    define('COUNTRY_RESOLUTION_AGGREGATE_SCHEMA_VERSION', 1);
}
if (!defined('COUNTRY_RESOLUTION_BOUNDARY_DATASET_ID')) {
    // Label stored in aggregate JSON; matches vendored file under data/geo/
    define('COUNTRY_RESOLUTION_BOUNDARY_DATASET_ID', 'ne_110m_admin_0_countries-v5.2.0');
}
// How long a valid aggregate (matching config SHA) may sit before scheduler rebuilds geometry (policy: refresh country-at-point).
if (!defined('COUNTRY_RESOLUTION_AGGREGATE_MAX_AGE_SECONDS')) {
    define('COUNTRY_RESOLUTION_AGGREGATE_MAX_AGE_SECONDS', 2592000); // 30 days
}
// Scheduler evaluates refresh at most this often (avoids reading/decoding aggregate every main-loop tick).
if (!defined('COUNTRY_RESOLUTION_SCHEDULER_CHECK_INTERVAL')) {
    define('COUNTRY_RESOLUTION_SCHEDULER_CHECK_INTERVAL', 3600); // 1 hour
}
// @deprecated Use COUNTRY_RESOLUTION_AGGREGATE_MAX_AGE_SECONDS; kept for backward compatibility (same value).
if (!defined('COUNTRY_RESOLUTION_REFRESH_INTERVAL')) {
    define('COUNTRY_RESOLUTION_REFRESH_INTERVAL', COUNTRY_RESOLUTION_AGGREGATE_MAX_AGE_SECONDS);
}

// Circuit breaker / backoff
// Circuit breaker failure threshold - require this many consecutive failures before opening
if (!defined('CIRCUIT_BREAKER_FAILURE_THRESHOLD')) {
    define('CIRCUIT_BREAKER_FAILURE_THRESHOLD', 2); // Open after 2 consecutive failures
}

// Base backoff times for different error types
if (!defined('BACKOFF_BASE_SECONDS')) {
    define('BACKOFF_BASE_SECONDS', 60); // Legacy - kept for backward compatibility
}
if (!defined('BACKOFF_BASE_TRANSIENT')) {
    define('BACKOFF_BASE_TRANSIENT', 10); // 10 seconds for transient errors
}
if (!defined('BACKOFF_BASE_RATE_LIMIT')) {
    define('BACKOFF_BASE_RATE_LIMIT', 2); // 2 seconds for rate limit (429) errors
}

// Maximum backoff durations
if (!defined('BACKOFF_MAX_TRANSIENT')) {
    define('BACKOFF_MAX_TRANSIENT', 600); // 10 minutes
}
if (!defined('BACKOFF_MAX_PERMANENT')) {
    define('BACKOFF_MAX_PERMANENT', 1800); // 30 minutes
}
if (!defined('BACKOFF_MAX_FAILURES')) {
    define('BACKOFF_MAX_FAILURES', 5);
}
if (!defined('BACKOFF_MAX_RETRY_AFTER_SECONDS')) {
    define('BACKOFF_MAX_RETRY_AFTER_SECONDS', 900); // 15 minutes - max Retry-After / reset hint
}

// Background refresh
if (!defined('BACKGROUND_REFRESH_MAX_TIME')) {
    define('BACKGROUND_REFRESH_MAX_TIME', 40); // Leave 5s buffer before script timeout
}

// HTTP status codes
if (!defined('HTTP_STATUS_OK')) {
    define('HTTP_STATUS_OK', 200);
}
if (!defined('HTTP_STATUS_NOT_FOUND')) {
    define('HTTP_STATUS_NOT_FOUND', 404);
}
if (!defined('HTTP_STATUS_SERVICE_UNAVAILABLE')) {
    define('HTTP_STATUS_SERVICE_UNAVAILABLE', 503);
}

// Error rate monitoring
if (!defined('ERROR_RATE_WINDOW_SECONDS')) {
    define('ERROR_RATE_WINDOW_SECONDS', 3600); // 1 hour
}
if (!defined('ERROR_RATE_DEGRADED_THRESHOLD')) {
    define('ERROR_RATE_DEGRADED_THRESHOLD', 10); // System degraded if >= 10 errors/hour
}

// Metrics collection
if (!defined('METRICS_RETENTION_DAYS')) {
    define('METRICS_RETENTION_DAYS', 14); // Keep 14 days of metrics
}
if (!defined('METRICS_FLUSH_INTERVAL_SECONDS')) {
    define('METRICS_FLUSH_INTERVAL_SECONDS', 300); // Flush to disk every 5 minutes
}
if (!defined('METRICS_STATUS_PAGE_DAYS')) {
    define('METRICS_STATUS_PAGE_DAYS', 7); // Show 7-day rolling window on status page
}
/** Completed UTC hour slices before current hour in {@see metrics_get_status_hourly_profile()} (plus one partial hour). */
if (!defined('METRICS_STATUS_HOURLY_PROFILE_COMPLETED_HOURS')) {
    define('METRICS_STATUS_HOURLY_PROFILE_COMPLETED_HOURS', 26);
}
/** Bump when hourly_profile JSON shape changes (invalidates APCu bundle mirror). */
if (!defined('METRICS_STATUS_HOURLY_PROFILE_SCHEMA_VERSION')) {
    define('METRICS_STATUS_HOURLY_PROFILE_SCHEMA_VERSION', 1);
}
if (!defined('METRICS_STATUS_BUNDLE_MIRROR_TTL_SECONDS')) {
    // APCu snapshot of metrics_get_status_bundle() after flush; best-effort telemetry only
    define('METRICS_STATUS_BUNDLE_MIRROR_TTL_SECONDS', 120);
}
if (!defined('METRICS_STATUS_BUNDLE_MIRROR_APCU_KEY')) {
    // Prefix dashboard_metrics_* so metrics_reset_all() (metrics_* counters only) does not delete this APCu entry
    define('METRICS_STATUS_BUNDLE_MIRROR_APCU_KEY', 'dashboard_metrics_status_bundle_mirror_v1');
}

/** Scheduler invokes spill aggregator CLI at this interval (seconds). */
if (!defined('METRICS_SPILL_MERGE_INTERVAL_SECONDS')) {
    define('METRICS_SPILL_MERGE_INTERVAL_SECONDS', 90);
}

/** Delete spill journal files older than this age if never consumed by aggregator (seconds). */
if (!defined('METRICS_SPILL_ORPHAN_MAX_AGE_SECONDS')) {
    define('METRICS_SPILL_ORPHAN_MAX_AGE_SECONDS', 3 * 3600);
}

/** Max spill journal files processed per aggregator run (soft cap; logged if hit). */
if (!defined('METRICS_SPILL_MERGE_MAX_FILES_PER_RUN')) {
    define('METRICS_SPILL_MERGE_MAX_FILES_PER_RUN', 2000);
}

/** Max wall-clock milliseconds for one aggregator merge loop before yielding (soft cap). */
if (!defined('METRICS_SPILL_MERGE_MAX_RUNTIME_MS')) {
    define('METRICS_SPILL_MERGE_MAX_RUNTIME_MS', 250);
}

/** Basename for exclusive flock singleton lock under CACHE_METRICS_DIR. */
if (!defined('METRICS_AGGREGATOR_LOCK_BASENAME')) {
    define('METRICS_AGGREGATOR_LOCK_BASENAME', 'aggregator.lock');
}

/** Basename for last-run telemetry JSON under CACHE_METRICS_DIR. */
if (!defined('METRICS_AGGREGATOR_LAST_RUN_BASENAME')) {
    define('METRICS_AGGREGATOR_LAST_RUN_BASENAME', 'aggregator_last_run.json');
}

/** JSON schema version for per-worker spill snapshot files under CACHE_METRICS_SPILL_DIR. */
if (!defined('METRICS_SPILL_FILE_SCHEMA_VERSION')) {
    define('METRICS_SPILL_FILE_SCHEMA_VERSION', 1);
}

// Time constants (seconds)
if (!defined('SECONDS_PER_MINUTE')) {
    define('SECONDS_PER_MINUTE', 60);
}
if (!defined('SECONDS_PER_HOUR')) {
    define('SECONDS_PER_HOUR', 3600);
}
if (!defined('SECONDS_PER_DAY')) {
    define('SECONDS_PER_DAY', 86400);
}

// Station power (facility metrics; not flight-safety tier - separate staleness from weather/METAR)
if (!defined('STATION_POWER_FETCH_INTERVAL_SECONDS')) {
    define('STATION_POWER_FETCH_INTERVAL_SECONDS', 600); // 10 minutes
}
if (!defined('STATION_POWER_CACHE_MAX_DISPLAY_AGE_SECONDS')) {
    define('STATION_POWER_CACHE_MAX_DISPLAY_AGE_SECONDS', 30 * SECONDS_PER_DAY);
}
/** Default dashboard poll interval for station power JSON (seconds); overridable via config. */
if (!defined('STATION_POWER_DEFAULT_REFRESH_SECONDS')) {
    define('STATION_POWER_DEFAULT_REFRESH_SECONDS', 15 * SECONDS_PER_MINUTE);
}
if (!defined('RATE_LIMIT_STATION_POWER_MAX')) {
    define('RATE_LIMIT_STATION_POWER_MAX', 60);
}
if (!defined('RATE_LIMIT_STATION_POWER_WINDOW')) {
    define('RATE_LIMIT_STATION_POWER_WINDOW', 60);
}
if (!defined('SECONDS_PER_WEEK')) {
    define('SECONDS_PER_WEEK', 604800);
}

// Debug log path - works in both container and host
// Prefer /var/log/aviationwx when present; otherwise use a neutral cache subdirectory (not committed).
if (!defined('DEBUG_LOG_PATH')) {
    // Check if we're in Docker container
    $inContainer = file_exists('/var/www/html');
    
    // If in container, check if /var/log/aviationwx exists (indicates file-based logging is used)
    // This directory is used by all major services for file-based logs
    if ($inContainer && is_dir('/var/log/aviationwx')) {
        // Use the same directory as other services (app.log, user.log, cron-*.log, etc.)
        $logDir = '/var/log/aviationwx';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        define('DEBUG_LOG_PATH', $logDir . '/debug.log');
    } elseif ($inContainer && is_dir('/var/www/html/cache')) {
        // Fallback: use cache directory if /var/log/aviationwx doesn't exist yet
        $debugLogDir = '/var/www/html/cache/dev-debug';
        if (!is_dir($debugLogDir)) {
            @mkdir($debugLogDir, 0755, true);
        }
        define('DEBUG_LOG_PATH', $debugLogDir . '/debug.log');
    } else {
        // Host or non-standard layout: project cache dev-debug directory
        $fallbackDir = dirname(__DIR__) . '/cache/dev-debug';
        if (!is_dir($fallbackDir)) {
            @mkdir($fallbackDir, 0755, true);
        }
        if (is_dir($fallbackDir)) {
            define('DEBUG_LOG_PATH', $fallbackDir . '/debug.log');
        } else {
            // Final fallback: use /var/log/aviationwx (will be created if needed)
            define('DEBUG_LOG_PATH', '/var/log/aviationwx/debug.log');
        }
    }
}

// Helper function to get debug log path (ensures constants are loaded)
if (!function_exists('get_debug_log_path')) {
    function get_debug_log_path(): string {
        if (defined('DEBUG_LOG_PATH')) {
            return DEBUG_LOG_PATH;
        }
        // Fallback if constant not defined
        require_once __DIR__ . '/constants.php';
        return defined('DEBUG_LOG_PATH') ? DEBUG_LOG_PATH : (dirname(__DIR__) . '/cache/dev-debug/debug.log');
    }
}

// Helper function to get host-accessible debug log path (for reading logs from host)
// Checks project cache dev-debug and Docker-mounted cache paths used in local development.
if (!function_exists('get_debug_log_path_host')) {
    function get_debug_log_path_host(): string {
        $projectLogPath = dirname(__DIR__) . '/cache/dev-debug/debug.log';
        if (file_exists($projectLogPath)) {
            return $projectLogPath;
        }
        // Check Docker log directory (if file-based logging is enabled, logs are in /var/log/aviationwx)
        // This maps to the container's log directory, but we need to check if it's accessible from host
        // For Docker, we'd need to check the container's log directory via docker exec or volume mount
        // For now, check the cache location as fallback (backward compatibility)
        $dockerCacheLogPath = '/tmp/aviationwx-cache/dev-debug/debug.log';
        if (file_exists($dockerCacheLogPath)) {
            return $dockerCacheLogPath;
        }
        // Check if we can access the container's log directory via docker exec
        // This is a best-effort check - in production, logs should be accessible via volume mounts
        $containerLogPath = '/var/log/aviationwx/debug.log';
        // Fallback to project path (will be created if needed)
        return $projectLogPath;
    }
}

// Status page thresholds
if (!defined('STATUS_RECENT_LOG_THRESHOLD_SECONDS')) {
    define('STATUS_RECENT_LOG_THRESHOLD_SECONDS', SECONDS_PER_HOUR); // Logs considered recent if within 1 hour
}

// METAR observation time parsing thresholds
if (!defined('METAR_OBS_TIME_MAX_AGE_SECONDS')) {
    define('METAR_OBS_TIME_MAX_AGE_SECONDS', 259200); // 3 days in seconds
}
if (!defined('METAR_OBS_TIME_FUTURE_THRESHOLD_DAYS')) {
    define('METAR_OBS_TIME_FUTURE_THRESHOLD_DAYS', 3); // If observation is >3 days in future, try previous month
}
if (!defined('METAR_OBS_TIME_PAST_THRESHOLD_DAYS')) {
    define('METAR_OBS_TIME_PAST_THRESHOLD_DAYS', 25); // If observation is >25 days in past, try next month
}

// METAR bulk: scheduler refresh cadence, slice max age before HTTP fallback, download timeout
if (!defined('METAR_BULK_REFRESH_INTERVAL_SECONDS')) {
    define('METAR_BULK_REFRESH_INTERVAL_SECONDS', 120);
}
if (!defined('METAR_BULK_STATION_FILE_MAX_AGE_SECONDS')) {
    define('METAR_BULK_STATION_FILE_MAX_AGE_SECONDS', 900);
}
if (!defined('METAR_BULK_DOWNLOAD_TIMEOUT_SECONDS')) {
    define('METAR_BULK_DOWNLOAD_TIMEOUT_SECONDS', 120);
}

// Upstream self-throttle (per-credential token bucket under cache/upstream-limits/)
if (!defined('UPSTREAM_RATE_LIMIT_DEFAULT_RPM')) {
    define('UPSTREAM_RATE_LIMIT_DEFAULT_RPM', 60);
}
if (!defined('UPSTREAM_RATE_LIMIT_DEFAULT_BURST')) {
    define('UPSTREAM_RATE_LIMIT_DEFAULT_BURST', 10);
}
if (!defined('UPSTREAM_RATE_LIMIT_TEMPEST_RPM')) {
    define('UPSTREAM_RATE_LIMIT_TEMPEST_RPM', 60);
}
if (!defined('UPSTREAM_RATE_LIMIT_TEMPEST_BURST')) {
    define('UPSTREAM_RATE_LIMIT_TEMPEST_BURST', 10);
}
// Ambient: 1 req/s per user apiKey, 3 req/s per developer applicationKey (upstream policy)
if (!defined('UPSTREAM_RATE_LIMIT_AMBIENT_API_KEY_RPM')) {
    define('UPSTREAM_RATE_LIMIT_AMBIENT_API_KEY_RPM', 60);
}
if (!defined('UPSTREAM_RATE_LIMIT_AMBIENT_API_KEY_BURST')) {
    define('UPSTREAM_RATE_LIMIT_AMBIENT_API_KEY_BURST', 1);
}
if (!defined('UPSTREAM_RATE_LIMIT_AMBIENT_APPLICATION_KEY_RPM')) {
    define('UPSTREAM_RATE_LIMIT_AMBIENT_APPLICATION_KEY_RPM', 180);
}
if (!defined('UPSTREAM_RATE_LIMIT_AMBIENT_APPLICATION_KEY_BURST')) {
    define('UPSTREAM_RATE_LIMIT_AMBIENT_APPLICATION_KEY_BURST', 3);
}
if (!defined('UPSTREAM_RATE_LIMIT_PWSWEATHER_RPM')) {
    define('UPSTREAM_RATE_LIMIT_PWSWEATHER_RPM', 60);
}
if (!defined('UPSTREAM_RATE_LIMIT_PWSWEATHER_BURST')) {
    define('UPSTREAM_RATE_LIMIT_PWSWEATHER_BURST', 10);
}
if (!defined('UPSTREAM_RATE_LIMIT_WEATHERLINK_RPM')) {
    define('UPSTREAM_RATE_LIMIT_WEATHERLINK_RPM', 60);
}
if (!defined('UPSTREAM_RATE_LIMIT_WEATHERLINK_BURST')) {
    define('UPSTREAM_RATE_LIMIT_WEATHERLINK_BURST', 3);
}
if (!defined('UPSTREAM_RATE_LIMIT_SYNOPTIC_RPM')) {
    define('UPSTREAM_RATE_LIMIT_SYNOPTIC_RPM', 60);
}
if (!defined('UPSTREAM_RATE_LIMIT_SYNOPTIC_BURST')) {
    define('UPSTREAM_RATE_LIMIT_SYNOPTIC_BURST', 10);
}
// Per-station bucket for METAR HTTP fallback (bulk preferred when configured)
if (!defined('UPSTREAM_RATE_LIMIT_METAR_HTTP_RPM')) {
    define('UPSTREAM_RATE_LIMIT_METAR_HTTP_RPM', 90);
}
if (!defined('UPSTREAM_RATE_LIMIT_METAR_HTTP_BURST')) {
    define('UPSTREAM_RATE_LIMIT_METAR_HTTP_BURST', 15);
}
if (!defined('UPSTREAM_RATE_LIMIT_NWS_RPM')) {
    define('UPSTREAM_RATE_LIMIT_NWS_RPM', 60);
}
if (!defined('UPSTREAM_RATE_LIMIT_NWS_BURST')) {
    define('UPSTREAM_RATE_LIMIT_NWS_BURST', 2);
}

// NWS /points/{lat},{lon} metadata cache (stable grid mapping; reduces repeat calls)
if (!defined('NWS_POINTS_CACHE_TTL_SECONDS')) {
    define('NWS_POINTS_CACHE_TTL_SECONDS', 43200); // 12 hours
}
if (!defined('NWS_POINTS_REFRESH_INTERVAL_SECONDS')) {
    define('NWS_POINTS_REFRESH_INTERVAL_SECONDS', 3600); // scheduler worker cadence (fetch only stale entries)
}

// Push webcam upload file age limits (fail-closed protection)
// Files in upload directory older than this are considered abandoned/stuck
if (!defined('UPLOAD_FILE_MAX_AGE_SECONDS')) {
    define('UPLOAD_FILE_MAX_AGE_SECONDS', 1800); // 30 minutes default
}
if (!defined('MIN_UPLOAD_FILE_MAX_AGE_SECONDS')) {
    define('MIN_UPLOAD_FILE_MAX_AGE_SECONDS', 600); // 10 minutes minimum (config override)
}
if (!defined('MAX_UPLOAD_FILE_MAX_AGE_SECONDS')) {
    define('MAX_UPLOAD_FILE_MAX_AGE_SECONDS', 7200); // 2 hours maximum (config override)
}

// Push FTP/SFTP inbox debris: max file age (mtime) before deletion (hourly + daily cleanup scripts)
if (!defined('MIN_CLEANUP_PUSH_UPLOAD_DEBRIS_MAX_AGE_SECONDS')) {
    define('MIN_CLEANUP_PUSH_UPLOAD_DEBRIS_MAX_AGE_SECONDS', 600); // 10 minutes (config override floor)
}
if (!defined('MAX_CLEANUP_PUSH_UPLOAD_DEBRIS_MAX_AGE_SECONDS')) {
    define('MAX_CLEANUP_PUSH_UPLOAD_DEBRIS_MAX_AGE_SECONDS', 604800); // 7 days (config override ceiling)
}
if (!defined('CLEANUP_PUSH_UPLOAD_DEBRIS_MAX_AGE_SECONDS')) {
    define('CLEANUP_PUSH_UPLOAD_DEBRIS_MAX_AGE_SECONDS', 10800); // 3 hours default when config omits override
}

// Push webcam adaptive stability checking
// How long to wait in stability checking loop for in-progress upload
if (!defined('DEFAULT_STABILITY_CHECK_TIMEOUT_SECONDS')) {
    define('DEFAULT_STABILITY_CHECK_TIMEOUT_SECONDS', 15); // 15 seconds (20 checks * 0.5s + buffer)
}
if (!defined('MIN_STABILITY_CHECK_TIMEOUT_SECONDS')) {
    define('MIN_STABILITY_CHECK_TIMEOUT_SECONDS', 10); // 10 seconds minimum
}
if (!defined('MAX_STABILITY_CHECK_TIMEOUT_SECONDS')) {
    define('MAX_STABILITY_CHECK_TIMEOUT_SECONDS', 30); // 30 seconds maximum
}

// Adaptive stability metrics
if (!defined('MIN_STABLE_CHECKS')) {
    define('MIN_STABLE_CHECKS', 5); // Absolute minimum consecutive stable checks
}
if (!defined('MAX_STABLE_CHECKS')) {
    define('MAX_STABLE_CHECKS', 20); // Conservative maximum consecutive stable checks
}
if (!defined('DEFAULT_STABLE_CHECKS')) {
    define('DEFAULT_STABLE_CHECKS', 20); // Start conservative
}
if (!defined('STABILITY_CHECK_INTERVAL_MS')) {
    define('STABILITY_CHECK_INTERVAL_MS', 500); // 0.5 seconds between checks
}
if (!defined('STABILITY_SAMPLES_TO_KEEP')) {
    define('STABILITY_SAMPLES_TO_KEEP', 100); // Rolling window size
}
if (!defined('REJECTION_RATE_THRESHOLD_HIGH')) {
    define('REJECTION_RATE_THRESHOLD_HIGH', 0.05); // >5% rejected = more conservative
}
if (!defined('REJECTION_RATE_THRESHOLD_LOW')) {
    define('REJECTION_RATE_THRESHOLD_LOW', 0.02); // <2% rejected = can optimize
}
if (!defined('P95_SAFETY_MARGIN')) {
    define('P95_SAFETY_MARGIN', 1.5); // Multiply P95 time by 1.5 (50% buffer)
}
if (!defined('MIN_SAMPLES_FOR_OPTIMIZATION')) {
    define('MIN_SAMPLES_FOR_OPTIMIZATION', 20); // Need 20 samples before optimizing
}

// Push webcam batch processing
// Process multiple files per worker run to clear backlogs efficiently
if (!defined('PUSH_BATCH_LIMIT')) {
    define('PUSH_BATCH_LIMIT', 30); // Max files per worker run
}
if (!defined('PUSH_EXTENDED_TIMEOUT_THRESHOLD')) {
    define('PUSH_EXTENDED_TIMEOUT_THRESHOLD', 10); // Files before extending timeout
}
if (!defined('PUSH_EXTENDED_TIMEOUT_SECONDS')) {
    define('PUSH_EXTENDED_TIMEOUT_SECONDS', 300); // 5 minutes for larger batches
}

// Webcam error frame detection thresholds
if (!defined('WEBCAM_ERROR_MIN_WIDTH')) {
    define('WEBCAM_ERROR_MIN_WIDTH', 100); // Minimum image width to be considered valid
}
if (!defined('WEBCAM_ERROR_MIN_HEIGHT')) {
    define('WEBCAM_ERROR_MIN_HEIGHT', 100); // Minimum image height to be considered valid
}
if (!defined('WEBCAM_ERROR_SAMPLE_SIZE')) {
    define('WEBCAM_ERROR_SAMPLE_SIZE', 10000); // Maximum pixels to sample for analysis
}
if (!defined('WEBCAM_ERROR_GREY_CHANNEL_DIFF')) {
    define('WEBCAM_ERROR_GREY_CHANNEL_DIFF', 30); // RGB channels differ by < this = grey pixel
}
if (!defined('WEBCAM_ERROR_DARK_BRIGHTNESS')) {
    define('WEBCAM_ERROR_DARK_BRIGHTNESS', 80); // Average brightness < this = dark pixel
}
if (!defined('WEBCAM_ERROR_GREY_RATIO_THRESHOLD')) {
    define('WEBCAM_ERROR_GREY_RATIO_THRESHOLD', 0.85); // >85% grey pixels indicates error frame
}
if (!defined('WEBCAM_ERROR_DARK_RATIO_THRESHOLD')) {
    define('WEBCAM_ERROR_DARK_RATIO_THRESHOLD', 0.6); // >60% dark pixels indicates error frame
}
if (!defined('WEBCAM_ERROR_COLOR_VARIANCE_THRESHOLD')) {
    define('WEBCAM_ERROR_COLOR_VARIANCE_THRESHOLD', 200); // <200 variance indicates uniform/error frame
}
if (!defined('WEBCAM_ERROR_EDGE_DIFF_THRESHOLD')) {
    define('WEBCAM_ERROR_EDGE_DIFF_THRESHOLD', 30); // Pixel difference > this = edge detected
}
if (!defined('WEBCAM_ERROR_EDGE_RATIO_THRESHOLD')) {
    define('WEBCAM_ERROR_EDGE_RATIO_THRESHOLD', 0.02); // <2% edge pixels indicates error frame
}
if (!defined('WEBCAM_ERROR_BORDER_BRIGHTNESS')) {
    define('WEBCAM_ERROR_BORDER_BRIGHTNESS', 120); // Border brightness < this = grey border
}
if (!defined('WEBCAM_ERROR_BORDER_RATIO_THRESHOLD')) {
    define('WEBCAM_ERROR_BORDER_RATIO_THRESHOLD', 0.7); // >70% grey borders indicates error frame
}
if (!defined('WEBCAM_ERROR_SCORE_THRESHOLD')) {
    define('WEBCAM_ERROR_SCORE_THRESHOLD', 0.7); // Error score >= this = error frame
}
if (!defined('WEBCAM_ERROR_GREY_SCORE_WEIGHT')) {
    define('WEBCAM_ERROR_GREY_SCORE_WEIGHT', 0.3); // Weight for grey ratio in error score
}
if (!defined('WEBCAM_ERROR_DARK_SCORE_WEIGHT')) {
    define('WEBCAM_ERROR_DARK_SCORE_WEIGHT', 0.2); // Weight for dark ratio in error score
}
if (!defined('WEBCAM_ERROR_VARIANCE_SCORE_WEIGHT')) {
    define('WEBCAM_ERROR_VARIANCE_SCORE_WEIGHT', 0.3); // Weight for variance in error score
}
if (!defined('WEBCAM_ERROR_EDGE_SCORE_WEIGHT')) {
    define('WEBCAM_ERROR_EDGE_SCORE_WEIGHT', 0.2); // Weight for edge density in error score
}
if (!defined('WEBCAM_ERROR_BORDER_SCORE_WEIGHT')) {
    define('WEBCAM_ERROR_BORDER_SCORE_WEIGHT', 0.1); // Weight for border analysis in error score
}
if (!defined('WEBCAM_ERROR_QUICK_GREY_RATIO')) {
    define('WEBCAM_ERROR_QUICK_GREY_RATIO', 0.7); // Quick check: >70% grey = likely error
}
if (!defined('WEBCAM_ERROR_QUICK_DARK_RATIO')) {
    define('WEBCAM_ERROR_QUICK_DARK_RATIO', 0.5); // Quick check: >50% dark = likely error
}
if (!defined('WEBCAM_ERROR_EDGE_SAMPLE_SIZE')) {
    define('WEBCAM_ERROR_EDGE_SAMPLE_SIZE', 5000); // Maximum pixels to sample for edge detection
}
if (!defined('WEBCAM_ERROR_BORDER_SAMPLE_SIZE')) {
    define('WEBCAM_ERROR_BORDER_SAMPLE_SIZE', 50); // Number of border pixels to sample
}
if (!defined('WEBCAM_ERROR_EMBEDDED_BORDER_RATIO')) {
    define('WEBCAM_ERROR_EMBEDDED_BORDER_RATIO', 0.8); // >80% grey borders with less grey center = embedded image pattern
}
if (!defined('WEBCAM_ERROR_EMBEDDED_CENTER_RATIO')) {
    define('WEBCAM_ERROR_EMBEDDED_CENTER_RATIO', 0.7); // Center <70% grey when borders >80% = embedded image
}
if (!defined('WEBCAM_ERROR_BRIGHT_CENTER_CONCENTRATION')) {
    define('WEBCAM_ERROR_BRIGHT_CENTER_CONCENTRATION', 0.7); // >70% bright pixels in center = embedded image pattern
}
if (!defined('WEBCAM_ERROR_BRIGHT_PIXEL_THRESHOLD_FOR_TEXT_EXCLUSION')) {
    define('WEBCAM_ERROR_BRIGHT_PIXEL_THRESHOLD_FOR_TEXT_EXCLUSION', 200); // Brightness > this = likely text, exclude from grey count
}
if (!defined('WEBCAM_ERROR_WHITE_PIXEL_THRESHOLD')) {
    define('WEBCAM_ERROR_WHITE_PIXEL_THRESHOLD', 200); // Brightness > this = white pixel (for text detection)
}
if (!defined('WEBCAM_ERROR_QUICK_BORDER_VARIANCE_THRESHOLD')) {
    define('WEBCAM_ERROR_QUICK_BORDER_VARIANCE_THRESHOLD', 500); // Variance >500 in border = real image (early exit)
}
if (!defined('WEBCAM_ERROR_BORDER_VARIANCE_THRESHOLD')) {
    define('WEBCAM_ERROR_BORDER_VARIANCE_THRESHOLD', 200); // Variance <200 in borders = uniform/error frame
}

// Uniform color detection (solid color = failed camera, lens cap, corruption)
// Daytime: high threshold catches flat error screens while allowing natural texture.
// Night: use a lower threshold so very dark sky (low but non-zero variance from noise/stars) is not misclassified as uniform.
if (!defined('WEBCAM_ERROR_UNIFORM_COLOR_VARIANCE_THRESHOLD')) {
    define('WEBCAM_ERROR_UNIFORM_COLOR_VARIANCE_THRESHOLD', 25); // Max channel variance <25 = essentially one color
}
if (!defined('WEBCAM_ERROR_UNIFORM_COLOR_VARIANCE_THRESHOLD_NAUTICAL')) {
    define('WEBCAM_ERROR_UNIFORM_COLOR_VARIANCE_THRESHOLD_NAUTICAL', 15); // Nautical twilight: between day and night
}
if (!defined('WEBCAM_ERROR_UNIFORM_COLOR_VARIANCE_THRESHOLD_NIGHT')) {
    define('WEBCAM_ERROR_UNIFORM_COLOR_VARIANCE_THRESHOLD_NIGHT', 8); // Only reject if nearly flat (dead sensor / lens cap)
}
if (!defined('WEBCAM_ERROR_UNIFORM_COLOR_SAMPLE_SIZE')) {
    define('WEBCAM_ERROR_UNIFORM_COLOR_SAMPLE_SIZE', 50); // Only need ~50 samples for this check
}
// Corrupt bottom detection: rows to check from bottom (JPEG encodes top-to-bottom)
if (!defined('WEBCAM_ERROR_CORRUPT_BOTTOM_ROWS')) {
    define('WEBCAM_ERROR_CORRUPT_BOTTOM_ROWS', 5);
}
if (!defined('WEBCAM_ERROR_CORRUPT_ROW_SAMPLE_STEP')) {
    define('WEBCAM_ERROR_CORRUPT_ROW_SAMPLE_STEP', 20); // Samples per row for line check
}
if (!defined('WEBCAM_ERROR_CORRUPT_ROW_VARIANCE_THRESHOLD')) {
    define('WEBCAM_ERROR_CORRUPT_ROW_VARIANCE_THRESHOLD', 200); // Skip row if variance >= this; allows JPEG artifacts in corrupt regions, skips real varied content
}
if (!defined('WEBCAM_ERROR_CORRUPT_COLOR_LOW')) {
    define('WEBCAM_ERROR_CORRUPT_COLOR_LOW', 50); // R/B must be < this for green/blue (allows JPEG variation)
}
if (!defined('WEBCAM_ERROR_CORRUPT_COLOR_HIGH')) {
    define('WEBCAM_ERROR_CORRUPT_COLOR_HIGH', 110); // Dominant channel must be > this; full row of solid green/blue/red is rare
}
// Fast-fail: last N pixels in lower-right (JPEG scan order); corruption cuts off there
if (!defined('WEBCAM_ERROR_CORRUPT_CORNER_SIZE')) {
    define('WEBCAM_ERROR_CORRUPT_CORNER_SIZE', 10); // Pixels to sample (rightmost of bottom row)
}
if (!defined('WEBCAM_ERROR_CORRUPT_CORNER_MIN_MATCH')) {
    define('WEBCAM_ERROR_CORRUPT_CORNER_MIN_MATCH', 8); // Require 8+ of 10 to match corruption color
}
if (!defined('WEBCAM_ERROR_CORRUPT_CORNER_MIN_BRIGHTNESS')) {
    define('WEBCAM_ERROR_CORRUPT_CORNER_MIN_BRIGHTNESS', 35); // Skip dark corners (night); corruption green/blue/red typically 35+
}

// Pixelation detection using Laplacian variance (low variance = overly smooth/pixelated)
// Measures edge sharpness - healthy images have sharp edges, pixelated images are blurry
// Conservative thresholds to avoid false positives (fog, overcast, snow are legitimately soft)
// Phase-specific thresholds: day has more detail, night is naturally softer
//
// Laplacian variance values observed in real-world conditions:
// - Crisp daytime image: 500-2000+
// - Foggy/overcast day: 100-300
// - Night with lights: 50-200
// - Dark night: 20-100
// - Foggy night/twilight: 2-8 (legitimate but very soft)
// - Ultra-dark clear night (little edge content): can fall just under 2; still valid
// - Severely pixelated/corrupted: near 0 (essentially flat/broken)
//
// Night threshold below 2 accepts that band without letting obvious broken frames through when combined with other checks.
if (!defined('WEBCAM_PIXELATION_THRESHOLD_DAY')) {
    define('WEBCAM_PIXELATION_THRESHOLD_DAY', 15); // Day: reject if variance < 15
}
if (!defined('WEBCAM_PIXELATION_THRESHOLD_CIVIL')) {
    define('WEBCAM_PIXELATION_THRESHOLD_CIVIL', 10); // Civil twilight: lower threshold
}
if (!defined('WEBCAM_PIXELATION_THRESHOLD_NAUTICAL')) {
    define('WEBCAM_PIXELATION_THRESHOLD_NAUTICAL', 5); // Nautical twilight: more lenient for foggy conditions
}
if (!defined('WEBCAM_PIXELATION_THRESHOLD_NIGHT')) {
    define('WEBCAM_PIXELATION_THRESHOLD_NIGHT', 1); // Night: allow Laplacian variance down to 1 (very dark sky)
}
// Sample size for Laplacian calculation (grid of NxN samples across image)
if (!defined('WEBCAM_PIXELATION_SAMPLE_GRID')) {
    define('WEBCAM_PIXELATION_SAMPLE_GRID', 20); // 20x20 grid = 400 sample points
}

// EXIF timestamp validation (fail closed - reject images with invalid timestamps)
// All webcam images must have valid EXIF DateTimeOriginal before acceptance
// Server-generated images (RTSP/MJPEG) have EXIF added immediately after capture
// Push camera images must have camera-provided EXIF with valid timestamps
if (!defined('WEBCAM_EXIF_MAX_FUTURE_SECONDS')) {
    define('WEBCAM_EXIF_MAX_FUTURE_SECONDS', 3600); // 1 hour future = reject (clock misconfiguration)
}
if (!defined('WEBCAM_EXIF_MAX_AGE_SECONDS')) {
    define('WEBCAM_EXIF_MAX_AGE_SECONDS', 86400); // 24 hours old = reject (stale image)
}
if (!defined('WEBCAM_EXIF_MIN_VALID_YEAR')) {
    define('WEBCAM_EXIF_MIN_VALID_YEAR', 2020); // Before 2020 = garbage/corrupted EXIF
}
if (!defined('WEBCAM_EXIF_MAX_VALID_YEAR')) {
    define('WEBCAM_EXIF_MAX_VALID_YEAR', 2100); // After 2100 = garbage/corrupted EXIF
}

// exiftool execution (safety-critical: retry transient failures, timeout prevents hang)
if (!defined('EXIFTOOL_TIMEOUT_SECONDS')) {
    define('EXIFTOOL_TIMEOUT_SECONDS', 10);
}
if (!defined('EXIFTOOL_MAX_RETRIES')) {
    define('EXIFTOOL_MAX_RETRIES', 3);
}
if (!defined('EXIFTOOL_RETRY_DELAY_MS')) {
    define('EXIFTOOL_RETRY_DELAY_MS', 100);
}

// Filename timestamp validation: mtime window (hours) for parseFilenameTimestamp
// Tighter window reduces false positives from product IDs; ±12h covers timezone/upload delay
if (!defined('FILENAME_TIMESTAMP_MTIME_WINDOW_HOURS')) {
    define('FILENAME_TIMESTAMP_MTIME_WINDOW_HOURS', 12);
}

// Climate bounds for weather data validation (Earth extremes + 10% margin)
// Used to validate weather data quality and reject clearly invalid values
// Temperature: -89.2°C to 56.7°C (Vostok Station, Antarctica to Death Valley, USA)
if (!defined('CLIMATE_TEMP_MIN_C')) {
    define('CLIMATE_TEMP_MIN_C', -98.12); // -89.2 * 1.1
}
if (!defined('CLIMATE_TEMP_MAX_C')) {
    define('CLIMATE_TEMP_MAX_C', 62.37); // 56.7 * 1.1
}

// Wind Speed: 0 to 220 knots (113.2 m/s = ~220 kts, Mount Washington, USA)
if (!defined('CLIMATE_WIND_SPEED_MAX_KTS')) {
    define('CLIMATE_WIND_SPEED_MAX_KTS', 242); // 220 * 1.1
}

// Calm wind threshold (knots) - winds below this are considered "calm" in aviation
if (!defined('CALM_WIND_THRESHOLD_KTS')) {
    define('CALM_WIND_THRESHOLD_KTS', 3);
}

// Wind Direction: 0 to 360 degrees (no margin needed)
if (!defined('CLIMATE_WIND_DIRECTION_MIN')) {
    define('CLIMATE_WIND_DIRECTION_MIN', 0);
}
if (!defined('CLIMATE_WIND_DIRECTION_MAX')) {
    define('CLIMATE_WIND_DIRECTION_MAX', 360);
}

// Pressure: 25.68 to 32.00 inHg (870 to 1084 hPa)
if (!defined('CLIMATE_PRESSURE_MIN_INHG')) {
    define('CLIMATE_PRESSURE_MIN_INHG', 23.11); // 25.68 * 0.9
}
if (!defined('CLIMATE_PRESSURE_MAX_INHG')) {
    define('CLIMATE_PRESSURE_MAX_INHG', 35.20); // 32.00 * 1.1
}

// Humidity: 0 to 100% (no margin needed)
if (!defined('CLIMATE_HUMIDITY_MIN')) {
    define('CLIMATE_HUMIDITY_MIN', 0);
}
if (!defined('CLIMATE_HUMIDITY_MAX')) {
    define('CLIMATE_HUMIDITY_MAX', 100);
}

// Dewpoint: Same bounds as temperature, but must be <= temperature
if (!defined('CLIMATE_DEWPOINT_MIN_C')) {
    define('CLIMATE_DEWPOINT_MIN_C', -98.12); // Same as temperature
}
if (!defined('CLIMATE_DEWPOINT_MAX_C')) {
    define('CLIMATE_DEWPOINT_MAX_C', 62.37); // Same as temperature
}

// Dewpoint Spread: -20 to 50°C (allows sensor error, super-saturation)
if (!defined('CLIMATE_DEWPOINT_SPREAD_MIN_C')) {
    define('CLIMATE_DEWPOINT_SPREAD_MIN_C', -20.0);
}
if (!defined('CLIMATE_DEWPOINT_SPREAD_MAX_C')) {
    define('CLIMATE_DEWPOINT_SPREAD_MAX_C', 50.0);
}

// Precipitation: 0 to 71.85 inches/day (1825 mm/day, Foc-Foc, Réunion)
if (!defined('CLIMATE_PRECIP_MIN_INCHES_DAY')) {
    define('CLIMATE_PRECIP_MIN_INCHES_DAY', 0.0);
}
if (!defined('CLIMATE_PRECIP_MAX_INCHES_DAY')) {
    define('CLIMATE_PRECIP_MAX_INCHES_DAY', 79.04); // 71.85 * 1.1
}

// Visibility: 0 to 50 SM (reasonable aviation maximum)
if (!defined('CLIMATE_VISIBILITY_MIN_SM')) {
    define('CLIMATE_VISIBILITY_MIN_SM', 0.0);
}
if (!defined('CLIMATE_VISIBILITY_MAX_SM')) {
    define('CLIMATE_VISIBILITY_MAX_SM', 50.0);
}

// Ceiling: 0 to 50,000 ft AGL (reasonable aviation maximum)
if (!defined('CLIMATE_CEILING_MIN_FT')) {
    define('CLIMATE_CEILING_MIN_FT', 0);
}
if (!defined('CLIMATE_CEILING_MAX_FT')) {
    define('CLIMATE_CEILING_MAX_FT', 50000);
}

// Peak Gust (live): Must be >= wind_speed, max same as wind speed
if (!defined('CLIMATE_PEAK_GUST_MIN_KTS')) {
    define('CLIMATE_PEAK_GUST_MIN_KTS', 0);
}
if (!defined('CLIMATE_PEAK_GUST_MAX_KTS')) {
    define('CLIMATE_PEAK_GUST_MAX_KTS', 242); // Same as wind speed max
}

// Peak Gust Today: 0 to 242 knots (earth wind max + 10%)
if (!defined('CLIMATE_PEAK_GUST_TODAY_MIN_KTS')) {
    define('CLIMATE_PEAK_GUST_TODAY_MIN_KTS', 0);
}
if (!defined('CLIMATE_PEAK_GUST_TODAY_MAX_KTS')) {
    define('CLIMATE_PEAK_GUST_TODAY_MAX_KTS', 242);
}

// Gust Factor: 0 to 50 knots (reasonable gust spread range), null is acceptable
if (!defined('CLIMATE_GUST_FACTOR_MIN_KTS')) {
    define('CLIMATE_GUST_FACTOR_MIN_KTS', 0);
}
if (!defined('CLIMATE_GUST_FACTOR_MAX_KTS')) {
    define('CLIMATE_GUST_FACTOR_MAX_KTS', 50);
}

// Pressure Altitude: -2,200 to 33,000 ft (Earth extremes: -2,000 to 30,000 ft + 10%)
if (!defined('CLIMATE_PRESSURE_ALTITUDE_MIN_FT')) {
    define('CLIMATE_PRESSURE_ALTITUDE_MIN_FT', -2200); // -2000 * 1.1
}
if (!defined('CLIMATE_PRESSURE_ALTITUDE_MAX_FT')) {
    define('CLIMATE_PRESSURE_ALTITUDE_MAX_FT', 33000); // 30000 * 1.1
}

// Density Altitude: -5,500 to 38,500 ft (Earth extremes: -5,000 to 35,000 ft + 10%)
if (!defined('CLIMATE_DENSITY_ALTITUDE_MIN_FT')) {
    define('CLIMATE_DENSITY_ALTITUDE_MIN_FT', -5500); // -5000 * 1.1
}
if (!defined('CLIMATE_DENSITY_ALTITUDE_MAX_FT')) {
    define('CLIMATE_DENSITY_ALTITUDE_MAX_FT', 38500); // 35000 * 1.1
}

// Sentinel values for unlimited visibility/ceiling
// These values are outside normal climate bounds and represent "unlimited" conditions
// Used internally to differentiate between unlimited (sentinel) and failed (null)
if (!defined('UNLIMITED_VISIBILITY_SM')) {
    define('UNLIMITED_VISIBILITY_SM', 999.0);  // Sentinel for unlimited visibility in statute miles
}
if (!defined('UNLIMITED_CEILING_FT')) {
    define('UNLIMITED_CEILING_FT', 99999);     // Sentinel for unlimited ceiling in feet
}

// =============================================================================
// IMAGE FORMAT QUALITY SETTINGS
// =============================================================================
// Quality settings for webcam format generation (WebP, JPEG)
// These can be overridden in airports.json config section

// WebP quality (0-100 scale, higher = better quality, larger file)
// Recommended range: 85-95 for high quality
// - 50-60: Noticeable artifacts, small files
// - 75-80: Good quality, moderate compression
// - 85-90: High quality, larger files (recommended for aviation safety)
// - 95-100: Near-lossless / lossless (very large)
if (!defined('WEBCAM_WEBP_QUALITY')) {
    define('WEBCAM_WEBP_QUALITY', 90);
}

// WebP compression level (0-6, higher = better compression but slower)
// Default 6 is fine for background processing
if (!defined('WEBCAM_WEBP_COMPRESSION_LEVEL')) {
    define('WEBCAM_WEBP_COMPRESSION_LEVEL', 6);
}

// JPEG quality (1-31 scale for ffmpeg, lower = better quality)
// This is the inverse of typical JPEG quality scales!
// - 1: Maximum quality (recommended for aviation safety)
// - 2-3: Very high quality
// - 3-5: High quality
// - 10+: Visible artifacts
if (!defined('WEBCAM_JPEG_QUALITY')) {
    define('WEBCAM_JPEG_QUALITY', 1);
}

/**
 * Master allowlist of push upload image file extensions (lowercase, no dot).
 *
 * The acquisition worker only globs these types; config and debris cleanup may use subsets
 * but must not list extensions outside this set.
 *
 * @return array<int, string>
 */
function push_upload_master_image_extensions(): array
{
    return ['jpg', 'jpeg', 'png', 'webp'];
}

// Upload health probe (production FTPS/SFTP watchdog)
if (!defined('UPLOAD_HEALTH_PROBE_INTERVAL_MIN_SEC')) {
    define('UPLOAD_HEALTH_PROBE_INTERVAL_MIN_SEC', 15);
}
if (!defined('UPLOAD_HEALTH_PROBE_INTERVAL_MAX_SEC')) {
    define('UPLOAD_HEALTH_PROBE_INTERVAL_MAX_SEC', 300);
}
if (!defined('UPLOAD_HEALTH_PROBE_INTERVAL_DEFAULT_SEC')) {
    define('UPLOAD_HEALTH_PROBE_INTERVAL_DEFAULT_SEC', 30);
}
if (!defined('UPLOAD_HEALTH_PROBE_STALE_GRACE_SEC')) {
    define('UPLOAD_HEALTH_PROBE_STALE_GRACE_SEC', 15);
}
if (!defined('UPLOAD_HEALTH_PROBE_FAIL_STREAK_THRESHOLD')) {
    define('UPLOAD_HEALTH_PROBE_FAIL_STREAK_THRESHOLD', 2);
}
if (!defined('UPLOAD_HEALTH_PROBE_RESTART_MIN_INTERVAL_SEC')) {
    define('UPLOAD_HEALTH_PROBE_RESTART_MIN_INTERVAL_SEC', 1800);
}
if (!defined('UPLOAD_HEALTH_PROBE_WATCHDOG_LOOP_SEC')) {
    define('UPLOAD_HEALTH_PROBE_WATCHDOG_LOOP_SEC', 50);
}
if (!defined('UPLOAD_HEALTH_PROBE_FILE_PREFIX')) {
    define('UPLOAD_HEALTH_PROBE_FILE_PREFIX', 'aviationwx-probe-');
}
if (!defined('UPLOAD_HEALTH_PROBE_FTP_NAMESPACE')) {
    define('UPLOAD_HEALTH_PROBE_FTP_NAMESPACE', '_probe');
}

// Push FTP/SFTP credentials (push_config and upload_health_probe)
if (!defined('PUSH_UPLOAD_USERNAME_MAX_LENGTH')) {
    define('PUSH_UPLOAD_USERNAME_MAX_LENGTH', 14);
}
if (!defined('PUSH_UPLOAD_PASSWORD_LENGTH')) {
    define('PUSH_UPLOAD_PASSWORD_LENGTH', 14);
}

