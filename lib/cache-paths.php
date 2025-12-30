<?php
/**
 * Cache Directory Structure
 * 
 * Centralized cache path definitions for the entire application.
 * All cache paths should be referenced through these constants/functions.
 * 
 * Directory Structure:
 * cache/
 * ├── weather/
 * │   ├── {airport}.json           # Current weather data
 * │   └── history/
 * │       └── {airport}.json       # 24-hour weather history
 * ├── webcams/
 * │   └── {airport}/{camIndex}/
 * │       ├── {timestamp}_{variant}.{format}  # Timestamped images
 * │       ├── current.{format}     # Symlink to latest
 * │       ├── history/             # Historical frames
 * │       └── state.json           # Push webcam state (last_processed)
 * ├── uploads/
 * │   └── {airport}/{username}/    # Push webcam FTP uploads
 * ├── notam/
 * │   └── {airport}.json           # NOTAM cache
 * ├── partners/
 * │   └── {hash}.{ext}             # Partner logo cache
 * ├── rate_limits/                 # Rate limit state files
 * ├── backoff.json                 # Circuit breaker state
 * ├── peak_gusts.json              # Daily peak gust tracking
 * ├── temp_extremes.json           # Daily temperature extremes
 * ├── outage_{airport}.json        # Outage detection state
 * └── memory_history.json          # Memory usage tracking
 */

// =============================================================================
// BASE CACHE DIRECTORY
// =============================================================================
// The base cache directory - all paths are relative to this
// In Docker: /var/www/html/cache (symlinked to /tmp/aviationwx-cache)
// In development: {project_root}/cache

if (!defined('CACHE_BASE_DIR')) {
    define('CACHE_BASE_DIR', dirname(__DIR__) . '/cache');
}

// =============================================================================
// WEATHER CACHE PATHS
// =============================================================================

if (!defined('CACHE_WEATHER_DIR')) {
    define('CACHE_WEATHER_DIR', CACHE_BASE_DIR . '/weather');
}

if (!defined('CACHE_WEATHER_HISTORY_DIR')) {
    define('CACHE_WEATHER_HISTORY_DIR', CACHE_WEATHER_DIR . '/history');
}

/**
 * Get path to current weather cache file for an airport
 * 
 * @param string $airportId Airport identifier (e.g., 'kczk')
 * @return string Full path to weather cache file
 */
function getWeatherCachePath(string $airportId): string {
    return CACHE_WEATHER_DIR . '/' . strtolower($airportId) . '.json';
}

/**
 * Get path to weather history file for an airport
 * 
 * @param string $airportId Airport identifier (e.g., 'kczk')
 * @return string Full path to weather history file
 */
function getWeatherHistoryCachePath(string $airportId): string {
    return CACHE_WEATHER_HISTORY_DIR . '/' . strtolower($airportId) . '.json';
}

/**
 * Get path to outage detection state file for an airport
 * 
 * @param string $airportId Airport identifier (e.g., 'kczk')
 * @return string Full path to outage state file
 */
function getOutageCachePath(string $airportId): string {
    return CACHE_BASE_DIR . '/outage_' . strtolower($airportId) . '.json';
}

// =============================================================================
// WEBCAM CACHE PATHS
// =============================================================================

if (!defined('CACHE_WEBCAMS_DIR')) {
    define('CACHE_WEBCAMS_DIR', CACHE_BASE_DIR . '/webcams');
}

/**
 * Get base directory for a specific airport's webcam cache
 * 
 * @param string $airportId Airport identifier (e.g., 'kczk')
 * @return string Full path to airport's webcam directory
 */
function getWebcamAirportDir(string $airportId): string {
    return CACHE_WEBCAMS_DIR . '/' . strtolower($airportId);
}

/**
 * Get directory for a specific camera's cache files
 * 
 * @param string $airportId Airport identifier (e.g., 'kczk')
 * @param int $camIndex Camera index (0-based)
 * @return string Full path to camera's cache directory
 */
function getWebcamCameraDir(string $airportId, int $camIndex): string {
    return getWebcamAirportDir($airportId) . '/' . $camIndex;
}

/**
 * Get path to camera history directory
 * 
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index (0-based)
 * @return string Full path to camera's history directory
 */
function getWebcamHistoryDir(string $airportId, int $camIndex): string {
    return getWebcamCameraDir($airportId, $camIndex) . '/history';
}

/**
 * Get path to camera state file (for push webcam last_processed tracking)
 * 
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index (0-based)
 * @return string Full path to camera state file
 */
function getWebcamStatePath(string $airportId, int $camIndex): string {
    return getWebcamCameraDir($airportId, $camIndex) . '/state.json';
}

/**
 * Get path to current webcam symlink
 * 
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index (0-based)
 * @param string $format Image format (jpg, webp, avif)
 * @return string Full path to current symlink
 */
function getWebcamCurrentPath(string $airportId, int $camIndex, string $format = 'jpg'): string {
    return getWebcamCameraDir($airportId, $camIndex) . '/current.' . $format;
}

/**
 * Get path to timestamped webcam image
 * 
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index (0-based)
 * @param int $timestamp Unix timestamp
 * @param string $variant Image variant (primary, full, large, medium, small, thumb)
 * @param string $format Image format (jpg, webp, avif)
 * @return string Full path to timestamped image
 */
function getWebcamTimestampedPath(string $airportId, int $camIndex, int $timestamp, string $variant, string $format): string {
    return getWebcamCameraDir($airportId, $camIndex) . '/' . $timestamp . '_' . $variant . '.' . $format;
}

/**
 * Get path to staging file for webcam processing
 * 
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index (0-based)
 * @param string $format Image format
 * @return string Full path to staging file
 */
function getWebcamStagingPath(string $airportId, int $camIndex, string $format = 'jpg'): string {
    return getWebcamCameraDir($airportId, $camIndex) . '/staging.' . $format . '.tmp';
}

// =============================================================================
// PUSH WEBCAM UPLOAD PATHS (FTP/SFTP chroot)
// =============================================================================

if (!defined('CACHE_UPLOADS_DIR')) {
    define('CACHE_UPLOADS_DIR', CACHE_BASE_DIR . '/uploads');
}

/**
 * Get FTP upload directory for a push webcam
 * 
 * @param string $airportId Airport identifier
 * @param string $username FTP username
 * @return string Full path to upload directory
 */
function getWebcamUploadDir(string $airportId, string $username): string {
    return CACHE_UPLOADS_DIR . '/' . strtolower($airportId) . '/' . $username;
}

// =============================================================================
// NOTAM CACHE PATHS
// =============================================================================

if (!defined('CACHE_NOTAM_DIR')) {
    define('CACHE_NOTAM_DIR', CACHE_BASE_DIR . '/notam');
}

/**
 * Get path to NOTAM cache file for an airport
 * 
 * @param string $airportId Airport identifier
 * @return string Full path to NOTAM cache file
 */
function getNotamCachePath(string $airportId): string {
    return CACHE_NOTAM_DIR . '/' . strtolower($airportId) . '.json';
}

/**
 * Get path to NOTAM token cache file
 * 
 * @return string Full path to token cache file
 */
function getNotamTokenCachePath(): string {
    return CACHE_NOTAM_DIR . '/token.json';
}

// =============================================================================
// PARTNER LOGO CACHE PATHS
// =============================================================================

if (!defined('CACHE_PARTNERS_DIR')) {
    define('CACHE_PARTNERS_DIR', CACHE_BASE_DIR . '/partners');
}

/**
 * Get path to cached partner logo
 * 
 * @param string $hash URL hash
 * @param string $extension File extension
 * @return string Full path to cached logo
 */
function getPartnerLogoCachePath(string $hash, string $extension): string {
    return CACHE_PARTNERS_DIR . '/' . $hash . '.' . $extension;
}

// =============================================================================
// RATE LIMIT PATHS
// =============================================================================

if (!defined('CACHE_RATE_LIMITS_DIR')) {
    define('CACHE_RATE_LIMITS_DIR', CACHE_BASE_DIR . '/rate_limits');
}

/**
 * Get path to rate limit state file
 * 
 * @param string $identifier Rate limit identifier (e.g., IP address hash)
 * @return string Full path to rate limit file
 */
function getRateLimitPath(string $identifier): string {
    return CACHE_RATE_LIMITS_DIR . '/' . $identifier . '.json';
}

// =============================================================================
// STATE FILES (in cache root)
// =============================================================================

if (!defined('CACHE_BACKOFF_FILE')) {
    define('CACHE_BACKOFF_FILE', CACHE_BASE_DIR . '/backoff.json');
}

if (!defined('CACHE_PEAK_GUSTS_FILE')) {
    define('CACHE_PEAK_GUSTS_FILE', CACHE_BASE_DIR . '/peak_gusts.json');
}

if (!defined('CACHE_TEMP_EXTREMES_FILE')) {
    define('CACHE_TEMP_EXTREMES_FILE', CACHE_BASE_DIR . '/temp_extremes.json');
}

if (!defined('CACHE_MEMORY_HISTORY_FILE')) {
    define('CACHE_MEMORY_HISTORY_FILE', CACHE_BASE_DIR . '/memory_history.json');
}

if (!defined('CACHE_PUBLIC_API_HEALTH_FILE')) {
    define('CACHE_PUBLIC_API_HEALTH_FILE', CACHE_BASE_DIR . '/public_api_health.json');
}

/**
 * Get path to outage state file for an airport
 * 
 * @param string $airportId Airport identifier
 * @return string Full path to outage state file
 */
function getOutageStatePath(string $airportId): string {
    return CACHE_BASE_DIR . '/outage_' . strtolower($airportId) . '.json';
}

// =============================================================================
// EXTERNAL DATA CACHES
// =============================================================================

if (!defined('CACHE_OURAIRPORTS_FILE')) {
    define('CACHE_OURAIRPORTS_FILE', CACHE_BASE_DIR . '/ourairports_data.json');
}

if (!defined('CACHE_ICAO_AIRPORTS_FILE')) {
    define('CACHE_ICAO_AIRPORTS_FILE', CACHE_BASE_DIR . '/icao_airports.json');
}

if (!defined('CACHE_IATA_MAPPING_FILE')) {
    define('CACHE_IATA_MAPPING_FILE', CACHE_BASE_DIR . '/iata_to_icao_mapping.json');
}

if (!defined('CACHE_FAA_MAPPING_FILE')) {
    define('CACHE_FAA_MAPPING_FILE', CACHE_BASE_DIR . '/faa_to_icao_mapping.json');
}

// =============================================================================
// HELPER FUNCTIONS
// =============================================================================

/**
 * Ensure a cache directory exists with proper permissions
 * 
 * @param string $path Directory path to create
 * @param int $mode Directory permissions (default 0755)
 * @return bool True if directory exists or was created successfully
 */
function ensureCacheDir(string $path): bool {
    if (is_dir($path)) {
        return true;
    }
    return @mkdir($path, 0755, true);
}

/**
 * Ensure all required cache directories exist
 * Call this during application bootstrap or deployment
 * 
 * @return array List of directories and their creation status
 */
function ensureAllCacheDirs(): array {
    $dirs = [
        CACHE_BASE_DIR,
        CACHE_WEATHER_DIR,
        CACHE_WEATHER_HISTORY_DIR,
        CACHE_WEBCAMS_DIR,
        CACHE_UPLOADS_DIR,
        CACHE_NOTAM_DIR,
        CACHE_PARTNERS_DIR,
        CACHE_RATE_LIMITS_DIR,
    ];
    
    $results = [];
    foreach ($dirs as $dir) {
        $results[$dir] = ensureCacheDir($dir);
    }
    return $results;
}

/**
 * Get all cache paths for an airport (useful for cleanup)
 * 
 * @param string $airportId Airport identifier
 * @return array Associative array of cache paths for this airport
 */
function getAirportCachePaths(string $airportId): array {
    $airportId = strtolower($airportId);
    return [
        'weather' => getWeatherCachePath($airportId),
        'weather_history' => getWeatherHistoryCachePath($airportId),
        'webcams_dir' => getWebcamAirportDir($airportId),
        'uploads_dir' => CACHE_UPLOADS_DIR . '/' . $airportId,
        'notam' => getNotamCachePath($airportId),
        'outage' => getOutageStatePath($airportId),
    ];
}

