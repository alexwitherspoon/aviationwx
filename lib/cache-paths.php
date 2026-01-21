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
 * │       ├── {timestamp}_{variant}.{format}  # Timestamped images (current & historical)
 * │       ├── current.{format}     # Symlink to latest timestamped image
 * │       └── state.json           # Push webcam state (last_processed)
 * ├── uploads/
 * │   └── {airport}/{username}/    # Push webcam uploads (ftp:www-data 2775)
 * ├── notam/
 * │   └── {airport}.json           # NOTAM cache
 * ├── partners/
 * │   └── {hash}.{ext}             # Partner logo cache
 * ├── map_tiles/
 * │   └── {layer}/
 * │       └── {z}_{x}_{y}.png      # Cached map tiles (OpenWeatherMap proxy)
 * ├── rate_limits/                 # Rate limit state files
 * ├── backoff.json                 # Circuit breaker state
 * ├── peak_gusts.json              # Daily peak gust tracking
 * ├── temp_extremes.json           # Daily temperature extremes
 * ├── outage_{airport}.json        # Outage detection state
 * └── memory_history.json          # Memory usage tracking
 * 
 * Note: Webcam history is stored directly in the camera directory (unified storage).
 * Retention is controlled by webcam_history_max_frames config setting.
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
 * @deprecated History is now stored directly in the camera cache directory.
 *             Use getWebcamCameraDir() instead. This function is kept for
 *             backward compatibility with tests and migration scripts.
 * 
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index (0-based)
 * @return string Full path to legacy camera history directory
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
 * @param string $format Image format (jpg, webp)
 * @return string Full path to current symlink
 */
function getWebcamCurrentPath(string $airportId, int $camIndex, string $format = 'jpg'): string {
    return getWebcamCameraDir($airportId, $camIndex) . '/current.' . $format;
}

/**
 * Get path to webcam cache symlink (alias for getWebcamCurrentPath)
 * 
 * This function is provided for backwards compatibility with existing code.
 * New code should use getWebcamCurrentPath() instead.
 * 
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index (0-based)
 * @param string $format Image format (jpg, webp)
 * @return string Full path to current symlink
 */
function getCacheSymlinkPath(string $airportId, int $camIndex, string $format): string {
    return getWebcamCurrentPath($airportId, $camIndex, $format);
}

/**
 * Get path to timestamped webcam image
 * 
 * @deprecated Use getWebcamVariantPath() or getWebcamOriginalTimestampedPath() instead
 * 
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index (0-based)
 * @param int $timestamp Unix timestamp
 * @param string $variant Image variant (primary, full, large, medium, small, thumb)
 * @param string $format Image format (jpg, webp)
 * @return string Full path to timestamped image
 */
function getWebcamTimestampedPath(string $airportId, int $camIndex, int $timestamp, string $variant, string $format): string {
    return getWebcamCameraDir($airportId, $camIndex) . '/' . $timestamp . '_' . $variant . '.' . $format;
}

/**
 * Get path to timestamped original webcam image
 * 
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index (0-based)
 * @param int $timestamp Unix timestamp
 * @param string $format Image format (jpg, webp)
 * @return string Full path to timestamped original image
 */
function getWebcamOriginalTimestampedPath(string $airportId, int $camIndex, int $timestamp, string $format): string {
    return getWebcamCameraDir($airportId, $camIndex) . '/' . $timestamp . '_original.' . $format;
}

/**
 * Get path to timestamped variant webcam image (by height)
 * 
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index (0-based)
 * @param int $timestamp Unix timestamp
 * @param int $height Variant height in pixels
 * @param string $format Image format (jpg, webp)
 * @return string Full path to timestamped variant image
 */
function getWebcamVariantPath(string $airportId, int $camIndex, int $timestamp, int $height, string $format): string {
    return getWebcamCameraDir($airportId, $camIndex) . '/' . $timestamp . '_' . $height . '.' . $format;
}

/**
 * Get path to original webcam symlink
 * 
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index (0-based)
 * @param string $format Image format (jpg, webp)
 * @return string Full path to original symlink
 */
function getWebcamOriginalSymlinkPath(string $airportId, int $camIndex, string $format = 'jpg'): string {
    return getWebcamCameraDir($airportId, $camIndex) . '/original.' . $format;
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
// PUSH WEBCAM UPLOAD PATHS (FTP/SFTP)
// =============================================================================

if (!defined('CACHE_UPLOADS_DIR')) {
    define('CACHE_UPLOADS_DIR', CACHE_BASE_DIR . '/uploads');
}

/**
 * Get upload directory for a push webcam
 * 
 * This is the directory where FTP/SFTP cameras upload files.
 * Both protocols use the same directory with shared permissions:
 * - FTP: vsftpd local_root points here
 * - SFTP: User home directory (no chroot for simpler camera configuration)
 * 
 * Directory owned by ftp:www-data with setgid (2775) so both FTP virtual users
 * and SFTP system users (in www-data group) can write. Uploaded files inherit
 * www-data group for processor access.
 * 
 * @param string $airportId Airport identifier
 * @param string $username FTP/SFTP username
 * @return string Full path to upload directory
 */
function getWebcamUploadDir(string $airportId, string $username): string {
    return CACHE_UPLOADS_DIR . '/' . strtolower($airportId) . '/' . $username;
}

/**
 * Get quarantine directory for rejected webcam images
 * 
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index (0-based)
 * @return string Full path to quarantine directory
 */
function getWebcamQuarantineDir(string $airportId, int $camIndex): string {
    return CACHE_WEBCAMS_DIR . '/quarantine/' . strtolower($airportId) . '/' . $camIndex;
}

// =============================================================================
// TEMPORARY AND LOCK FILE PATHS
// =============================================================================

if (!defined('TEMP_DIR')) {
    define('TEMP_DIR', sys_get_temp_dir());
}

/**
 * Get lock file path for push webcam processing
 * 
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index (0-based)
 * @return string Full path to lock file
 */
function getPushWebcamLockPath(string $airportId, int $camIndex): string {
    return TEMP_DIR . '/push_webcam_lock_' . strtolower($airportId) . '_' . $camIndex . '.lock';
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
// METRICS CACHE PATHS
// =============================================================================

if (!defined('CACHE_METRICS_DIR')) {
    define('CACHE_METRICS_DIR', CACHE_BASE_DIR . '/metrics');
}

if (!defined('CACHE_METRICS_HOURLY_DIR')) {
    define('CACHE_METRICS_HOURLY_DIR', CACHE_METRICS_DIR . '/hourly');
}

if (!defined('CACHE_METRICS_DAILY_DIR')) {
    define('CACHE_METRICS_DAILY_DIR', CACHE_METRICS_DIR . '/daily');
}

if (!defined('CACHE_METRICS_WEEKLY_DIR')) {
    define('CACHE_METRICS_WEEKLY_DIR', CACHE_METRICS_DIR . '/weekly');
}

/**
 * Get path to hourly metrics file
 * 
 * @param string $hourId Hour identifier (e.g., '2025-12-31-14' for 14:00 UTC)
 * @return string Full path to hourly metrics file
 */
function getMetricsHourlyPath(string $hourId): string {
    return CACHE_METRICS_HOURLY_DIR . '/' . $hourId . '.json';
}

/**
 * Get path to daily metrics file
 * 
 * @param string $dateId Date identifier (e.g., '2025-12-31')
 * @return string Full path to daily metrics file
 */
function getMetricsDailyPath(string $dateId): string {
    return CACHE_METRICS_DAILY_DIR . '/' . $dateId . '.json';
}

/**
 * Get path to weekly metrics file
 * 
 * @param string $weekId Week identifier (e.g., '2025-W01')
 * @return string Full path to weekly metrics file
 */
function getMetricsWeeklyPath(string $weekId): string {
    return CACHE_METRICS_WEEKLY_DIR . '/' . $weekId . '.json';
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
// MAP TILES CACHE (OpenWeatherMap Proxy)
// =============================================================================

if (!defined('CACHE_MAP_TILES_DIR')) {
    define('CACHE_MAP_TILES_DIR', CACHE_BASE_DIR . '/map_tiles');
}

/**
 * Get directory for map tile cache (by layer type)
 * 
 * @param string $layer Layer type (e.g., 'clouds_new')
 * @return string Full path to tile layer directory
 */
function getMapTileLayerDir(string $layer): string {
    return CACHE_MAP_TILES_DIR . '/' . $layer;
}

/**
 * Get path to cached map tile
 * 
 * @param string $layer Layer type (e.g., 'clouds_new')
 * @param int $z Zoom level
 * @param int $x Tile X coordinate
 * @param int $y Tile Y coordinate
 * @return string Full path to cached tile
 */
function getMapTileCachePath(string $layer, int $z, int $x, int $y): string {
    return getMapTileLayerDir($layer) . '/' . $z . '_' . $x . '_' . $y . '.png';
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
        CACHE_METRICS_DIR,
        CACHE_METRICS_HOURLY_DIR,
        CACHE_METRICS_DAILY_DIR,
        CACHE_METRICS_WEEKLY_DIR,
        CACHE_MAP_TILES_DIR,
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

