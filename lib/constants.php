<?php
/**
 * Application Constants
 * Centralized constants for timeouts, limits, and configuration values
 */

// Default refresh intervals (seconds)
if (!defined('DEFAULT_WEBCAM_REFRESH')) {
    define('DEFAULT_WEBCAM_REFRESH', 60);
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

// Staleness thresholds
if (!defined('MAX_STALE_HOURS')) {
    define('MAX_STALE_HOURS', 3);
}
if (!defined('DATA_OUTAGE_BANNER_HOURS')) {
    define('DATA_OUTAGE_BANNER_HOURS', 1.5); // Show outage banner when all sources are stale for this duration
}
if (!defined('STALE_WHILE_REVALIDATE_SECONDS')) {
    define('STALE_WHILE_REVALIDATE_SECONDS', 300);
}

// Weather staleness thresholds
// METAR-only source thresholds (METARs are published hourly, so hour-based thresholds are appropriate)
if (!defined('WEATHER_STALENESS_WARNING_HOURS_METAR')) {
    define('WEATHER_STALENESS_WARNING_HOURS_METAR', 1); // Warning at 1 hour for METAR-only sources
}
if (!defined('WEATHER_STALENESS_ERROR_HOURS_METAR')) {
    define('WEATHER_STALENESS_ERROR_HOURS_METAR', 2); // Error at 2 hours for METAR-only sources
}

// Weather staleness multipliers (for non-METAR sources - Tempest, Ambient, WeatherLink, etc.)
// These use multiplier-based thresholds similar to webcams
if (!defined('WEATHER_STALENESS_WARNING_MULTIPLIER')) {
    define('WEATHER_STALENESS_WARNING_MULTIPLIER', 5); // Warning at 5x refresh interval
}
if (!defined('WEATHER_STALENESS_ERROR_MULTIPLIER')) {
    define('WEATHER_STALENESS_ERROR_MULTIPLIER', 10); // Error at 10x refresh interval
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
    define('RATE_LIMIT_WEATHER_MAX', 60);
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
if (!defined('NOTAM_STALE_THRESHOLD')) {
    define('NOTAM_STALE_THRESHOLD', 900); // 15 minutes (consider stale)
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
    define('PLACEHOLDER_CACHE_TTL', 3600); // 1 hour
}
if (!defined('PARTNER_LOGO_CACHE_TTL')) {
    define('PARTNER_LOGO_CACHE_TTL', 2592000); // 30 days
}

// Circuit breaker / backoff
if (!defined('BACKOFF_BASE_SECONDS')) {
    define('BACKOFF_BASE_SECONDS', 60);
}
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
if (!defined('WEBCAM_STALENESS_WARNING_MULTIPLIER')) {
    define('WEBCAM_STALENESS_WARNING_MULTIPLIER', 5); // Warning at 5x refresh interval
}
if (!defined('WEBCAM_STALENESS_ERROR_MULTIPLIER')) {
    define('WEBCAM_STALENESS_ERROR_MULTIPLIER', 10); // Error at 10x refresh interval
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

