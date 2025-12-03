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
if (!defined('STALE_WARNING_HOURS')) {
    define('STALE_WARNING_HOURS', 1);
}
if (!defined('STALE_WHILE_REVALIDATE_SECONDS')) {
    define('STALE_WHILE_REVALIDATE_SECONDS', 300);
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

// Circuit breaker / backoff
if (!defined('BACKOFF_BASE_SECONDS')) {
    define('BACKOFF_BASE_SECONDS', 60);
}
if (!defined('BACKOFF_MAX_TRANSIENT')) {
    define('BACKOFF_MAX_TRANSIENT', 3600); // 1 hour
}
if (!defined('BACKOFF_MAX_PERMANENT')) {
    define('BACKOFF_MAX_PERMANENT', 7200); // 2 hours
}
if (!defined('BACKOFF_MAX_FAILURES')) {
    define('BACKOFF_MAX_FAILURES', 5);
}

// Background refresh
if (!defined('BACKGROUND_REFRESH_MAX_TIME')) {
    define('BACKGROUND_REFRESH_MAX_TIME', 40); // Leave 5s buffer before script timeout
}

