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
if (!defined('DEFAULT_WEATHER_REFRESH')) {
    define('DEFAULT_WEATHER_REFRESH', 60);
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
if (!defined('NOTAM_CACHE_TTL_DEFAULT')) {
    define('NOTAM_CACHE_TTL_DEFAULT', 3600); // 1 hour
}
if (!defined('NOTAM_TOKEN_EXPIRY_BUFFER')) {
    define('NOTAM_TOKEN_EXPIRY_BUFFER', 60); // Refresh token 1 min before expiry
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
if (!defined('NOTAM_GEO_RADIUS_DEFAULT')) {
    define('NOTAM_GEO_RADIUS_DEFAULT', 10); // 10 NM default radius
}
if (!defined('NOTAM_RATE_LIMIT_SECONDS')) {
    define('NOTAM_RATE_LIMIT_SECONDS', 1); // 1 request per second
}
if (!defined('RATE_LIMIT_CONFIG_GENERATOR_MAX')) {
    define('RATE_LIMIT_CONFIG_GENERATOR_MAX', 10);
}
if (!defined('RATE_LIMIT_CONFIG_GENERATOR_WINDOW')) {
    define('RATE_LIMIT_CONFIG_GENERATOR_WINDOW', 3600);
}
if (!defined('RATE_LIMIT_APCU_TTL_BUFFER')) {
    define('RATE_LIMIT_APCU_TTL_BUFFER', 10);
}

// File operations
if (!defined('FILE_LOCK_STALE_SECONDS')) {
    define('FILE_LOCK_STALE_SECONDS', 300); // 5 minutes
}
if (!defined('CACHE_FILE_MAX_SIZE')) {
    define('CACHE_FILE_MAX_SIZE', 5242880); // 5MB
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
if (!defined('SECONDS_PER_WEEK')) {
    define('SECONDS_PER_WEEK', 604800);
}

// Debug log path - works in both container and host
// Debug log path - uses file-based logging directory when available, otherwise falls back to cache
// In container: /var/log/aviationwx/debug.log (consistent with other services)
// On host: use .cursor/debug.log relative to project root
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
        $debugLogDir = '/var/www/html/cache/.cursor';
        if (!is_dir($debugLogDir)) {
            @mkdir($debugLogDir, 0755, true);
        }
        define('DEBUG_LOG_PATH', $debugLogDir . '/debug.log');
    } elseif (file_exists(dirname(__DIR__) . '/.cursor')) {
        // On host, use .cursor directory
        define('DEBUG_LOG_PATH', dirname(__DIR__) . '/.cursor/debug.log');
    } else {
        // Last resort: try to create .cursor in cache or use container path
        $fallbackDir = dirname(__DIR__) . '/cache/.cursor';
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
        return defined('DEBUG_LOG_PATH') ? DEBUG_LOG_PATH : (dirname(__DIR__) . '/.cursor/debug.log');
    }
}

// Helper function to get host-accessible debug log path (for reading logs from host)
// This checks both the project .cursor directory and the Docker-mounted log/cache locations
if (!function_exists('get_debug_log_path_host')) {
    function get_debug_log_path_host(): string {
        // First check if we're on the host and the project .cursor directory exists
        $projectLogPath = dirname(__DIR__) . '/.cursor/debug.log';
        if (file_exists($projectLogPath)) {
            return $projectLogPath;
        }
        // Check Docker log directory (if file-based logging is enabled, logs are in /var/log/aviationwx)
        // This maps to the container's log directory, but we need to check if it's accessible from host
        // For Docker, we'd need to check the container's log directory via docker exec or volume mount
        // For now, check the cache location as fallback (backward compatibility)
        $dockerCacheLogPath = '/tmp/aviationwx-cache/.cursor/debug.log';
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
// A healthy webcam image will NEVER be all one color - even fog/night/snow has variance
if (!defined('WEBCAM_ERROR_UNIFORM_COLOR_VARIANCE_THRESHOLD')) {
    define('WEBCAM_ERROR_UNIFORM_COLOR_VARIANCE_THRESHOLD', 25); // Max channel variance <25 = essentially one color
}
if (!defined('WEBCAM_ERROR_UNIFORM_COLOR_SAMPLE_SIZE')) {
    define('WEBCAM_ERROR_UNIFORM_COLOR_SAMPLE_SIZE', 50); // Only need ~50 samples for this check
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
// - Severely pixelated/corrupted: <2 (essentially flat/broken)
//
// Thresholds are set conservatively low to avoid false positives
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
    define('WEBCAM_PIXELATION_THRESHOLD_NIGHT', 2); // Night: very conservative (fog/dark conditions have low variance ~2-8)
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

