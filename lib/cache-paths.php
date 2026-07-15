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
 * │       ├── {YYYY-MM-DD}/{HH}/   # Date/hour subdirs (~500 files each); production uses setgid on webcams/
 * │       │   └── {timestamp}_{variant}.{format}
 * │       ├── current.{format}     # Symlink to latest timestamped image
 * │       ├── pull_metadata.json   # Pull cameras: ETag + checksum for conditional/unchanged skip
 * │       └── state.json           # Push webcam state (last_processed)
 * ├── ftp/
 * │   ├── {airport}/{username}/    # FTP uploads (ftp:www-data 2775)
 * │   └── {namespace}/{username}/  # Upload health probe only (UPLOAD_HEALTH_PROBE_FTP_NAMESPACE)
 * 
 * SFTP uploads are stored separately in /var/sftp/ (not under cache/)
 * because SSH chroot requires ALL parent directories to be root-owned.
 * /var/sftp/{username}/            # SFTP chroot (root:root 755)
 * /var/sftp/{username}/files/      # SFTP uploads (ftp:www-data 2775)
 * ├── notam/
 * │   └── {airport}.json           # NOTAM cache
 * ├── station-power/
 * │   └── {airport}.json           # Canonical station power snapshot (provider-agnostic)
 * ├── partners/
 * │   ├── {hash}.{ext}             # Partner logo image cache (remote URLs)
 * │   └── lum/                     # Logo luminance metadata (contrast tiles)
 * │       └── {prefix}/
 * │           └── {hash}.json      # Keyed by absolute image path; invalidated by mtime
 * ├── map_tiles/
 * │   └── {layer}/{z}/{x}/
 * │       └── {y}.png              # Hierarchical tile cache (limits files per dir)
 * ├── rate_limits/
 * │   └── {prefix}/                # First 2 chars of hash (git-style sharding)
 * │       └── {hash}.json          # Rate limit state files
 * ├── upstream-limits/
 * │   └── {prefix}/                # Per-credential upstream token buckets (flock)
 * │       └── {fingerprint}.json
 * ├── backoff.json                 # Circuit breaker state
 * ├── operations_snapshot.json   # Public API GET /v1/operations (scheduler)
 * ├── peak_gusts/                  # Per-airport daily peak gust tracking
 * │   └── {airport}.json
 * ├── temp_extremes/               # Per-airport daily temperature extremes
 * │   └── {airport}.json
 * ├── outage_{airport}.json        # Outage detection state
 * ├── nws-points/                  # NWS /points/{lat},{lon} metadata (12h TTL)
 * ├── metar-bulk/                  # AWC METAR CSV.gz slices (scheduler refresh)
 * │   ├── stations/{ICAO}.json     # One-element JSON array per station (METAR API shape)
 * │   └── tmp/                     # Download scratch (deleted after ingest)
 * ├── airport_country_resolution.json  # Geometry-derived ISO per airport (scheduler)
 * ├── nasr/
 * │   ├── nasr_apt.json                # FAA NASR APT runway performance cache (full US set)
 * │   ├── nasr_apt_configured.json     # Slice for airports.json only (runtime load)
 * │   └── nasr_meta.json               # NASR fetch metadata
 * └── memory_history.json          # Memory usage tracking
 * 
 * Note: Webcam history is stored directly in the camera directory (unified storage).
 * Retention is controlled by webcam_history_max_frames config setting.
 */

// Metrics lock / spill schema basenames and related defaults live in lib/constants.php; load them here
// so this file stays the single include for cache paths without duplicating defaults.
require_once __DIR__ . '/constants.php';

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
// METAR BULK CACHE (gzip ingest, per-ICAO JSON under cache/metar-bulk/stations/)
// =============================================================================

if (!defined('CACHE_METAR_BULK_DIR')) {
    define('CACHE_METAR_BULK_DIR', CACHE_BASE_DIR . '/metar-bulk');
}

function getMetarBulkCacheDir(): string
{
    if (isset($GLOBALS['metarBulkTestCacheRoot'])
        && is_string($GLOBALS['metarBulkTestCacheRoot'])
        && $GLOBALS['metarBulkTestCacheRoot'] !== ''
    ) {
        return rtrim($GLOBALS['metarBulkTestCacheRoot'], '/');
    }

    return CACHE_METAR_BULK_DIR;
}

function getMetarBulkStationsDir(): string
{
    return getMetarBulkCacheDir() . '/stations';
}

function getMetarBulkTempDir(): string
{
    return getMetarBulkCacheDir() . '/tmp';
}

function getMetarBulkRefreshLockPath(): string
{
    return getMetarBulkCacheDir() . '/refresh.lock';
}

function getMetarBulkStationJsonPath(string $icaoUpper): string {
    return getMetarBulkStationsDir() . '/' . strtoupper($icaoUpper) . '.json';
}

/**
 * METAR bulk refresh metadata (`fetched_at`, row counts) for ops age metrics.
 */
function getMetarBulkMetaPath(): string
{
    return getMetarBulkCacheDir() . '/meta.json';
}

// =============================================================================
// NWS POINTS CACHE (api.weather.gov /points/{lat},{lon})
// =============================================================================

if (!defined('CACHE_NWS_POINTS_DIR')) {
    define('CACHE_NWS_POINTS_DIR', CACHE_BASE_DIR . '/nws-points');
}

/**
 * @return string Directory for cached NWS points responses
 */
function getNwsPointsCacheDir(): string
{
    if (isset($GLOBALS['nwsPointsCacheTestRoot'])
        && is_string($GLOBALS['nwsPointsCacheTestRoot'])
        && $GLOBALS['nwsPointsCacheTestRoot'] !== ''
    ) {
        return rtrim($GLOBALS['nwsPointsCacheTestRoot'], '/');
    }

    return CACHE_NWS_POINTS_DIR;
}

/**
 * @param string $cacheKey From nwsPointsCacheKey() (e.g. 45.7710,-122.8600)
 */
function getNwsPointsCacheFilePath(string $cacheKey): string
{
    return getNwsPointsCacheDir() . '/' . $cacheKey . '.json';
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
 * Get date/hour subdir for webcam frames (limits files per directory)
 *
 * Format: YYYY-MM-DD/HH (UTC). With 60s refresh, ~60 frames/hour × 8 files ≈ 480 files per hour dir.
 *
 * @param int $timestamp Unix timestamp
 * @return string Relative subdir path (e.g., '2026-02-24/14')
 */
function getWebcamFramesSubdir(int $timestamp): string {
    $date = gmdate('Y-m-d', $timestamp);
    $hour = gmdate('H', $timestamp);
    return $date . '/' . $hour;
}

/**
 * Get full path to webcam frames directory for a timestamp
 *
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index (0-based)
 * @param int $timestamp Unix timestamp
 * @return string Full path to frames subdir
 */
function getWebcamFramesDir(string $airportId, int $camIndex, int $timestamp): string {
    return getWebcamCameraDir($airportId, $camIndex) . '/' . getWebcamFramesSubdir($timestamp);
}

/**
 * Get all timestamped image file paths for a camera (scans date/hour subdirs)
 *
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index (0-based)
 * @param string $pattern Glob pattern for files (e.g., '*_*.{jpg,jpeg,webp}' or '*_original.{jpg,jpeg,webp}')
 * @return array List of full paths to matching files
 */
function getWebcamImageFiles(string $airportId, int $camIndex, string $pattern = '*_*.{jpg,jpeg,webp}'): array {
    $cacheDir = getWebcamCameraDir($airportId, $camIndex);
    if (!is_dir($cacheDir)) {
        return [];
    }
    $files = [];
    // glob doesn't recurse; scan date/hour subdirs explicitly
    $dateDirs = glob($cacheDir . '/????-??-??', GLOB_ONLYDIR);
    if ($dateDirs !== false) {
        foreach ($dateDirs as $dateDir) {
            $hourDirs = glob($dateDir . '/[0-2][0-9]', GLOB_ONLYDIR);
            if ($hourDirs !== false) {
                foreach ($hourDirs as $hourDir) {
                    $hourFiles = glob($hourDir . '/' . $pattern, GLOB_BRACE);
                    if ($hourFiles !== false) {
                        $files = array_merge($files, $hourFiles);
                    }
                }
            }
        }
    }
    return $files;
}

/**
 * Get path to camera history directory
 *
 * @deprecated History is now stored directly in the camera cache directory.
 *             Use getWebcamCameraDir() instead. Kept for backward compatibility.
 *
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index (0-based)
 * @return string Full path to camera history directory
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
    return getWebcamFramesDir($airportId, $camIndex, $timestamp) . '/' . $timestamp . '_' . $variant . '.' . $format;
}

/**
 * Get path to timestamped original webcam image
 *
 * Stored in date/hour subdir to limit files per directory (~500/hour).
 *
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index (0-based)
 * @param int $timestamp Unix timestamp
 * @param string $format Image format (jpg, webp)
 * @return string Full path to timestamped original image
 */
function getWebcamOriginalTimestampedPath(string $airportId, int $camIndex, int $timestamp, string $format): string {
    return getWebcamFramesDir($airportId, $camIndex, $timestamp) . '/' . $timestamp . '_original.' . $format;
}

/**
 * Get path to timestamped variant webcam image (by height)
 *
 * Stored in date/hour subdir to limit files per directory.
 *
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index (0-based)
 * @param int $timestamp Unix timestamp
 * @param int $height Variant height in pixels
 * @param string $format Image format (jpg, webp)
 * @return string Full path to timestamped variant image
 */
function getWebcamVariantPath(string $airportId, int $camIndex, int $timestamp, int $height, string $format): string {
    return getWebcamFramesDir($airportId, $camIndex, $timestamp) . '/' . $timestamp . '_' . $height . '.' . $format;
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
// PUSH WEBCAM UPLOAD PATHS (FTP and SFTP - separate directories)
// =============================================================================

// FTP uploads - simple directory structure (no chroot needed for vsftpd)
if (!defined('CACHE_UPLOADS_DIR')) {
    define('CACHE_UPLOADS_DIR', CACHE_BASE_DIR . '/ftp');
}

// SFTP uploads - dedicated chroot structure outside of cache/
// Must be in a path where ALL parent directories are root-owned (SSH chroot requirement)
// /var/sftp/ works because /var/ is root-owned
if (!defined('CACHE_SFTP_DIR')) {
    define('CACHE_SFTP_DIR', '/var/sftp');
}

/**
 * Get FTP upload directory for a push webcam
 * 
 * This is where FTP/FTPS cameras upload files.
 * vsftpd local_root points here, users upload to /
 * 
 * Directory owned by ftp:www-data with setgid (2775).
 * 
 * @param string $airportId Airport identifier
 * @param string $username FTP username
 * @return string Full path to FTP upload directory
 */
function getWebcamFtpUploadDir(string $airportId, string $username): string {
    return CACHE_UPLOADS_DIR . '/' . strtolower($airportId) . '/' . $username;
}

/**
 * FTP local_root for upload health probe accounts (isolated from camera inboxes).
 *
 * @param string $username Probe FTPS username from config.upload_health_probe.ftps
 * @return string Full path to probe-only FTP directory
 */
function getUploadHealthProbeFtpDir(string $username): string {
    if (!defined('UPLOAD_HEALTH_PROBE_FTP_NAMESPACE')) {
        require_once __DIR__ . '/constants.php';
    }

    return CACHE_UPLOADS_DIR . '/' . UPLOAD_HEALTH_PROBE_FTP_NAMESPACE . '/' . $username;
}

/**
 * Whether a top-level FTP upload cache directory basename is reserved for upload health probes.
 *
 * cleanup-cache.php must preserve this tree even when empty (vsftpd local_root).
 */
function isUploadHealthProbeFtpCacheNamespace(string $basename): bool {
    if (!defined('UPLOAD_HEALTH_PROBE_FTP_NAMESPACE')) {
        require_once __DIR__ . '/constants.php';
    }

    return strtolower($basename) === strtolower(UPLOAD_HEALTH_PROBE_FTP_NAMESPACE);
}

/**
 * Get SFTP chroot directory for a push webcam
 * 
 * This is the SFTP chroot directory (root-owned, not writable).
 * SFTP users are jailed here and upload to the files/ subdirectory.
 * 
 * The entire path from / to this directory must be root-owned,
 * which is why SFTP uses a separate /cache/sftp/ hierarchy.
 * 
 * Directory structure:
 *   /cache/sftp/{username}/       ← root:root 755 (SFTP chroot)
 *   /cache/sftp/{username}/files/ ← ftp:www-data 2775 (uploads)
 * 
 * @param string $username SFTP username
 * @return string Full path to SFTP chroot directory
 */
function getWebcamSftpChrootDir(string $username): string {
    return CACHE_SFTP_DIR . '/' . $username;
}

/**
 * Get SFTP upload directory for a push webcam
 * 
 * This is the writable subdirectory where SFTP cameras upload files.
 * Users chroot to parent and must upload to /files/
 * 
 * Directory owned by ftp:www-data with setgid (2775).
 * 
 * @param string $username SFTP username
 * @return string Full path to SFTP upload directory
 */
function getWebcamSftpUploadDir(string $username): string {
    return getWebcamSftpChrootDir($username) . '/files';
}

/**
 * Get all upload directories for a push webcam
 * 
 * Returns both FTP and SFTP upload paths for the processor to check.
 * 
 * @param string $airportId Airport identifier
 * @param string $username Push webcam username
 * @return array Array of paths to check for uploaded files
 */
function getWebcamAllUploadDirs(string $airportId, string $username): array {
    return [
        'ftp' => getWebcamFtpUploadDir($airportId, $username),
        'sftp' => getWebcamSftpUploadDir($username),
    ];
}

/**
 * Get upload directory for a push webcam (legacy compatibility)
 * 
 * @deprecated Use getWebcamFtpUploadDir() or getWebcamSftpUploadDir() instead
 * 
 * Returns FTP directory for backward compatibility.
 * 
 * @param string $airportId Airport identifier
 * @param string $username FTP/SFTP username
 * @return string Full path to FTP upload directory
 */
function getWebcamUploadDir(string $airportId, string $username): string {
    return getWebcamFtpUploadDir($airportId, $username);
}

/**
 * Get chroot directory for a push webcam (legacy compatibility)
 * 
 * @deprecated Use getWebcamSftpChrootDir() instead
 * 
 * @param string $airportId Airport identifier (ignored for SFTP)
 * @param string $username SFTP username
 * @return string Full path to SFTP chroot directory
 */
function getWebcamChrootDir(string $airportId, string $username): string {
    return getWebcamSftpChrootDir($username);
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
 * Aggregated TFR GeoJSON for the airports directory map (internal use).
 *
 * Uses {@see notamCacheDirectory()} when available so test overrides align
 * with per-airport NOTAM cache paths.
 *
 * @return string Full path to JSON cache file
 */
function getNotamTfrMapLayerCachePath(): string {
    require_once __DIR__ . '/notam/cache.php';

    return notamCacheDirectory() . '/tfr-map-layer.json';
}

/**
 * Exclusive flock target for single-flight NOTAM map layer rebuilds.
 *
 * @return string Full path to lock file (created on demand)
 */
function getNotamTfrMapLayerRebuildLockPath(): string {
    require_once __DIR__ . '/notam/cache.php';

    return notamCacheDirectory() . '/tfr-map-layer.rebuild.lock';
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
// STATION POWER CACHE PATHS
// =============================================================================

if (!defined('CACHE_STATION_POWER_DIR')) {
    define('CACHE_STATION_POWER_DIR', CACHE_BASE_DIR . '/station-power');
}

/**
 * Path to canonical station power JSON for an airport (provider-agnostic schema).
 *
 * @param string $airportId Airport identifier (e.g. kspb)
 * @return string Absolute path to cache file
 */
function getStationPowerCachePath(string $airportId): string {
    return CACHE_STATION_POWER_DIR . '/' . strtolower($airportId) . '.json';
}

// =============================================================================
// PARTNER LOGO CACHE PATHS
// =============================================================================

if (!defined('CACHE_PARTNERS_DIR')) {
    define('CACHE_PARTNERS_DIR', CACHE_BASE_DIR . '/partners');
}

/**
 * Build filesystem path for a partner logo cache file from URL hash and extension.
 *
 * For resolve-or-download behavior from a logo URL, use {@see getPartnerLogoCachePath()}
 * in `lib/partner-logo-cache.php` (single string argument).
 *
 * @param string $hash MD5 or other stable hash of the remote logo URL
 * @param string $extension File extension without dot (e.g. jpg, png)
 * @return string Full path under CACHE_PARTNERS_DIR
 */
function getPartnerLogoCachedFilePath(string $hash, string $extension): string {
    return CACHE_PARTNERS_DIR . '/' . $hash . '.' . $extension;
}

if (!defined('CACHE_PARTNERS_LUM_DIR')) {
    define('CACHE_PARTNERS_LUM_DIR', CACHE_PARTNERS_DIR . '/lum');
}

/**
 * Writable cache path for partner logo luminance metadata.
 *
 * Keyed by SHA-256 of the resolved absolute image path so read-only
 * `/partner-logos/` mounts do not need sidecar writes beside the image.
 *
 * @param string $imagePath Absolute filesystem path to the logo image
 * @return string Full path to the JSON metadata file under CACHE_PARTNERS_LUM_DIR
 */
function getPartnerLogoLuminanceCachePath(string $imagePath): string {
    $hash = hash('sha256', $imagePath);
    $prefix = substr($hash, 0, 2);
    $dir = CACHE_PARTNERS_LUM_DIR . '/' . $prefix;
    ensureCacheDir($dir);

    return $dir . '/' . $hash . '.json';
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
 * Uses prefix subdir (first 2 chars of identifier) to limit files per directory.
 *
 * @param string $identifier Rate limit identifier (e.g., IP address hash)
 * @return string Full path to rate limit file
 */
function getRateLimitPath(string $identifier): string {
    $prefix = strlen($identifier) >= 2 ? substr($identifier, 0, 2) : $identifier;
    return CACHE_RATE_LIMITS_DIR . '/' . $prefix . '/' . $identifier . '.json';
}

// =============================================================================
// UPSTREAM RATE LIMIT PATHS (per-credential token buckets)
// =============================================================================

if (!defined('CACHE_UPSTREAM_LIMITS_DIR')) {
    define('CACHE_UPSTREAM_LIMITS_DIR', CACHE_BASE_DIR . '/upstream-limits');
}

/**
 * Path to flock-backed upstream token bucket state for a credential fingerprint.
 *
 * @param string $fingerprint SHA-256 hex from upstreamRateFingerprint()
 */
function getUpstreamRateLimitStatePath(string $fingerprint): string
{
    $prefix = strlen($fingerprint) >= 2 ? substr($fingerprint, 0, 2) : $fingerprint;

    return CACHE_UPSTREAM_LIMITS_DIR . '/' . $prefix . '/' . $fingerprint . '.json';
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

if (!defined('CACHE_METRICS_SPILL_DIR')) {
    define('CACHE_METRICS_SPILL_DIR', CACHE_METRICS_DIR . '/spill');
}

/**
 * Root directory for per-worker metric spill files before aggregator merge (hour subdirs).
 *
 * @return string Absolute path to spill root
 */
function getMetricsSpillRootDir(): string {
    return CACHE_METRICS_SPILL_DIR;
}

/**
 * Directory for one UTC hour bucket (metrics_get_hour_id format).
 *
 * @param string $hourId Hour identifier (e.g. 2026-05-09-14)
 * @return string Absolute path
 */
function getMetricsSpillHourDir(string $hourId): string {
    return CACHE_METRICS_SPILL_DIR . '/' . $hourId;
}

/**
 * Per-worker JSONL journal for request-shutdown spill deltas (append one line per shutdown).
 *
 * One file per FPM worker per UTC hour; the aggregator claims and merges the whole journal.
 *
 * @param string $hourId Hour identifier (metrics_get_hour_id format)
 * @param int    $pid    Process ID (FPM worker)
 * @return string Absolute path to {pid}.jsonl under the hour spill directory
 */
function getMetricsSpillWorkerJournalPath(string $hourId, int $pid): string {
    return getMetricsSpillHourDir($hourId) . '/' . $pid . '.jsonl';
}

/**
 * Exclusive singleton lock for metrics spill aggregator CLI (non-blocking flock).
 *
 * @return string Absolute path
 */
function getMetricsAggregatorLockPath(): string {
    return CACHE_METRICS_DIR . '/' . METRICS_AGGREGATOR_LOCK_BASENAME;
}

/**
 * Last-run telemetry JSON written by aggregator for ops visibility.
 *
 * @return string Absolute path
 */
function getMetricsAggregatorLastRunPath(): string {
    return CACHE_METRICS_DIR . '/' . METRICS_AGGREGATOR_LAST_RUN_BASENAME;
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

// Per-airport tracking subdirectories (preferred over legacy single-file layout)
if (!defined('CACHE_PEAK_GUSTS_DIR')) {
    define('CACHE_PEAK_GUSTS_DIR', CACHE_BASE_DIR . '/peak_gusts');
}

if (!defined('CACHE_TEMP_EXTREMES_DIR')) {
    define('CACHE_TEMP_EXTREMES_DIR', CACHE_BASE_DIR . '/temp_extremes');
}

/**
 * Get path to per-airport peak gust tracking file
 *
 * @param string $airportId Airport identifier (e.g., 'kczk')
 * @return string Full path to peak gust tracking file
 */
function getPeakGustTrackingPath(string $airportId): string {
    return CACHE_PEAK_GUSTS_DIR . '/' . strtolower($airportId) . '.json';
}

/**
 * Get path to per-airport temp extremes tracking file
 *
 * @param string $airportId Airport identifier (e.g., 'kczk')
 * @return string Full path to temp extremes tracking file
 */
function getTempExtremesTrackingPath(string $airportId): string {
    return CACHE_TEMP_EXTREMES_DIR . '/' . strtolower($airportId) . '.json';
}

if (!defined('CACHE_MEMORY_HISTORY_FILE')) {
    define('CACHE_MEMORY_HISTORY_FILE', CACHE_BASE_DIR . '/memory_history.json');
}

if (!defined('CACHE_AIRPORT_COUNTRY_RESOLUTION_FILE')) {
    define('CACHE_AIRPORT_COUNTRY_RESOLUTION_FILE', CACHE_BASE_DIR . '/airport_country_resolution.json');
}

if (!defined('CACHE_PUBLIC_API_HEALTH_FILE')) {
    define('CACHE_PUBLIC_API_HEALTH_FILE', CACHE_BASE_DIR . '/public_api_health.json');
}
if (!defined('CACHE_SYSTEM_HEALTH_FILE')) {
    define('CACHE_SYSTEM_HEALTH_FILE', CACHE_BASE_DIR . '/status_system_health.json');
}
if (!defined('CACHE_AIRPORT_HEALTH_FILE')) {
    define('CACHE_AIRPORT_HEALTH_FILE', CACHE_BASE_DIR . '/status_airport_health.json');
}
if (!defined('CACHE_STATUS_METRICS_BUNDLE_FILE')) {
    define('CACHE_STATUS_METRICS_BUNDLE_FILE', CACHE_BASE_DIR . '/status_metrics_bundle.json');
}
if (!defined('CACHE_NODE_PERFORMANCE_FILE')) {
    define('CACHE_NODE_PERFORMANCE_FILE', CACHE_BASE_DIR . '/status_node_performance.json');
}
if (!defined('CACHE_IMAGE_PROCESSING_METRICS_FILE')) {
    define('CACHE_IMAGE_PROCESSING_METRICS_FILE', CACHE_BASE_DIR . '/status_image_processing.json');
}
if (!defined('CACHE_PAGE_RENDER_METRICS_FILE')) {
    define('CACHE_PAGE_RENDER_METRICS_FILE', CACHE_BASE_DIR . '/status_page_render.json');
}

if (!defined('CACHE_OPERATIONS_SNAPSHOT_FILE')) {
    define('CACHE_OPERATIONS_SNAPSHOT_FILE', CACHE_BASE_DIR . '/operations_snapshot.json');
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
// RUNWAY GEOMETRY CACHE (FAA + OurAirports)
// =============================================================================
// Bulk runway data from FAA (US) and OurAirports (worldwide)
// Processed segments stored per-airport; FAA preferred when both exist

if (!defined('CACHE_RUNWAYS_DIR')) {
    define('CACHE_RUNWAYS_DIR', CACHE_BASE_DIR . '/runways');
}

if (!defined('CACHE_RUNWAYS_DATA_FILE')) {
    define('CACHE_RUNWAYS_DATA_FILE', CACHE_RUNWAYS_DIR . '/runways_data.json');
}

if (!defined('CACHE_RUNWAYS_FETCH_LOCK')) {
    define('CACHE_RUNWAYS_FETCH_LOCK', CACHE_RUNWAYS_DIR . '/.fetch.lock');
}

/**
 * Get path to runway fetch lock file
 *
 * @return string Full path to lock file
 */
function getRunwaysFetchLockPath(): string {
    return CACHE_RUNWAYS_FETCH_LOCK;
}

// =============================================================================
// NASR APT CACHE (FAA 28-day subscription - runway performance fields)
// =============================================================================

if (!defined('CACHE_NASR_DIR')) {
    define('CACHE_NASR_DIR', CACHE_BASE_DIR . '/nasr');
}

if (!defined('CACHE_NASR_APT_DATA_FILE')) {
    define('CACHE_NASR_APT_DATA_FILE', CACHE_NASR_DIR . '/nasr_apt.json');
}

if (!defined('CACHE_NASR_APT_META_FILE')) {
    define('CACHE_NASR_APT_META_FILE', CACHE_NASR_DIR . '/nasr_meta.json');
}

if (!defined('CACHE_NASR_APT_CONFIGURED_FILE')) {
    define('CACHE_NASR_APT_CONFIGURED_FILE', CACHE_NASR_DIR . '/nasr_apt_configured.json');
}

if (!defined('CACHE_NASR_APT_FETCH_LOCK')) {
    define('CACHE_NASR_APT_FETCH_LOCK', CACHE_NASR_DIR . '/.fetch.lock');
}

/**
 * Get path to NASR APT fetch lock file
 *
 * @return string Full path to lock file
 */
function getNasrAptFetchLockPath(): string
{
    return CACHE_NASR_APT_FETCH_LOCK;
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
 * Uses hierarchical {layer}/{z}/{x}/{y}.png to limit files per directory.
 *
 * @param string $layer Layer type (e.g., 'clouds_new')
 * @param int $z Zoom level
 * @param int $x Tile X coordinate
 * @param int $y Tile Y coordinate
 * @return string Full path to cached tile
 */
function getMapTileCachePath(string $layer, int $z, int $x, int $y): string {
    return getMapTileLayerDir($layer) . '/' . $z . '/' . $x . '/' . $y . '.png';
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
 * Ensure all required cache directories exist.
 * Call this during application bootstrap or deployment.
 *
 * Paths under CACHE_BASE_DIR should stay aligned with `docker/docker-entrypoint.sh` (ensure_cache_subdirs),
 * `/usr/local/libexec/aviationwx/set-cache-permissions.sh` (cache + FTP parent + nightly SFTP chroot repair via `repair-sftp-chroot-permissions.sh`), and
 * `.github/workflows/deploy-docker.yml` (host cache chown excludes `sftp/{user}/` chroots). The entrypoint skips `/var/sftp` in the cache subtree loop
 * (SFTP uses a separate chroot block under `/var/sftp`). This function still lists CACHE_SFTP_DIR so
 * bootstrap can ensure the path when permissions allow; production entrypoint creates it with correct
 * ownership for sshd chroot.
 *
 * @return array List of directories and their creation status
 */
function ensureAllCacheDirs(): array {
    $dirs = [
        CACHE_BASE_DIR,
        CACHE_PEAK_GUSTS_DIR,
        CACHE_TEMP_EXTREMES_DIR,
        CACHE_RUNWAYS_DIR,
        CACHE_NASR_DIR,
        CACHE_WEATHER_DIR,
        CACHE_WEATHER_HISTORY_DIR,
        CACHE_WEBCAMS_DIR,
        CACHE_UPLOADS_DIR,
        CACHE_SFTP_DIR,
        CACHE_NOTAM_DIR,
        CACHE_STATION_POWER_DIR,
        CACHE_PARTNERS_DIR,
        CACHE_PARTNERS_LUM_DIR,
        CACHE_RATE_LIMITS_DIR,
        CACHE_UPSTREAM_LIMITS_DIR,
        CACHE_METRICS_DIR,
        CACHE_METRICS_HOURLY_DIR,
        CACHE_METRICS_DAILY_DIR,
        CACHE_METRICS_WEEKLY_DIR,
        CACHE_METRICS_SPILL_DIR,
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
        'ftp_uploads_dir' => CACHE_UPLOADS_DIR . '/' . $airportId,
        'sftp_dir' => CACHE_SFTP_DIR,  // SFTP uses flat structure by username
        'notam' => getNotamCachePath($airportId),
        'station_power' => getStationPowerCachePath($airportId),
        'outage' => getOutageStatePath($airportId),
        'peak_gusts' => getPeakGustTrackingPath($airportId),
        'temp_extremes' => getTempExtremesTrackingPath($airportId),
    ];
}

