<?php
// Prevent double inclusion
if (defined('AVIATIONWX_CONFIG_LOADED')) {
    return;
}
define('AVIATIONWX_CONFIG_LOADED', true);

require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/airport-identifiers.php';

/**
 * Shared Configuration Utilities
 * Provides centralized config loading, caching, and validation
 */

/**
 * Detect duplicate keys in raw JSON content
 * 
 * PHP's json_decode silently uses the last value for duplicate keys, which can
 * lead to subtle configuration bugs (e.g., two "weather_source" keys in an airport
 * where the second overwrites the first).
 * 
 * This function parses the raw JSON to detect such duplicates.
 * 
 * @param string $jsonContent Raw JSON string to check
 * @return array Array of duplicate key paths found (e.g., ["airports.kspb.weather_source"])
 */
function detectDuplicateJsonKeys(string $jsonContent): array {
    $duplicates = [];
    
    // Use a simple regex-based approach to find potential duplicate keys
    // This won't catch all edge cases but handles the common case of duplicate
    // object keys at the same nesting level
    
    // We'll track keys at each nesting level using a stack approach
    // For simplicity, we look for patterns like "key": appearing multiple times
    // within the same object context
    
    $lines = explode("\n", $jsonContent);
    $keyStack = []; // Track keys at current nesting level
    $pathStack = []; // Track path for error reporting
    $braceDepth = 0;
    $bracketDepth = 0;
    
    foreach ($lines as $lineNum => $line) {
        $trimmed = trim($line);
        
        // Track brace depth
        $openBraces = substr_count($trimmed, '{');
        $closeBraces = substr_count($trimmed, '}');
        $openBrackets = substr_count($trimmed, '[');
        $closeBrackets = substr_count($trimmed, ']');
        
        // When entering a new object, push new key tracker
        for ($i = 0; $i < $openBraces; $i++) {
            $keyStack[] = [];
            $braceDepth++;
        }
        
        // Check for key definitions: "key": 
        if (preg_match('/^\s*"([^"]+)"\s*:/', $trimmed, $matches)) {
            $key = $matches[1];
            
            // Check if this key was already seen at current level
            if (!empty($keyStack)) {
                $currentLevelKeys = &$keyStack[count($keyStack) - 1];
                if (isset($currentLevelKeys[$key])) {
                    // Duplicate found!
                    $path = implode('.', array_merge($pathStack, [$key]));
                    $duplicates[] = [
                        'key' => $key,
                        'path' => $path,
                        'first_line' => $currentLevelKeys[$key],
                        'duplicate_line' => $lineNum + 1
                    ];
                } else {
                    $currentLevelKeys[$key] = $lineNum + 1;
                }
                
                // Update path stack if this opens a new object
                if (strpos($trimmed, '{') !== false) {
                    $pathStack[] = $key;
                }
            }
        }
        
        // When leaving objects, pop key tracker
        for ($i = 0; $i < $closeBraces; $i++) {
            if (!empty($keyStack)) {
                array_pop($keyStack);
            }
            if (!empty($pathStack)) {
                array_pop($pathStack);
            }
            $braceDepth--;
        }
        
        $bracketDepth += $openBrackets - $closeBrackets;
    }
    
    return $duplicates;
}

/**
 * Validate airport ID format
 * 
 * Supports both standard ICAO/FAA codes (3-4 characters) and custom identifiers
 * (3-50 characters with hyphens) for airports without standard codes.
 * 
 * Rules:
 * - Length: 3-50 characters (matches subdomain regex)
 * - Characters: Lowercase alphanumeric + hyphens (filesystem-safe)
 * - Format: Must start and end with alphanumeric (no leading/trailing hyphens)
 * - No consecutive hyphens (prevents "--")
 * - No whitespace or special characters
 * 
 * @param string|null $id Airport ID to validate
 * @return bool True if valid, false otherwise
 */
function validateAirportId(?string $id): bool {
    if ($id === null || empty($id)) {
        return false;
    }
    // Check for whitespace BEFORE trimming (reject IDs with whitespace)
    // This prevents "k spb" from becoming "kspb" after trim
    if (preg_match('/\s/', $id)) {
        return false;
    }
    $normalized = strtolower(trim($id));
    
    // Length check: 3-50 characters (matches subdomain regex)
    $length = strlen($normalized);
    if ($length < 3 || $length > 50) {
        return false;
    }
    
    // Must start and end with alphanumeric (no leading/trailing hyphens)
    // Allow lowercase alphanumeric + hyphens (filesystem-safe)
    // No consecutive hyphens (prevents "--")
    return preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $normalized) === 1
        && strpos($normalized, '--') === false;
}

/**
 * Check if we're in a production environment
 * 
 * Determines production status by checking:
 * 1. APP_ENV environment variable
 * 2. ENVIRONMENT environment variable
 * 3. HTTP_HOST domain (checks for production TLDs, excludes test/staging)
 * 
 * @return bool True if production, false otherwise
 */
function isProduction(): bool {
    // Check APP_ENV environment variable
    $appEnv = getenv('APP_ENV');
    if ($appEnv === 'production') {
        return true;
    }
    if ($appEnv === 'testing' || $appEnv === 'development' || $appEnv === 'local') {
        return false;
    }
    
    // Check ENVIRONMENT variable
    $env = getenv('ENVIRONMENT');
    if ($env === 'production' || $env === 'prod') {
        return true;
    }
    
    // Check domain - if not localhost/local domain, assume production
    $host = isset($_SERVER['HTTP_HOST']) ? strtolower($_SERVER['HTTP_HOST']) : '';
    if (!empty($host)) {
        // Production domains
        if (strpos($host, 'aviationwx.org') !== false || 
            strpos($host, '.com') !== false || 
            strpos($host, '.net') !== false ||
            strpos($host, '.io') !== false) {
            // But exclude obvious test/staging domains
            if (strpos($host, 'test') === false && 
                strpos($host, 'staging') === false && 
                strpos($host, 'dev') === false &&
                strpos($host, 'localhost') === false &&
                strpos($host, '127.0.0.1') === false) {
                return true;
            }
        }
    }
    
    return false;
}

/**
 * Check if application is running in test mode
 * 
 * Test mode is active when:
 * - CONFIG_PATH environment variable points to airports.json.test
 * - APP_ENV is set to 'testing' or 'test'
 * - Running in CI (GITHUB_ACTIONS or CI env var) and using test config
 * 
 * @return bool True if in test mode, false otherwise
 */
function isTestMode(): bool {
    // Check CONFIG_PATH environment variable
    $envConfigPath = getenv('CONFIG_PATH');
    if ($envConfigPath && strpos($envConfigPath, 'airports.json.test') !== false) {
        return true;
    }
    
    // Check APP_ENV
    $appEnv = getenv('APP_ENV');
    if ($appEnv === 'testing' || $appEnv === 'test') {
        return true;
    }
    
    // Check if running in CI
    if (getenv('CI') === 'true' || getenv('GITHUB_ACTIONS') === 'true') {
        // In CI, check if we're using test config
        $envConfigPath = getenv('CONFIG_PATH');
        if ($envConfigPath && strpos($envConfigPath, 'airports.json.test') !== false) {
            return true;
        }
        // Also check if default config path contains test markers
        // In CI, we copy test fixture to config/airports.json, so check content for test markers
        $defaultConfigPath = __DIR__ . '/../config/airports.json';
        if (file_exists($defaultConfigPath)) {
            // Use @ to suppress errors for non-critical file operations
            // We handle failures explicitly with null checks below
            $configContent = @file_get_contents($defaultConfigPath);
            if ($configContent && (
                strpos($configContent, '"test_api_key_') !== false ||
                strpos($configContent, 'example.com') !== false
            )) {
                return true;
            }
        }
    }
    
    return false;
}

/**
 * Check if external services should be mocked
 * 
 * Mock mode is designed for developers without access to production secrets.
 * When active:
 * - Weather API calls return simulated data (via lib/test-mocks.php)
 * - Webcam fetches return placeholder images (via lib/mock-webcam.php)
 * - All features remain functional for UI development
 * 
 * Mock mode activates when:
 * - LOCAL_DEV_MOCK=1 environment variable is set, OR
 * - APP_ENV=testing (always mocks in test mode), OR
 * - Config contains test API keys (prefixed with test_ or demo_), OR
 * - Webcam URLs point to example.com
 * 
 * ## Usage
 * Developers without secrets can start with mock mode:
 * ```bash
 * cp config/airports.json.example config/airports.json
 * make dev  # Auto-detects test keys, enables mocking
 * ```
 * 
 * ## Related Files
 * - lib/test-mocks.php: HTTP response mocking for weather APIs
 * - lib/mock-webcam.php: Placeholder webcam image generation
 * - tests/mock-weather-responses.php: Weather API mock responses
 * 
 * @return bool True if external services should be mocked
 */
function shouldMockExternalServices(): bool {
    // Always mock in test mode
    if (isTestMode()) {
        return true;
    }
    
    // Explicit mock mode via environment variable
    $mockEnv = getenv('LOCAL_DEV_MOCK');
    if ($mockEnv === '1' || $mockEnv === 'true') {
        return true;
    }
    if ($mockEnv === '0' || $mockEnv === 'false') {
        return false;
    }
    
    // Auto-detect: check if config contains test markers
    // Use loadConfigRaw to avoid circular dependency with loadConfig()
    $configFile = getConfigFilePath();
    if ($configFile && file_exists($configFile)) {
        $content = @file_get_contents($configFile);
        if ($content) {
            // Check for test API keys
            if (preg_match('/"api_key"\s*:\s*"(test_|demo_)/', $content)) {
                return true;
            }
            // Check for example.com webcam URLs
            if (strpos($content, 'example.com') !== false) {
                return true;
            }
        }
    }
    
    return false;
}

/**
 * Get the resolved config file path without loading config
 * 
 * Helper function to determine which config file would be used.
 * Used by shouldMockExternalServices() to avoid circular dependency.
 * 
 * @return string|null Path to config file, or null if not found
 */
function getConfigFilePath(): ?string {
    // Check CONFIG_PATH environment variable first
    $envConfigPath = getenv('CONFIG_PATH');
    if ($envConfigPath && file_exists($envConfigPath) && is_file($envConfigPath)) {
        return $envConfigPath;
    }
    
    // Check secrets path (Docker mount)
    $secretsPath = '/var/www/html/secrets/airports.json';
    if (file_exists($secretsPath)) {
        return $secretsPath;
    }
    
    // Check default config path
    $defaultPath = __DIR__ . '/../config/airports.json';
    if (file_exists($defaultPath) && is_file($defaultPath)) {
        return $defaultPath;
    }
    
    // Check adjacent secrets repo (local dev)
    $adjacentSecrets = __DIR__ . '/../../aviationwx.org-secrets/airports.json';
    if (file_exists($adjacentSecrets)) {
        return $adjacentSecrets;
    }
    
    return null;
}

/**
 * Get global configuration value with fallback to default
 * @param string $key Configuration key
 * @param mixed $default Default value if not set
 * @return mixed Configuration value or default
 */
function getGlobalConfig(string $key, mixed $default = null): mixed {
    $config = loadConfig();
    if ($config === null) {
        return $default;
    }
    
    if (isset($config['config']) && is_array($config['config']) && isset($config['config'][$key])) {
        return $config['config'][$key];
    }
    
    return $default;
}

/**
 * Get default timezone from global config
 * @return string Timezone identifier (default: UTC)
 */
function getDefaultTimezone(): string {
    return getGlobalConfig('default_timezone', 'UTC');
}

/**
 * Get base domain from global config
 * @return string Base domain (default: aviationwx.org)
 */
function getBaseDomain(): string {
    return getGlobalConfig('base_domain', 'aviationwx.org');
}

// =============================================================================
// STALENESS THRESHOLD HELPERS (3-Tier Model)
// =============================================================================
// Cascade: airport → global config → built-in default
// METAR and NOTAM: global config → built-in default (no airport override)

/**
 * Get stale warning threshold in seconds
 * Cascade: airport → global config → built-in default
 * 
 * @param array|null $airport Airport config (for airport-level override)
 * @return int Threshold in seconds (enforces minimum)
 */
function getStaleWarningSeconds(?array $airport = null): int {
    // Airport-level override
    if ($airport !== null && isset($airport['stale_warning_seconds'])) {
        return max(MIN_STALE_WARNING_SECONDS, intval($airport['stale_warning_seconds']));
    }
    
    // Global config
    $global = getGlobalConfig('stale_warning_seconds');
    if ($global !== null) {
        return max(MIN_STALE_WARNING_SECONDS, intval($global));
    }
    
    // Built-in default
    return DEFAULT_STALE_WARNING_SECONDS;
}

/**
 * Get stale error threshold in seconds
 * Cascade: airport → global config → built-in default
 * 
 * @param array|null $airport Airport config (for airport-level override)
 * @return int Threshold in seconds (enforces minimum)
 */
function getStaleErrorSeconds(?array $airport = null): int {
    // Airport-level override
    if ($airport !== null && isset($airport['stale_error_seconds'])) {
        return max(MIN_STALE_ERROR_SECONDS, intval($airport['stale_error_seconds']));
    }
    
    // Global config
    $global = getGlobalConfig('stale_error_seconds');
    if ($global !== null) {
        return max(MIN_STALE_ERROR_SECONDS, intval($global));
    }
    
    return DEFAULT_STALE_ERROR_SECONDS;
}

/**
 * Get stale failclosed threshold in seconds
 * This is the "stop showing data" threshold
 * Cascade: airport → global config → built-in default
 * 
 * @param array|null $airport Airport config (for airport-level override)
 * @return int Threshold in seconds (enforces minimum)
 */
function getStaleFailclosedSeconds(?array $airport = null): int {
    // Airport-level override
    if ($airport !== null && isset($airport['stale_failclosed_seconds'])) {
        return max(MIN_STALE_FAILCLOSED_SECONDS, intval($airport['stale_failclosed_seconds']));
    }
    
    // Global config
    $global = getGlobalConfig('stale_failclosed_seconds');
    if ($global !== null) {
        return max(MIN_STALE_FAILCLOSED_SECONDS, intval($global));
    }
    
    return DEFAULT_STALE_FAILCLOSED_SECONDS;
}

/**
 * Get all staleness thresholds as an array
 * Useful for passing to JavaScript or APIs
 * 
 * @param array|null $airport Airport config (for airport-level override)
 * @return array ['warning_seconds' => int, 'error_seconds' => int, 'failclosed_seconds' => int]
 */
function getStalenessThresholds(?array $airport = null): array {
    return [
        'warning_seconds' => getStaleWarningSeconds($airport),
        'error_seconds' => getStaleErrorSeconds($airport),
        'failclosed_seconds' => getStaleFailclosedSeconds($airport),
    ];
}

/**
 * Get METAR stale warning threshold in seconds (global only)
 * @return int Threshold in seconds
 */
function getMetarStaleWarningSeconds(): int {
    $global = getGlobalConfig('metar_stale_warning_seconds');
    if ($global !== null) {
        return max(MIN_STALE_WARNING_SECONDS, intval($global));
    }
    return DEFAULT_METAR_STALE_WARNING_SECONDS;
}

/**
 * Get METAR stale error threshold in seconds (global only)
 * @return int Threshold in seconds
 */
function getMetarStaleErrorSeconds(): int {
    $global = getGlobalConfig('metar_stale_error_seconds');
    if ($global !== null) {
        return max(MIN_STALE_ERROR_SECONDS, intval($global));
    }
    return DEFAULT_METAR_STALE_ERROR_SECONDS;
}

/**
 * Get METAR stale failclosed threshold in seconds (global only)
 * @return int Threshold in seconds
 */
function getMetarStaleFailclosedSeconds(): int {
    $global = getGlobalConfig('metar_stale_failclosed_seconds');
    if ($global !== null) {
        return max(MIN_STALE_FAILCLOSED_SECONDS, intval($global));
    }
    return DEFAULT_METAR_STALE_FAILCLOSED_SECONDS;
}

/**
 * Get all METAR staleness thresholds as an array (global only)
 * @return array ['warning_seconds' => int, 'error_seconds' => int, 'failclosed_seconds' => int]
 */
function getMetarStalenessThresholds(): array {
    return [
        'warning_seconds' => getMetarStaleWarningSeconds(),
        'error_seconds' => getMetarStaleErrorSeconds(),
        'failclosed_seconds' => getMetarStaleFailclosedSeconds(),
    ];
}

/**
 * Get NOTAM stale warning threshold in seconds (global only)
 * @return int Threshold in seconds
 */
function getNotamStaleWarningSeconds(): int {
    $global = getGlobalConfig('notam_stale_warning_seconds');
    if ($global !== null) {
        return max(MIN_STALE_WARNING_SECONDS, intval($global));
    }
    return DEFAULT_NOTAM_STALE_WARNING_SECONDS;
}

/**
 * Get NOTAM stale error threshold in seconds (global only)
 * @return int Threshold in seconds
 */
function getNotamStaleErrorSeconds(): int {
    $global = getGlobalConfig('notam_stale_error_seconds');
    if ($global !== null) {
        return max(MIN_STALE_ERROR_SECONDS, intval($global));
    }
    return DEFAULT_NOTAM_STALE_ERROR_SECONDS;
}

/**
 * Get NOTAM stale failclosed threshold in seconds (global only)
 * @return int Threshold in seconds
 */
function getNotamStaleFailclosedSeconds(): int {
    $global = getGlobalConfig('notam_stale_failclosed_seconds');
    if ($global !== null) {
        return max(MIN_STALE_FAILCLOSED_SECONDS, intval($global));
    }
    return DEFAULT_NOTAM_STALE_FAILCLOSED_SECONDS;
}

/**
 * Get all NOTAM staleness thresholds as an array (global only)
 * @return array ['warning_seconds' => int, 'error_seconds' => int, 'failclosed_seconds' => int]
 */
function getNotamStalenessThresholds(): array {
    return [
        'warning_seconds' => getNotamStaleWarningSeconds(),
        'error_seconds' => getNotamStaleErrorSeconds(),
        'failclosed_seconds' => getNotamStaleFailclosedSeconds(),
    ];
}

/**
 * Determine staleness tier for a given age
 * 
 * @param int $ageSeconds Age of data in seconds
 * @param array $thresholds ['warning_seconds' => int, 'error_seconds' => int, 'failclosed_seconds' => int]
 * @return string 'fresh' | 'warning' | 'error' | 'failclosed'
 */
function getStaleTier(int $ageSeconds, array $thresholds): string {
    if ($ageSeconds >= $thresholds['failclosed_seconds']) {
        return 'failclosed';
    }
    if ($ageSeconds >= $thresholds['error_seconds']) {
        return 'error';
    }
    if ($ageSeconds >= $thresholds['warning_seconds']) {
        return 'warning';
    }
    return 'fresh';
}

// =============================================================================
// END STALENESS THRESHOLD HELPERS
// =============================================================================

/**
 * Get default webcam refresh interval from global config
 * @return int Default webcam refresh in seconds (default: 60)
 */
function getDefaultWebcamRefresh(): int {
    return (int)getGlobalConfig('webcam_refresh_default', 60);
}

/**
 * Get default weather refresh interval from global config
 * @return int Default weather refresh in seconds (default: 60)
 */
function getDefaultWeatherRefresh(): int {
    return (int)getGlobalConfig('weather_refresh_default', 60);
}

/**
 * Get weather worker pool size from global config
 * @return int Number of concurrent workers (default: 5)
 */
function getWeatherWorkerPoolSize(): int {
    return (int)getGlobalConfig('weather_worker_pool_size', 5);
}

/**
 * Get webcam worker pool size from global config
 * @return int Number of concurrent workers (default: 5)
 */
function getWebcamWorkerPoolSize(): int {
    return (int)getGlobalConfig('webcam_worker_pool_size', 5);
}

/**
 * Get worker timeout from global config
 * @return int Timeout in seconds (default: 90)
 */
function getWorkerTimeout(): int {
    return (int)getGlobalConfig('worker_timeout_seconds', 90);
}

/**
 * Get NOTAM refresh interval from global config
 * @return int Refresh interval in seconds (default: 600 = 10 minutes)
 */
function getNotamRefreshSeconds(): int {
    return (int)getGlobalConfig('notam_refresh_seconds', 600);
}

/**
 * Get NOTAM cache TTL from global config
 * @return int Cache TTL in seconds (default: 3600 = 1 hour)
 */
function getNotamCacheTtlSeconds(): int {
    return (int)getGlobalConfig('notam_cache_ttl_seconds', 3600);
}

/**
 * Get NOTAM worker pool size from global config
 * @return int Number of concurrent workers (default: 1)
 */
function getNotamWorkerPoolSize(): int {
    return (int)getGlobalConfig('notam_worker_pool_size', 1);
}

/**
 * Get NOTAM API client ID from global config
 * @return string Client ID or empty string if not configured
 */
function getNotamApiClientId(): string {
    return (string)getGlobalConfig('notam_api_client_id', '');
}

/**
 * Get NOTAM API client secret from global config
 * @return string Client secret or empty string if not configured
 */
function getNotamApiClientSecret(): string {
    return (string)getGlobalConfig('notam_api_client_secret', '');
}

/**
 * Get NOTAM API base URL from global config
 * @return string Base URL (default: staging URL)
 */
function getNotamApiBaseUrl(): string {
    return (string)getGlobalConfig('notam_api_base_url', 'https://api-staging.cgifederal-aim.com');
}

/**
 * Validate refresh interval
 * 
 * Validates that a refresh interval meets minimum requirements.
 * Refresh intervals must be integers >= minimum (default: 5 seconds).
 * 
 * @param mixed $interval Refresh interval to validate
 * @param int $minimum Minimum allowed interval in seconds (default: 5)
 * @return bool True if valid, false otherwise
 */
function validateRefreshInterval($interval, int $minimum = 5): bool {
    if (!is_int($interval) && !is_numeric($interval)) {
        return false;
    }
    $interval = (int)$interval;
    return $interval >= $minimum;
}

/**
 * Get minimum refresh interval from global config
 * 
 * Returns the minimum allowed refresh interval for any data source.
 * This is a safety limit to prevent excessive update frequency.
 * 
 * @return int Minimum refresh interval in seconds (default: 5)
 */
function getMinimumRefreshInterval(): int {
    return (int)getGlobalConfig('minimum_refresh_seconds', 5);
}

/**
 * Get scheduler config reload interval from global config
 * 
 * Returns how often the scheduler should check for config changes.
 * 
 * @return int Config reload check interval in seconds (default: 60)
 */
function getSchedulerConfigReloadInterval(): int {
    return (int)getGlobalConfig('scheduler_config_reload_seconds', 60);
}

/**
 * Get METAR refresh interval from config
 * 
 * Returns the global METAR refresh interval from config section.
 * METAR data is updated hourly, so this should always be >= 60 seconds.
 * 
 * @param array|null $config Config array (if already loaded, pass to avoid reload)
 * @return int METAR refresh interval in seconds (default: 60, minimum: 60)
 */
function getMetarRefreshInterval(?array $config = null): int {
    if ($config === null) {
        $config = loadConfig();
    }
    
    if ($config === null || !isset($config['config'])) {
        return 60;
    }
    
    $interval = isset($config['config']['metar_refresh_seconds'])
        ? intval($config['config']['metar_refresh_seconds'])
        : 60;
    
    // Enforce minimum of 60 seconds (METAR updates are hourly)
    return max(60, $interval);
}

/**
 * Check if WebP generation is enabled globally
 * 
 * @return bool True if WebP generation enabled, false otherwise
 */
function isWebpGenerationEnabled(): bool {
    return (bool)getGlobalConfig('webcam_generate_webp', false);
}

/**
 * Get list of enabled formats for webcam generation
 * 
 * @return array Array of enabled format strings: ['jpg', 'webp']
 */
function getEnabledWebcamFormats(): array {
    $formats = ['jpg']; // Always enabled
    
    if (isWebpGenerationEnabled()) {
        $formats[] = 'webp';
    }
    
    return $formats;
}

/**
 * Get WebP quality setting
 * 
 * Returns configured WebP quality (0-100 scale, higher = better).
 * Can be overridden in airports.json config.webcam_webp_quality
 * 
 * @return int WebP quality value (default: 80)
 */
function getWebcamWebpQuality(): int {
    return (int)getGlobalConfig('webcam_webp_quality', WEBCAM_WEBP_QUALITY);
}

/**
 * Get JPEG quality setting
 * 
 * Returns configured JPEG quality (1-31 scale for ffmpeg, lower = better).
 * Can be overridden in airports.json config.webcam_jpeg_quality
 * 
 * @return int JPEG quality value (default: 2)
 */
function getWebcamJpegQuality(): int {
    return (int)getGlobalConfig('webcam_jpeg_quality', WEBCAM_JPEG_QUALITY);
}

/**
 * Get image primary size from config
 * 
 * Returns the configured primary image size, defaulting to 1920x1080.
 * 
 * @return string Resolution string in format "WIDTHxHEIGHT" (e.g., "1920x1080")
 */
function getImagePrimarySize(): string {
    return getGlobalConfig('image_primary_size', '1920x1080');
}

/**
 * Get image max resolution from config
 * 
 * Returns the configured maximum image resolution, defaulting to 3840x2160 (4K).
 * Images larger than this will be downscaled.
 * 
 * @return string Resolution string in format "WIDTHxHEIGHT" (e.g., "3840x2160")
 */
function getImageMaxResolution(): string {
    return getGlobalConfig('image_max_resolution', '3840x2160');
}

/**
 * Get image aspect ratio from config
 * 
 * Returns the configured target aspect ratio, defaulting to "16:9".
 * 
 * @return string Aspect ratio string (e.g., "16:9")
 */
function getImageAspectRatio(): string {
    return getGlobalConfig('image_aspect_ratio', '16:9');
}

/**
 * Get list of image variant heights to generate
 * 
 * Returns the configured list of variant heights to generate.
 * Defaults to [1080, 720, 360] (standard video resolutions).
 * Heights are in pixels; width is calculated from aspect ratio.
 * 
 * @return array Array of integer heights
 */
function getImageVariants(): array {
    $variants = getGlobalConfig('image_variants', null);
    if ($variants === null || !is_array($variants)) {
        return [1080, 720, 360];
    }
    // Ensure all values are integers
    return array_map('intval', $variants);
}

// =============================================================================
// WEBCAM HISTORY CONFIGURATION
// =============================================================================

/** Default retention period in hours */
const WEBCAM_HISTORY_RETENTION_HOURS_DEFAULT = 24;

/** Default period to show in UI (hours) */
const WEBCAM_HISTORY_DEFAULT_HOURS_DEFAULT = 3;

/** Default period presets for UI */
const WEBCAM_HISTORY_PRESET_HOURS_DEFAULT = [1, 3, 6, 24];

/** Safety multiplier for implicit max frames calculation */
const WEBCAM_HISTORY_FRAME_SAFETY_MULTIPLIER = 2.0;

/**
 * Check if webcam history is enabled for an airport
 * 
 * History is considered enabled when retention > 0 and would result in >= 2 frames.
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @return bool True if webcam history enabled for this airport
 */
function isWebcamHistoryEnabledForAirport(string $airportId): bool {
    $retentionHours = getWebcamHistoryRetentionHours($airportId);
    return $retentionHours > 0;
}

/**
 * Get webcam history retention period in hours
 * 
 * Checks airport-specific setting first, falls back to global default.
 * Supports both new time-based config and legacy frame-based config.
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @return float Retention period in hours
 */
function getWebcamHistoryRetentionHours(string $airportId): float {
    $config = loadConfig();
    if ($config === null) {
        return WEBCAM_HISTORY_RETENTION_HOURS_DEFAULT;
    }
    
    $airport = $config['airports'][$airportId] ?? [];
    
    // New config: webcam_history_retention_hours (takes precedence)
    if (isset($airport['webcam_history_retention_hours'])) {
        return (float)$airport['webcam_history_retention_hours'];
    }
    
    // Check global new config
    if (isset($config['webcam_history_retention_hours'])) {
        return (float)$config['webcam_history_retention_hours'];
    }
    
    // Legacy config: webcam_history_max_frames (convert to hours)
    // Assume 60-second refresh for conversion if not specified
    if (isset($airport['webcam_history_max_frames'])) {
        aviationwx_log('warning', 'Deprecated config: webcam_history_max_frames - use webcam_history_retention_hours instead', [
            'airport' => $airportId
        ]);
        $maxFrames = (int)$airport['webcam_history_max_frames'];
        $refreshSeconds = getWebcamRefreshSecondsForAirport($airportId);
        return ($maxFrames * $refreshSeconds) / 3600;
    }
    
    // Check global legacy config
    if (isset($config['webcam_history_max_frames'])) {
        aviationwx_log('warning', 'Deprecated config: webcam_history_max_frames - use webcam_history_retention_hours instead');
        $maxFrames = (int)$config['webcam_history_max_frames'];
        $refreshSeconds = getWebcamRefreshSecondsForAirport($airportId);
        return ($maxFrames * $refreshSeconds) / 3600;
    }
    
    return WEBCAM_HISTORY_RETENTION_HOURS_DEFAULT;
}

/**
 * Get default period to show in history player UI (hours)
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @return int Default period in hours
 */
function getWebcamHistoryDefaultHours(string $airportId): int {
    $config = loadConfig();
    if ($config === null) {
        return WEBCAM_HISTORY_DEFAULT_HOURS_DEFAULT;
    }
    
    $airport = $config['airports'][$airportId] ?? [];
    
    // Airport-specific
    if (isset($airport['webcam_history_default_hours'])) {
        return (int)$airport['webcam_history_default_hours'];
    }
    
    // Global
    if (isset($config['webcam_history_default_hours'])) {
        return (int)$config['webcam_history_default_hours'];
    }
    
    return WEBCAM_HISTORY_DEFAULT_HOURS_DEFAULT;
}

/**
 * Get preset periods for history player UI (hours)
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @return array Array of preset hours [1, 3, 6, 24]
 */
function getWebcamHistoryPresetHours(string $airportId): array {
    $config = loadConfig();
    if ($config === null) {
        return WEBCAM_HISTORY_PRESET_HOURS_DEFAULT;
    }
    
    $airport = $config['airports'][$airportId] ?? [];
    
    // Airport-specific
    if (isset($airport['webcam_history_preset_hours']) && is_array($airport['webcam_history_preset_hours'])) {
        $presets = array_map('intval', $airport['webcam_history_preset_hours']);
        sort($presets);
        return $presets;
    }
    
    // Global
    if (isset($config['webcam_history_preset_hours']) && is_array($config['webcam_history_preset_hours'])) {
        $presets = array_map('intval', $config['webcam_history_preset_hours']);
        sort($presets);
        return $presets;
    }
    
    return WEBCAM_HISTORY_PRESET_HOURS_DEFAULT;
}

/**
 * Get full history UI configuration for an airport
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @return array Configuration array with preset_hours and default_hours
 */
function getWebcamHistoryUIConfig(string $airportId): array {
    return [
        'preset_hours' => getWebcamHistoryPresetHours($airportId),
        'default_hours' => getWebcamHistoryDefaultHours($airportId)
    ];
}

/**
 * Get typical refresh rate for an airport's webcams (for legacy conversion)
 * 
 * @param string $airportId Airport ID
 * @return int Refresh seconds (default 60)
 */
function getWebcamRefreshSecondsForAirport(string $airportId): int {
    $config = loadConfig();
    if ($config === null) {
        return 60;
    }
    
    $airport = $config['airports'][$airportId] ?? [];
    $webcams = $airport['webcams'] ?? [];
    
    // Use first webcam's refresh rate, or default
    if (!empty($webcams) && isset($webcams[0]['refresh_seconds'])) {
        return (int)$webcams[0]['refresh_seconds'];
    }
    
    return 60;
}

/**
 * Calculate implicit max frames for a camera (safety limit)
 * 
 * Uses retention hours and refresh rate to calculate expected frames,
 * then applies 2x safety multiplier.
 * 
 * @param float $retentionHours Retention period in hours
 * @param int $refreshSeconds Refresh interval in seconds
 * @return int Maximum frames to keep (safety limit)
 */
function calculateImplicitMaxFrames(float $retentionHours, int $refreshSeconds): int {
    $expectedFrames = $retentionHours * (3600 / max(1, $refreshSeconds));
    return (int)ceil($expectedFrames * WEBCAM_HISTORY_FRAME_SAFETY_MULTIPLIER);
}

/**
 * Get max history frames for an airport
 * 
 * @deprecated Use getWebcamHistoryRetentionHours() instead
 * 
 * Checks airport-specific setting first, falls back to global default.
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @return int Max frames to retain per camera
 */
function getWebcamHistoryMaxFrames(string $airportId): int {
    // For backward compatibility, calculate from retention hours
    $retentionHours = getWebcamHistoryRetentionHours($airportId);
    $refreshSeconds = getWebcamRefreshSecondsForAirport($airportId);
    
    // Return expected frames (not safety limit) for compatibility
    return (int)ceil($retentionHours * (3600 / max(1, $refreshSeconds)));
}

/**
 * Valid values for each preference type
 */
define('VALID_PREFERENCE_VALUES', [
    'time_format' => ['12hr', '24hr'],
    'temp_unit' => ['F', 'C'],
    'distance_unit' => ['ft', 'm'],
    'baro_unit' => ['inHg', 'hPa', 'mmHg'],
    'wind_speed_unit' => ['kts', 'mph', 'km/h'],
]);

/**
 * Default preference values (hardcoded fallbacks)
 */
define('DEFAULT_PREFERENCE_VALUES', [
    'time_format' => '12hr',
    'temp_unit' => 'F',
    'distance_unit' => 'ft',
    'baro_unit' => 'inHg',
    'wind_speed_unit' => 'kts',
]);

/**
 * Validate default_preferences object
 * 
 * Validates that all preference values are valid options.
 * 
 * @param mixed $preferences The default_preferences value to validate
 * @param string $context Context for error messages (e.g., "config" or "Airport 'kspb'")
 * @return array Array of error messages (empty if valid)
 */
function validateDefaultPreferences(mixed $preferences, string $context): array {
    $errors = [];
    
    if (!is_array($preferences)) {
        $errors[] = "{$context}.default_preferences must be an object";
        return $errors;
    }
    
    $allowedKeys = array_keys(VALID_PREFERENCE_VALUES);
    
    foreach ($preferences as $key => $value) {
        if (!in_array($key, $allowedKeys, true)) {
            $errors[] = "{$context}.default_preferences has unknown field '{$key}'. Allowed: " . implode(', ', $allowedKeys);
            continue;
        }
        
        if (!is_string($value)) {
            $errors[] = "{$context}.default_preferences.{$key} must be a string";
            continue;
        }
        
        $validValues = VALID_PREFERENCE_VALUES[$key];
        if (!in_array($value, $validValues, true)) {
            $errors[] = "{$context}.default_preferences.{$key} has invalid value '{$value}'. Allowed: " . implode(', ', $validValues);
        }
    }
    
    return $errors;
}

/**
 * Validate public_api configuration section
 * 
 * Validates the nested public_api configuration including:
 * - enabled flag
 * - version string
 * - rate_limits (anonymous and partner)
 * - bulk_max_airports
 * - weather_history settings
 * - partner_keys
 * 
 * @param mixed $publicApi The public_api config value to validate
 * @return array Array of error messages (empty if valid)
 */
function validatePublicApiConfig(mixed $publicApi): array {
    $errors = [];
    
    if (!is_array($publicApi)) {
        $errors[] = "config.public_api must be an object";
        return $errors;
    }
    
    // enabled: boolean
    if (isset($publicApi['enabled'])) {
        if (!is_bool($publicApi['enabled'])) {
            $errors[] = "config.public_api.enabled must be a boolean (true or false)";
        }
    }
    
    // version: string
    if (isset($publicApi['version'])) {
        if (!is_string($publicApi['version'])) {
            $errors[] = "config.public_api.version must be a string";
        }
    }
    
    // bulk_max_airports: positive integer
    if (isset($publicApi['bulk_max_airports'])) {
        if (!is_int($publicApi['bulk_max_airports']) || $publicApi['bulk_max_airports'] < 1) {
            $errors[] = "config.public_api.bulk_max_airports must be a positive integer";
        }
    }
    
    // weather_history_enabled: boolean
    if (isset($publicApi['weather_history_enabled'])) {
        if (!is_bool($publicApi['weather_history_enabled'])) {
            $errors[] = "config.public_api.weather_history_enabled must be a boolean";
        }
    }
    
    // weather_history_retention_hours: positive integer
    if (isset($publicApi['weather_history_retention_hours'])) {
        if (!is_int($publicApi['weather_history_retention_hours']) || $publicApi['weather_history_retention_hours'] < 1) {
            $errors[] = "config.public_api.weather_history_retention_hours must be a positive integer";
        }
    }
    
    // attribution_text: string
    if (isset($publicApi['attribution_text'])) {
        if (!is_string($publicApi['attribution_text'])) {
            $errors[] = "config.public_api.attribution_text must be a string";
        }
    }
    
    // rate_limits: object with anonymous and partner sub-objects
    if (isset($publicApi['rate_limits'])) {
        if (!is_array($publicApi['rate_limits'])) {
            $errors[] = "config.public_api.rate_limits must be an object";
        } else {
            $rateLimitTypes = ['anonymous', 'partner'];
            $rateLimitFields = ['requests_per_minute', 'requests_per_hour', 'requests_per_day'];
            
            foreach ($rateLimitTypes as $type) {
                if (isset($publicApi['rate_limits'][$type])) {
                    if (!is_array($publicApi['rate_limits'][$type])) {
                        $errors[] = "config.public_api.rate_limits.{$type} must be an object";
                    } else {
                        foreach ($rateLimitFields as $field) {
                            if (isset($publicApi['rate_limits'][$type][$field])) {
                                $val = $publicApi['rate_limits'][$type][$field];
                                if (!is_int($val) || $val < 1) {
                                    $errors[] = "config.public_api.rate_limits.{$type}.{$field} must be a positive integer";
                                }
                            }
                        }
                    }
                }
            }
        }
    }
    
    // partner_keys: object with API key entries
    if (isset($publicApi['partner_keys'])) {
        if (!is_array($publicApi['partner_keys'])) {
            $errors[] = "config.public_api.partner_keys must be an object";
        } else {
            foreach ($publicApi['partner_keys'] as $apiKey => $keyConfig) {
                // Validate API key format: ak_live_ or ak_test_ prefix
                if (!is_string($apiKey) || !preg_match('/^ak_(live|test)_[a-zA-Z0-9]+$/', $apiKey)) {
                    $errors[] = "config.public_api.partner_keys key '{$apiKey}' must match format ak_live_* or ak_test_*";
                }
                
                if (!is_array($keyConfig)) {
                    $errors[] = "config.public_api.partner_keys.{$apiKey} must be an object";
                    continue;
                }
                
                // Required: name
                if (!isset($keyConfig['name']) || !is_string($keyConfig['name']) || empty($keyConfig['name'])) {
                    $errors[] = "config.public_api.partner_keys.{$apiKey}.name is required and must be a non-empty string";
                }
                
                // Optional: contact (string)
                if (isset($keyConfig['contact']) && !is_string($keyConfig['contact'])) {
                    $errors[] = "config.public_api.partner_keys.{$apiKey}.contact must be a string";
                }
                
                // Optional: enabled (boolean)
                if (isset($keyConfig['enabled']) && !is_bool($keyConfig['enabled'])) {
                    $errors[] = "config.public_api.partner_keys.{$apiKey}.enabled must be a boolean";
                }
                
                // Optional: created (string, ideally date format)
                if (isset($keyConfig['created']) && !is_string($keyConfig['created'])) {
                    $errors[] = "config.public_api.partner_keys.{$apiKey}.created must be a string";
                }
                
                // Optional: notes (string)
                if (isset($keyConfig['notes']) && !is_string($keyConfig['notes'])) {
                    $errors[] = "config.public_api.partner_keys.{$apiKey}.notes must be a string";
                }
            }
        }
    }
    
    return $errors;
}

/**
 * Get default preferences for an airport
 * 
 * Merges preferences in order: hardcoded defaults → global config → airport override.
 * Returns only the preferences that differ from hardcoded defaults.
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @return array Merged default preferences
 */
function getDefaultPreferencesForAirport(string $airportId): array {
    $config = loadConfig();
    if ($config === null) {
        return [];
    }
    
    // Start with empty (JS will use its own hardcoded defaults as final fallback)
    $defaults = [];
    
    // Layer 1: Global config defaults
    if (isset($config['config']['default_preferences']) && is_array($config['config']['default_preferences'])) {
        foreach ($config['config']['default_preferences'] as $key => $value) {
            if (isset(VALID_PREFERENCE_VALUES[$key]) && in_array($value, VALID_PREFERENCE_VALUES[$key], true)) {
                $defaults[$key] = $value;
            }
        }
    }
    
    // Layer 2: Airport-specific overrides
    if (isset($config['airports'][$airportId]['default_preferences']) && is_array($config['airports'][$airportId]['default_preferences'])) {
        foreach ($config['airports'][$airportId]['default_preferences'] as $key => $value) {
            if (isset(VALID_PREFERENCE_VALUES[$key]) && in_array($value, VALID_PREFERENCE_VALUES[$key], true)) {
                $defaults[$key] = $value;
            }
        }
    }
    
    return $defaults;
}

/**
 * Load airport configuration with caching
 * 
 * Uses APCu cache if available, falls back to static variable for request lifetime.
 * Automatically invalidates cache when file modification time changes.
 * 
 * IMPORTANT: airports.json is NOT in the repository - it only exists on the production host.
 * - CI (GitHub Actions): Never has access - runs in GitHub's cloud, uses test fixtures
 * - CD (Deployment): Has access - runs on production host where file exists at /home/aviationwx/airports.json
 * 
 * SECURITY: Prevents test data (airports.json.test) from being used in production.
 * 
 * @param bool $useCache Whether to use APCu caching (default: true)
 * @return array|null Configuration array with 'airports' and optional 'config' keys, or null on error
 */
function loadConfig(bool $useCache = true): ?array {
    static $cachedConfig = null;
    
    // Get config file path
    $envConfigPath = getenv('CONFIG_PATH');
    
    // Check if CONFIG_PATH is set and is a file (not a directory)
    if ($envConfigPath && file_exists($envConfigPath) && is_file($envConfigPath)) {
        $configFile = $envConfigPath;
    } else {
        // Fall back to default path
        $configFile = __DIR__ . '/../config/airports.json';
        // If default path doesn't exist and not in production, try secrets path (for local dev)
        if ((!file_exists($configFile) || is_dir($configFile)) && !isProduction()) {
            $secretsPath = __DIR__ . '/../../aviationwx.org-secrets/airports.json';
            $secretsPathAlt = __DIR__ . '/../../aviationwx-secrets/airports.json';
            if (file_exists($secretsPath)) {
                $configFile = $secretsPath;
            } elseif (file_exists($secretsPathAlt)) {
                $configFile = $secretsPathAlt;
            }
        }
        // Last resort: try /var/www/html/airports.json (production mount point)
        if ((!file_exists($configFile) || is_dir($configFile))) {
            $configFile = '/var/www/html/airports.json';
        }
    }
    
    // SECURITY: Prevent test data from being used in production
    $isProduction = isProduction();
    if ($isProduction) {
        // Check if config file path contains test file
        if (strpos($configFile, 'airports.json.test') !== false || 
            strpos($configFile, 'json.test') !== false ||
            basename($configFile) === 'airports.json.test') {
            aviationwx_log('error', 'test config file blocked in production', [
                'path' => $configFile,
                'env_config_path' => $envConfigPath
            ], 'app');
            error_log('SECURITY: Attempted to use test config file in production: ' . $configFile);
            return null;
        }
        
        // Ensure we're using the production airports.json, not test file
        if (basename($configFile) !== 'airports.json') {
            aviationwx_log('warning', 'non-standard config file in production', [
                'path' => $configFile,
                'basename' => basename($configFile)
            ], 'app');
        }
    }
    
    // Validate file exists and is not a directory
    // Note: In CI (GitHub Actions), airports.json doesn't exist - this is expected and handled gracefully
    if (!file_exists($configFile)) {
        // In CI, this is normal - tests use CONFIG_PATH pointing to test fixtures
        // In production, this is a critical failure - airports.json MUST exist
        if (!isProduction()) {
            // Non-production: log as info (expected in CI/test environments)
            aviationwx_log('info', 'config file not found (using defaults)', ['path' => $configFile], 'app');
        } else {
            // Production: CRITICAL ERROR - airports.json is required, fail immediately
            aviationwx_log('error', 'config file not found - PRODUCTION FAILURE', ['path' => $configFile], 'app', true);
            error_log('CRITICAL: airports.json not found in production at: ' . $configFile);
            // In production, we cannot continue without airports.json - return null to fail fast
            // The application should handle this gracefully by showing an error page
        }
        return null;
    }
    if (is_dir($configFile)) {
        aviationwx_log('error', 'config path is directory', ['path' => $configFile], 'app', true);
        return null;
    }
    
    // Read file content to compute SHA hash (needed for change detection)
    $jsonContent = @file_get_contents($configFile);
    if ($jsonContent === false) {
        aviationwx_log('error', 'config read failed', ['path' => $configFile], 'app', true);
        return null;
    }
    
    // Compute SHA-256 hash of file content for reliable change detection
    // This detects ANY content change, not just structural changes
    $fileSha = hash('sha256', $jsonContent);
    $cacheKey = 'aviationwx_config';
    $cacheShaKey = 'aviationwx_config_sha';
    
    // Try APCu cache first (if available)
    if ($useCache && function_exists('apcu_fetch')) {
        // Check if cached SHA hash matches current file SHA
        // SHA is more reliable than mtime (catches all content changes)
        $cachedSha = apcu_fetch($cacheShaKey);
        if ($cachedSha !== false && $cachedSha === $fileSha) {
            // File content hasn't changed, return cached config
            $cached = apcu_fetch($cacheKey);
            if ($cached !== false) {
                // Also update static cache
                $cachedConfig = $cached;
                return $cached;
            }
        } else {
            // File changed or cache expired, clear old cache
            apcu_delete($cacheKey);
            apcu_delete($cacheShaKey);
        }
    }
    
    // Use static cache for request lifetime (but check file hasn't changed)
    // We also store file SHA hash and path in static variables to detect changes
    static $cachedConfigSha = null;
    static $cachedConfigPath = null;
    
    // Check if cache is valid: same file path AND same SHA hash
    if ($cachedConfig !== null && 
        $cachedConfigPath === $configFile && 
        $cachedConfigSha === $fileSha) {
        // File hasn't changed in this request, return cached config
        return $cachedConfig;
    }
    
    // File changed, path changed, or no cache - clear static cache
    $cachedConfig = null;
    $cachedConfigSha = null;
    $cachedConfigPath = null;
    
    // Validate JSON (content already read above)
    
    $config = json_decode($jsonContent, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($config)) {
        aviationwx_log('error', 'config invalid json', ['error' => json_last_error_msg(), 'path' => $configFile], 'app', true);
        return null;
    }
    
    // Check for duplicate keys in JSON (PHP silently uses last value for duplicates)
    $duplicateKeys = detectDuplicateJsonKeys($jsonContent);
    if (!empty($duplicateKeys)) {
        aviationwx_log('error', 'config has duplicate JSON keys', [
            'path' => $configFile,
            'duplicate_keys' => $duplicateKeys
        ], 'app', true);
        // Don't fail - just warn. The config is still valid JSON, just has overwrites.
        // This helps catch config errors like having two "weather_source" keys in an airport.
    }

    // Basic schema validation (lightweight)
    $errors = [];
    
    // Validate global config section if present (optional)
    if (isset($config['config']) && !is_array($config['config'])) {
        $errors[] = 'Root.config must be an object';
    }
    
    if (!isset($config['airports']) || !is_array($config['airports'])) {
        $errors[] = 'Root.airports must be an object';
    } else {
        // Validate push_webcam_settings if present
        if (isset($config['push_webcam_settings'])) {
            require_once __DIR__ . '/push-webcam-validator.php';
            $settingsValidation = validatePushWebcamSettings($config['push_webcam_settings']);
            if (!$settingsValidation['valid']) {
                $errors = array_merge($errors, $settingsValidation['errors']);
            }
        }
        
        foreach ($config['airports'] as $aid => $ap) {
            if (!validateAirportId($aid)) {
                $errors[] = "Airport key '{$aid}' is invalid (must be 3-50 lowercase alphanumeric characters, hyphens allowed)";
            }
            
            if (!isset($ap['webcams']) || !is_array($ap['webcams'])) {
                // Allow no webcams
                continue;
            }
            foreach ($ap['webcams'] as $idx => $cam) {
                // Check if push camera
                $isPush = (isset($cam['type']) && $cam['type'] === 'push') 
                       || isset($cam['push_config']);
                
                if ($isPush) {
                    // Validate push camera configuration
                    require_once __DIR__ . '/push-webcam-validator.php';
                    $pushValidation = validatePushWebcamConfig($cam, $aid, $idx);
                    if (!$pushValidation['valid']) {
                        $errors = array_merge($errors, $pushValidation['errors']);
                    }
                } else {
                    // Validate pull camera (existing logic)
                    if (!isset($cam['name']) || !isset($cam['url'])) {
                        $errors[] = "Airport '{$aid}' webcam index {$idx} missing required fields (name,url)";
                        continue;
                    }
                    if (isset($cam['type']) && !in_array($cam['type'], ['rtsp','mjpeg','static_jpeg','static_png','push'])) {
                        $errors[] = "Airport '{$aid}' webcam index {$idx} has invalid type '{$cam['type']}'";
                    }
                    if (isset($cam['rtsp_transport']) && !in_array(strtolower($cam['rtsp_transport']), ['tcp','udp'])) {
                        $errors[] = "Airport '{$aid}' webcam index {$idx} has invalid rtsp_transport";
                    }
                }
            }
        }
        
        // Check for duplicate push usernames
        require_once __DIR__ . '/push-webcam-validator.php';
        $usernameValidation = validateUniquePushUsernames($config);
        if (!$usernameValidation['valid']) {
            $errors = array_merge($errors, $usernameValidation['errors']);
        }
    }
    if (!empty($errors)) {
        // Log and fail-fast
        aviationwx_log('error', 'config schema errors', ['errors' => $errors], 'app', true);
        return null;
    }

    aviationwx_log('info', 'config loaded', ['path' => $configFile, 'sha' => substr($fileSha, 0, 8)], 'app');
    
    // Cache in static variable (with SHA hash and path)
    $cachedConfig = $config;
    $cachedConfigSha = $fileSha;
    $cachedConfigPath = $configFile;
    
    // Cache in APCu if available (1 hour TTL, but invalidated on file change)
    if ($useCache && function_exists('apcu_store')) {
        apcu_store($cacheKey, $config, CONFIG_CACHE_TTL);
        apcu_store($cacheShaKey, $fileSha, CONFIG_CACHE_TTL);
    }
    
    return $config;
}

/**
 * Clear configuration cache
 * 
 * Removes the config data and SHA hash from APCu.
 * This forces the next loadConfig() call to reload from disk.
 * 
 * @return void
 */
function clearConfigCache(): void {
    if (function_exists('apcu_delete')) {
        apcu_delete('aviationwx_config');
        apcu_delete('aviationwx_config_sha');
    }
}

/**
 * Get sanitized airport ID from request
 * 
 * Checks both query parameter and subdomain, validating format before returning.
 * Supports both ?airport=kspb and kspb.aviationwx.org patterns.
 * 
 * @return string Validated airport ID (3-50 lowercase alphanumeric, hyphens allowed) or empty string
 */
function getAirportIdFromRequest(): string {
    $identifier = '';
    
    // First, try query parameter
    if (isset($_GET['airport']) && !empty($_GET['airport'])) {
        $identifier = trim($_GET['airport']);
    } else {
        // Try extracting from subdomain
        $host = isset($_SERVER['HTTP_HOST']) ? strtolower(trim($_SERVER['HTTP_HOST'])) : '';
        $baseDomain = getBaseDomain();
        
        // Match subdomain pattern (e.g., kspb.aviationwx.org or 03s.aviationwx.org)
        // Uses base domain from config to support custom domains
        // Support 3-4 alphanumeric (ICAO/FAA) and custom identifiers (with hyphens)
        $pattern = '/^([a-z0-9-]{3,50})\.' . preg_quote($baseDomain, '/') . '$/';
        if (preg_match($pattern, $host, $matches)) {
            $identifier = $matches[1];
        } else {
            // Fallback: check if host has 3+ parts (handles other TLDs and custom domains)
            $hostParts = explode('.', $host);
            if (count($hostParts) >= 3) {
                $identifier = $hostParts[0];
            }
        }
    }
    
    if (empty($identifier)) {
        return '';
    }
    
    // Try to find airport by identifier (supports ICAO, IATA, FAA, or airport ID)
    $result = findAirportByIdentifier($identifier);
    if ($result !== null && isset($result['airportId'])) {
        return $result['airportId'];
    }
    
    // Fallback: if identifier matches airport ID format, return as-is (backward compatibility)
    if (validateAirportId($identifier)) {
        return strtolower($identifier);
    }
    
    return '';
}

/**
 * Find airport by any identifier type (ICAO, IATA, FAA, or airport ID)
 * 
 * Searches for an airport using priority:
 * 1. Direct key lookup (primary identifier)
 * 2. ICAO code (preferred)
 * 3. IATA code
 * 4. FAA identifier
 * 
 * @param string $identifier The identifier to search for (case-insensitive)
 * @param array|null $config Optional config array (if already loaded)
 * @return array|null Airport configuration array with 'airport' and 'airportId' keys, or null if not found
 *                    Returns ['airport' => array, 'airportId' => string] on success
 */
function findAirportByIdentifier(string $identifier, ?array $config = null): ?array {
    if ($config === null) {
        $config = loadConfig();
    }
    if (!$config || !isset($config['airports'])) {
        return null;
    }
    
    $identifier = trim($identifier);
    if (empty($identifier)) {
        return null;
    }
    
    $identifierUpper = strtoupper($identifier);
    $identifierLower = strtolower($identifier);
    
    // 1. Direct key lookup (primary identifier - backward compatibility)
    if (isset($config['airports'][$identifierUpper])) {
        return ['airport' => $config['airports'][$identifierUpper], 'airportId' => $identifierUpper];
    }
    if (isset($config['airports'][$identifierLower])) {
        return ['airport' => $config['airports'][$identifierLower], 'airportId' => $identifierLower];
    }
    if (isset($config['airports'][$identifier])) {
        return ['airport' => $config['airports'][$identifier], 'airportId' => $identifier];
    }
    
    // 2. Search by identifier type (priority: ICAO > IATA > FAA)
    foreach ($config['airports'] as $airportId => $airport) {
        // Check ICAO (preferred) - guard against null/empty to prevent PHP 8.1+ deprecation
        if (isset($airport['icao']) && !empty($airport['icao']) && strtoupper(trim($airport['icao'])) === $identifierUpper) {
            return ['airport' => $airport, 'airportId' => $airportId];
        }
        // Check IATA - guard against null/empty
        if (isset($airport['iata']) && !empty($airport['iata']) && strtoupper(trim($airport['iata'])) === $identifierUpper) {
            return ['airport' => $airport, 'airportId' => $airportId];
        }
        // Check FAA - guard against null/empty
        if (isset($airport['faa']) && !empty($airport['faa']) && strtoupper(trim($airport['faa'])) === $identifierUpper) {
            return ['airport' => $airport, 'airportId' => $airportId];
        }
    }
    
    // 3. Fallback: Check cached mapping files (IATA->ICAO, FAA->ICAO) for airports not in airports.json
    // IMPORTANT: This creates an unconfigured airport lookup ONLY for redirect purposes.
    // Unconfigured airports are valid airport lookups but are NOT the same as airports in airports.json.
    // They will show 404, not a dashboard. This allows redirects (e.g., pdx -> kpdx) to work even if the airport isn't configured yet.
    $icaoFromMapping = getIcaoFromIdentifier($identifierUpper);
    
    // If we found an ICAO code from the mapping, create an unconfigured airport lookup
    // This lookup only has ICAO/IATA/FAA codes - no name or other required fields from airports.json.
    // The calling code must check if it's unconfigured and show 404 instead of a dashboard.
    if ($icaoFromMapping !== null) {
        $icaoLower = strtolower($icaoFromMapping);
        // Use ICAO code as airport ID (lowercase)
        $airportId = $icaoLower;
        
        // Create unconfigured airport lookup with just identifier codes
        // This allows redirect logic to work, but the airport will show 404 (not a dashboard)
        $unconfiguredAirport = [
            'icao' => $icaoFromMapping,
        ];
        
        // Add the original identifier if it's IATA or FAA
        $identifierType = detectIdentifierType($identifierUpper);
        if ($identifierType === 'iata') {
            $unconfiguredAirport['iata'] = $identifierUpper;
        } elseif ($identifierType === 'faa') {
            $unconfiguredAirport['faa'] = $identifierUpper;
        }
        
        return ['airport' => $unconfiguredAirport, 'airportId' => $airportId];
    }
    
    return null;
}

/**
 * Get the primary identifier for an airport based on priority
 * 
 * Priority: ICAO > IATA > FAA > Custom (airport ID)
 * 
 * @param string $airportId The airport ID (config key)
 * @param array|null $airport Optional airport config (if already loaded)
 * @return string The primary identifier to use
 */
function getPrimaryIdentifier(string $airportId, ?array $airport = null): string {
    if ($airport === null) {
        $config = loadConfig();
        if ($config && isset($config['airports'][$airportId])) {
            $airport = $config['airports'][$airportId];
        } else {
            return $airportId; // Fallback to airport ID
        }
    }
    
    // Priority: ICAO > IATA > FAA > Airport ID
    if (isset($airport['icao']) && !empty($airport['icao'])) {
        return strtoupper(trim($airport['icao']));
    }
    if (isset($airport['iata']) && !empty($airport['iata'])) {
        return strtoupper(trim($airport['iata']));
    }
    if (isset($airport['faa']) && !empty($airport['faa'])) {
        return strtoupper(trim($airport['faa']));
    }
    
    // Fallback to airport ID (may be custom identifier)
    return $airportId;
}

/**
 * Get the best available identifier for external links
 * 
 * Returns the best identifier (ICAO > IATA > FAA) that can be used for external links.
 * Unlike getPrimaryIdentifier(), this returns null if no standard identifier is available,
 * since external services won't accept arbitrary airport IDs.
 * 
 * Priority: ICAO > IATA > FAA
 * 
 * @param array $airport Airport configuration array
 * @return string|null The best identifier available, or null if none found
 */
function getBestIdentifierForLinks(array $airport): ?string {
    // Priority: ICAO > IATA > FAA
    if (isset($airport['icao']) && !empty($airport['icao'])) {
        return strtoupper(trim($airport['icao']));
    }
    if (isset($airport['iata']) && !empty($airport['iata'])) {
        return strtoupper(trim($airport['iata']));
    }
    if (isset($airport['faa']) && !empty($airport['faa'])) {
        return strtoupper(trim($airport['faa']));
    }
    
    // No standard identifier available
    return null;
}

/**
 * Check if an airport is enabled
 * 
 * Airports are opt-in: they must have `enabled: true` to be active.
 * Defaults to false if the field is missing or not explicitly true.
 * Uses strict boolean check to prevent accidental enabling via truthy values.
 * 
 * @param array $airport Airport configuration array
 * @return bool True if airport is enabled, false otherwise
 */
function isAirportEnabled(array $airport): bool {
    return isset($airport['enabled']) && $airport['enabled'] === true;
}

/**
 * Check if an airport is in maintenance mode
 * 
 * Returns true only if `maintenance` is explicitly set to true.
 * Defaults to false if the field is missing or not explicitly true.
 * Uses strict boolean check to prevent accidental enabling via truthy values.
 * 
 * @param array $airport Airport configuration array
 * @return bool True if airport is in maintenance mode, false otherwise
 */
function isAirportInMaintenance(array $airport): bool {
    return isset($airport['maintenance']) && $airport['maintenance'] === true;
}

/**
 * Check if an airport is unlisted (hidden from discovery)
 * 
 * Returns true only if `unlisted` is explicitly set to true.
 * Unlisted airports are enabled and process data, but are hidden from:
 * - Airport directory/map
 * - Navigation search
 * - Sitemaps (XML and HTML)
 * - Public API listings
 * 
 * Unlisted airports remain accessible via direct URL.
 * Useful for test sites and new airports being commissioned.
 * 
 * @param array $airport Airport configuration array
 * @return bool True if airport is unlisted, false otherwise
 */
function isAirportUnlisted(array $airport): bool {
    return isset($airport['unlisted']) && $airport['unlisted'] === true;
}

/**
 * Get only enabled airports from configuration
 * 
 * Filters the airports array to return only airports with `enabled: true`.
 * Preserves airport structure and keys.
 * 
 * @param array $config Configuration array with 'airports' key
 * @return array Filtered airports array containing only enabled airports
 */
function getEnabledAirports(array $config): array {
    if (!isset($config['airports']) || !is_array($config['airports'])) {
        return [];
    }
    
    $enabledAirports = [];
    foreach ($config['airports'] as $airportId => $airport) {
        if (isAirportEnabled($airport)) {
            $enabledAirports[$airportId] = $airport;
        }
    }
    
    return $enabledAirports;
}

/**
 * Get only listed (publicly discoverable) airports from configuration
 * 
 * Filters the airports array to return only airports that are:
 * - enabled (enabled: true)
 * - NOT unlisted (unlisted: false or missing)
 * 
 * Use this for public discovery channels: airport directory, map, search,
 * sitemaps, and public API listings.
 * 
 * For data processing or direct URL access, use getEnabledAirports() instead.
 * 
 * @param array $config Configuration array with 'airports' key
 * @return array Filtered airports array containing only listed airports
 */
function getListedAirports(array $config): array {
    $enabledAirports = getEnabledAirports($config);
    
    return array_filter($enabledAirports, function ($airport) {
        return !isAirportUnlisted($airport);
    });
}

/**
 * Check if the system is running in single-airport mode
 * 
 * Single-airport mode is triggered when exactly 1 airport is enabled.
 * This simplifies the UI by hiding multi-airport navigation elements.
 * 
 * @return bool True if only 1 airport is enabled, false otherwise
 */
function isSingleAirportMode(): bool {
    static $cachedResult = null;
    
    // Use static cache to avoid repeated config loads in same request
    if ($cachedResult !== null) {
        return $cachedResult;
    }
    
    $config = loadConfig();
    if (!$config) {
        $cachedResult = false;
        return false;
    }
    
    $enabledAirports = getEnabledAirports($config);
    $cachedResult = (count($enabledAirports) === 1);
    
    return $cachedResult;
}

/**
 * Get the single airport ID when in single-airport mode
 * 
 * @return string|null Airport ID if in single-airport mode, null otherwise
 */
function getSingleAirportId(): ?string {
    static $cachedId = null;
    static $cached = false;
    
    if ($cached) {
        return $cachedId;
    }
    
    if (!isSingleAirportMode()) {
        $cached = true;
        $cachedId = null;
        return null;
    }
    
    $config = loadConfig();
    if (!$config) {
        $cached = true;
        $cachedId = null;
        return null;
    }
    
    $enabledAirports = getEnabledAirports($config);
    $cachedId = array_key_first($enabledAirports);
    $cached = true;
    
    return $cachedId;
}

/**
 * Get the current Git commit SHA (short version)
 *
 * Tries multiple methods to get the SHA for display in footers:
 * 1. Environment variable (set during deployment)
 * 2. .git/HEAD file (if in git repository)
 * 3. git command (if available)
 *
 * @return string Short SHA (7 characters) or empty string if unavailable
 */
function getGitSha(): string {
    // Try environment variable first (set during deployment)
    $sha = getenv('GIT_SHA');
    if ($sha && strlen($sha) >= 7) {
        return substr($sha, 0, 7);
    }
    
    // Try reading from .git/HEAD (if in a git repository)
    $gitHead = __DIR__ . '/.git/HEAD';
    if (file_exists($gitHead)) {
        $headContent = @file_get_contents($gitHead);
        if ($headContent) {
            // Handle ref: refs/heads/main format
            if (preg_match('/^ref: (.+)$/', trim($headContent), $matches)) {
                $refFile = __DIR__ . '/.git/' . trim($matches[1]);
                if (file_exists($refFile)) {
                    $sha = trim(@file_get_contents($refFile));
                    if ($sha && strlen($sha) >= 7) {
                        return substr($sha, 0, 7);
                    }
                }
            } else {
                // Direct SHA in HEAD (detached HEAD state)
                $sha = trim($headContent);
                if ($sha && strlen($sha) >= 7) {
                    return substr($sha, 0, 7);
                }
            }
        }
    }
    
    // Try git command as fallback (if git is available)
    // Use --short=7 to force 7 characters (matching GitHub's short SHA display)
    if (function_exists('shell_exec')) {
        $sha = @shell_exec('cd ' . escapeshellarg(__DIR__) . ' && git rev-parse --short=7 HEAD 2>/dev/null');
        if ($sha) {
            $sha = trim($sha);
            // Always return exactly 7 characters to match GitHub's display
            if (strlen($sha) >= 7) {
                return substr($sha, 0, 7);
            } elseif (strlen($sha) > 0) {
                // If we got a shorter SHA, pad it or use full SHA
                // Fall back to full SHA and take first 7 characters
                // Use @ to suppress errors for non-critical git operations
                // We handle failures explicitly with null checks below
                $fullSha = @shell_exec('cd ' . escapeshellarg(__DIR__) . ' && git rev-parse HEAD 2>/dev/null');
                if ($fullSha) {
                    $fullSha = trim($fullSha);
                    if (strlen($fullSha) >= 7) {
                        return substr($fullSha, 0, 7);
                    }
                }
            }
        }
    }
    
    // Return empty string if SHA cannot be determined
    return '';
}

/**
 * Find similar airports to a given airport code
 * 
 * Uses fuzzy matching to find airports that might be what the user was looking for.
 * Returns airports sorted by similarity (best matches first).
 * 
 * @param string $searchCode The airport code the user searched for
 * @param array $config The airport configuration array
 * @param int $maxResults Maximum number of suggestions to return (default: 5)
 * @return array Array of airport suggestions with 'id', 'icao', 'name', 'address', and 'similarity_score'
 */
function findSimilarAirports(string $searchCode, array $config, int $maxResults = 5): array {
    if (empty($searchCode) || !isset($config['airports']) || !is_array($config['airports'])) {
        return [];
    }
    
    $searchCode = strtoupper(trim($searchCode));
    $suggestions = [];
    
    foreach ($config['airports'] as $airportId => $airport) {
        // Guard against null to prevent PHP 8.1+ deprecation warning
        $icao = (isset($airport['icao']) && !empty($airport['icao'])) ? strtoupper(trim((string)$airport['icao'])) : '';
        if (empty($icao)) {
            continue;
        }
        
        $score = 0;
        
        // Exact match (case-insensitive)
        if ($icao === $searchCode) {
            $score = 1000; // Highest priority
        } 
        // Check if search code is contained in ICAO or vice versa
        elseif (stripos($icao, $searchCode) !== false || stripos($searchCode, $icao) !== false) {
            $score = 500;
        }
        // Calculate Levenshtein distance for similarity
        else {
            $distance = levenshtein($searchCode, $icao);
            $maxLength = max(strlen($searchCode), strlen($icao));
            if ($maxLength > 0) {
                // Convert distance to similarity score (0-100, higher is better)
                $similarity = (1 - ($distance / $maxLength)) * 100;
                $score = max(0, $similarity);
            }
        }
        
        // Bonus points for same length
        if (strlen($icao) === strlen($searchCode)) {
            $score += 10;
        }
        
        // Bonus points if both start with same letter (common for US airports starting with K)
        if (strlen($searchCode) > 0 && strlen($icao) > 0 && 
            strtoupper($searchCode[0]) === strtoupper($icao[0])) {
            $score += 5;
        }
        
        // Only include if there's some similarity
        if ($score > 0) {
            $suggestions[] = [
                'id' => $airportId,
                'icao' => $icao,
                'name' => $airport['name'] ?? 'Unknown Airport',
                'address' => $airport['address'] ?? '',
                'similarity_score' => $score
            ];
        }
    }
    
    // Sort by similarity score (descending)
    usort($suggestions, function($a, $b) {
        return $b['similarity_score'] <=> $a['similarity_score'];
    });
    
    // Return top results
    return array_slice($suggestions, 0, $maxResults);
}

/**
 * Download and cache OurAirports data (ICAO, IATA, FAA codes)
 * 
 * Downloads from OurAirports project (Public Domain)
 * Source: https://davidmegginson.github.io/ourairports-data/airports.csv
 * Caches the data locally to avoid repeated downloads
 * 
 * OurAirports provides comprehensive airport data with 40,000+ airports worldwide,
 * including ICAO, IATA, and FAA (gps_code) identifiers. Data is updated nightly.
 * 
 * @param bool $forceRefresh Force refresh even if cache exists
 * @return array|null Array with 'icao', 'iata', and 'faa' keys, each containing arrays of codes, or null on error
 */
function getOurAirportsData(bool $forceRefresh = false): ?array {
    $cacheFile = __DIR__ . '/../cache/ourairports_data.json';
    $cacheMaxAge = 7 * 24 * 3600; // 7 days (data updated nightly)
    
    // Check cache first (unless forcing refresh)
    if (!$forceRefresh && file_exists($cacheFile)) {
        $cacheAge = time() - filemtime($cacheFile);
        if ($cacheAge < $cacheMaxAge) {
            // Use @ to suppress errors for non-critical cache file operations
            // We handle failures explicitly with null checks and fallbacks below
            $cached = @json_decode(@file_get_contents($cacheFile), true);
            if (is_array($cached) && isset($cached['icao']) && isset($cached['iata']) && isset($cached['faa'])) {
                return $cached;
            }
        }
    }
    
    // Download from OurAirports
    try {
        $csvUrl = 'https://davidmegginson.github.io/ourairports-data/airports.csv';
        $context = stream_context_create([
            'http' => [
                'timeout' => 30, // Larger timeout for big file
                'method' => 'GET',
                'header' => 'User-Agent: AviationWX/1.0',
                'ignore_errors' => true
            ]
        ]);
        
        $csvContent = @file_get_contents($csvUrl, false, $context);
        if ($csvContent === false) {
            aviationwx_log('warning', 'failed to download OurAirports data', [], 'app');
            // Return cached version if available, even if stale
            if (file_exists($cacheFile)) {
                // Use @ to suppress errors for non-critical cache file operations
            // We handle failures explicitly with null checks and fallbacks below
            $cached = @json_decode(@file_get_contents($cacheFile), true);
                if (is_array($cached) && isset($cached['icao'])) {
                    return $cached;
                }
            }
            return null;
        }
        
        // Parse CSV and extract codes
        // CSV columns: id,ident,type,name,latitude_deg,longitude_deg,elevation_ft,continent,iso_country,iso_region,municipality,scheduled_service,icao_code,iata_code,gps_code,local_code,...
        $icaoCodes = [];
        $iataCodes = [];
        $faaCodes = [];
        $lines = explode("\n", $csvContent);
        $headerSkipped = false;
        
        foreach ($lines as $lineNum => $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }
            
            // Skip header line
            if (!$headerSkipped) {
                $headerSkipped = true;
                continue;
            }
            
            // Parse CSV line (handle quoted fields properly)
            // Use null as escape parameter for PHP 8.1+ compatibility
            $fields = str_getcsv($line, ',', '"', null);
            if (count($fields) < 15) {
                continue; // Skip malformed lines
            }
            
            // Column 12: icao_code
            if (isset($fields[12]) && !empty($fields[12])) {
                $icao = strtoupper(trim($fields[12]));
                if (preg_match('/^[A-Z0-9]{3,4}$/', $icao)) {
                    $icaoCodes[$icao] = true;
                }
            }
            
            // Column 13: iata_code
            if (isset($fields[13]) && !empty($fields[13])) {
                $iata = strtoupper(trim($fields[13]));
                if (preg_match('/^[A-Z]{3}$/', $iata)) {
                    $iataCodes[$iata] = true;
                }
            }
            
            // Column 14: gps_code (FAA identifier)
            if (isset($fields[14]) && !empty($fields[14])) {
                $faa = strtoupper(trim($fields[14]));
                if (preg_match('/^[A-Z0-9]{3,4}$/', $faa)) {
                    $faaCodes[$faa] = true;
                }
            }
        }
        
        // Convert to simple arrays
        $result = [
            'icao' => array_keys($icaoCodes),
            'iata' => array_keys($iataCodes),
            'faa' => array_keys($faaCodes)
        ];
        
        // Save to cache
        $cacheDir = dirname($cacheFile);
        if (!is_dir($cacheDir)) {
            // Use @ to suppress errors for non-critical directory creation
            // We handle failures explicitly with error checks below
            @mkdir($cacheDir, 0755, true);
        }
        // Use @ to suppress errors for non-critical cache file writes
        // We handle failures explicitly with error checks below
        @file_put_contents($cacheFile, json_encode($result, JSON_PRETTY_PRINT));
        
        aviationwx_log('info', 'OurAirports data downloaded and cached', [
            'icao_count' => count($result['icao']),
            'iata_count' => count($result['iata']),
            'faa_count' => count($result['faa'])
        ], 'app');
        
        return $result;
        
    } catch (Exception $e) {
        aviationwx_log('error', 'error downloading OurAirports data', ['error' => $e->getMessage()], 'app');
        // Return cached version if available
        if (file_exists($cacheFile)) {
            // Use @ to suppress errors for non-critical cache file operations
            // We handle failures explicitly with null checks and fallbacks below
            $cached = @json_decode(@file_get_contents($cacheFile), true);
            if (is_array($cached) && isset($cached['icao'])) {
                return $cached;
            }
        }
        return null;
    }
}

/**
 * Download and cache ICAO airport codes list from GitHub
 * 
 * Downloads from lxndrblz/Airports repository (CC-BY-SA-4.0 license)
 * Source: https://github.com/lxndrblz/Airports
 * Caches the list locally to avoid repeated downloads
 * 
 * NOTE: This function is deprecated in favor of getOurAirportsData() which provides
 * more comprehensive data. Kept for backward compatibility.
 * 
 * @param bool $forceRefresh Force refresh even if cache exists
 * @return array|null Array of ICAO codes (uppercase) or null on error
 * @deprecated Use getOurAirportsData() instead for better coverage
 */
function getIcaoAirportList(bool $forceRefresh = false): ?array {
    $cacheFile = __DIR__ . '/../cache/icao_airports.json';
    $cacheMaxAge = 30 * 24 * 3600; // 30 days
    
    // Check cache first (unless forcing refresh)
    if (!$forceRefresh && file_exists($cacheFile)) {
        $cacheAge = time() - filemtime($cacheFile);
        if ($cacheAge < $cacheMaxAge) {
            // Use @ to suppress errors for non-critical cache file operations
            // We handle failures explicitly with null checks and fallbacks below
            $cached = @json_decode(@file_get_contents($cacheFile), true);
            if (is_array($cached) && !empty($cached)) {
                return $cached;
            }
        }
    }
    
    // Download from GitHub
    try {
        // GitHub raw URL for the airports CSV
        $csvUrl = 'https://raw.githubusercontent.com/lxndrblz/Airports/main/airports.csv';
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'method' => 'GET',
                'header' => 'User-Agent: AviationWX/1.0',
                'ignore_errors' => true
            ]
        ]);
        
        $csvContent = @file_get_contents($csvUrl, false, $context);
        if ($csvContent === false) {
            aviationwx_log('warning', 'failed to download ICAO list', [], 'app');
            // Return cached version if available, even if stale
            if (file_exists($cacheFile)) {
                // Use @ to suppress errors for non-critical cache file operations
            // We handle failures explicitly with null checks and fallbacks below
            $cached = @json_decode(@file_get_contents($cacheFile), true);
                if (is_array($cached)) {
                    return $cached;
                }
            }
            return null;
        }
        
        // Parse CSV and extract ICAO codes
        $icaoCodes = [];
        $lines = explode("\n", $csvContent);
        $headerSkipped = false;
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }
            
            // Skip header line
            if (!$headerSkipped) {
                $headerSkipped = true;
                continue;
            }
            
            // Parse CSV line (handle quoted fields)
            $fields = str_getcsv($line);
            if (count($fields) >= 2) {
                // ICAO code is typically in the second column (index 1)
                // Format: IATA,ICAO,Name,...
                $icao = strtoupper(trim($fields[1] ?? ''));
                if (!empty($icao) && preg_match('/^[A-Z0-9]{3,4}$/', $icao)) {
                    $icaoCodes[$icao] = true; // Use as associative array for fast lookup
                }
            }
        }
        
        // Convert to simple array of codes
        $icaoCodes = array_keys($icaoCodes);
        
        // Save to cache
        $cacheDir = dirname($cacheFile);
        if (!is_dir($cacheDir)) {
            // Use @ to suppress errors for non-critical directory creation
            // We handle failures explicitly with error checks below
            @mkdir($cacheDir, 0755, true);
        }
        @file_put_contents($cacheFile, json_encode($icaoCodes, JSON_PRETTY_PRINT));
        
        aviationwx_log('info', 'ICAO airport list downloaded and cached', ['count' => count($icaoCodes)], 'app');
        return $icaoCodes;
        
    } catch (Exception $e) {
        aviationwx_log('error', 'error downloading ICAO list', ['error' => $e->getMessage()], 'app');
        // Return cached version if available
        if (file_exists($cacheFile)) {
            // Use @ to suppress errors for non-critical cache file operations
            // We handle failures explicitly with null checks and fallbacks below
            $cached = @json_decode(@file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                return $cached;
            }
        }
        return null;
    }
}

/**
 * Check if an ICAO code is a valid, real airport
 * 
 * Validates against:
 * 1. Our own airport config (fastest, most reliable)
 * 2. Cached OurAirports data (comprehensive, 40,000+ airports)
 * 3. Cached lookup results (APCu)
 * 4. Legacy ICAO airport list from GitHub (fallback)
 * 5. METAR data availability (fallback, only if lists unavailable)
 * 
 * @param string $icaoCode The ICAO code to validate (e.g., "KSPB")
 * @param array|null $config Optional config array (if already loaded, pass it to avoid reloading)
 * @return bool True if the ICAO code is a real airport, false otherwise
 */
function isValidRealAirport(string $icaoCode, ?array $config = null): bool {
    if (empty($icaoCode)) {
        return false;
    }
    
    $icaoCode = strtoupper(trim($icaoCode));
    
    // First, check our own config (fastest and most reliable)
    if ($config === null) {
        $config = loadConfig();
    }
    
    if ($config !== null && isset($config['airports'])) {
        foreach ($config['airports'] as $airportId => $airport) {
            // Guard against null to prevent PHP 8.1+ deprecation warning
            $airportIcao = (isset($airport['icao']) && !empty($airport['icao'])) ? strtoupper(trim((string)$airport['icao'])) : '';
            if ($airportIcao === $icaoCode) {
                return true; // Found in our config - definitely a real airport
            }
        }
    }
    
    // Check APCu cache for previous lookups
    $cacheKey = 'icao_valid_' . $icaoCode;
    if (function_exists('apcu_fetch')) {
        $cached = apcu_fetch($cacheKey);
        if ($cached !== false) {
            return (bool)$cached;
        }
    }
    
    // Check against OurAirports data (preferred, more comprehensive)
    $ourairportsData = getOurAirportsData();
    if ($ourairportsData !== null && isset($ourairportsData['icao']) && is_array($ourairportsData['icao'])) {
        $isValid = in_array($icaoCode, $ourairportsData['icao'], true);
        
        // Cache the result for 30 days (airports don't change often)
        if (function_exists('apcu_store')) {
            apcu_store($cacheKey, $isValid, 2592000); // 30 day cache
        }
        
        return $isValid;
    }
    
    // Fallback to legacy ICAO list (for backward compatibility)
    $icaoList = getIcaoAirportList();
    if ($icaoList !== null && is_array($icaoList)) {
        $isValid = in_array($icaoCode, $icaoList, true);
        
        // Cache the result for 30 days (airports don't change often)
        if (function_exists('apcu_store')) {
            apcu_store($cacheKey, $isValid, 2592000); // 30 day cache
        }
        
        return $isValid;
    }
    
    // Fallback: Try to validate by checking if METAR data exists for this airport
    // Only use this if the ICAO list is unavailable
    $isValid = false;
    
    try {
        // Use Aviation Weather Center's METAR endpoint (free, public)
        // This is a lightweight check - we just verify the airport has weather reporting
        $context = stream_context_create([
            'http' => [
                'timeout' => 3, // 3 second timeout
                'method' => 'GET',
                'header' => 'User-Agent: AviationWX/1.0',
                'ignore_errors' => true
            ]
        ]);
        
        // Try to fetch METAR data for this airport
        // Aviation Weather Center METAR endpoint
        $metarUrl = 'https://aviationweather.gov/api/data/metar?ids=' . urlencode($icaoCode) . '&format=json';
        $response = @file_get_contents($metarUrl, false, $context);
        
        if ($response !== false) {
            $data = json_decode($response, true);
            // If we get valid data back (not empty, not error), it's a real airport
            if (is_array($data) && !empty($data)) {
                $isValid = true;
            }
        }
        
    } catch (Exception $e) {
        // If lookup fails, we can't verify - default to false
        // This is conservative: we only say it's valid if we can confirm it
        $isValid = false;
    }
    
    // Cache the result for 7 days (airports don't change often)
    if (function_exists('apcu_store')) {
        apcu_store($cacheKey, $isValid, 604800); // 7 day cache
    }
    
    return $isValid;
}

/**
 * Check if an ICAO code format is valid (syntactic validation only)
 * 
 * This checks format only, not whether the airport actually exists.
 * Use isValidRealAirport() to check if it's a real airport.
 * 
 * ICAO codes are 3-4 alphanumeric characters, typically uppercase letters.
 * 
 * @param string $icaoCode The ICAO code to validate
 * @return bool True if valid format (3-4 alphanumeric characters), false otherwise
 * @deprecated Use isValidIdentifierFormat($code, 'icao') from airport-identifiers.php instead
 */
function isValidIcaoFormat(string $icaoCode): bool {
    return isValidIdentifierFormat($icaoCode, 'icao');
}

/**
 * Check if an IATA code format is valid (syntactic validation only)
 * 
 * IATA codes are exactly 3 uppercase letters, used primarily for commercial aviation.
 * 
 * @param string $iataCode The IATA code to validate
 * @return bool True if valid format (exactly 3 uppercase letters), false otherwise
 * @deprecated Use isValidIdentifierFormat($code, 'iata') from airport-identifiers.php instead
 */
function isValidIataFormat(string $iataCode): bool {
    return isValidIdentifierFormat($iataCode, 'iata');
}

/**
 * Check if an FAA identifier format is valid (syntactic validation only)
 * 
 * FAA identifiers are 3-4 alphanumeric characters and can start with numbers.
 * Used for US airports, including small private fields.
 * 
 * Examples: "03S", "KSPB", "PDX"
 * 
 * @param string $faaId The FAA identifier to validate
 * @return bool True if valid format (3-4 alphanumeric characters), false otherwise
 * @deprecated Use isValidIdentifierFormat($code, 'faa') from airport-identifiers.php instead
 */
function isValidFaaFormat(string $faaId): bool {
    return isValidIdentifierFormat($faaId, 'faa');
}

/**
 * Check if an IATA code is a valid, real airport code
 * 
 * Validates against OurAirports data (comprehensive, 40,000+ airports).
 * 
 * @param string $iataCode The IATA code to validate (e.g., "PDX")
 * @return bool True if the IATA code is a real airport code, false otherwise
 */
function isValidRealIataCode(string $iataCode): bool {
    if (empty($iataCode)) {
        return false;
    }
    
    $iataCode = strtoupper(trim($iataCode));
    
    // Check format first
    if (!isValidIataFormat($iataCode)) {
        return false;
    }
    
    // Check APCu cache for previous lookups
    $cacheKey = 'iata_valid_' . $iataCode;
    if (function_exists('apcu_fetch')) {
        $cached = apcu_fetch($cacheKey);
        if ($cached !== false) {
            return (bool)$cached;
        }
    }
    
    // Check against OurAirports data
    $ourairportsData = getOurAirportsData();
    if ($ourairportsData !== null && isset($ourairportsData['iata']) && is_array($ourairportsData['iata'])) {
        $isValid = in_array($iataCode, $ourairportsData['iata'], true);
        
        // Cache the result for 30 days
        if (function_exists('apcu_store')) {
            apcu_store($cacheKey, $isValid, 2592000); // 30 day cache
        }
        
        return $isValid;
    }
    
    return false;
}

/**
 * Check if an FAA identifier is a valid, real airport code
 * 
 * Validates against OurAirports data (comprehensive, 40,000+ airports).
 * FAA identifiers are stored in the gps_code field in OurAirports.
 * 
 * @param string $faaCode The FAA identifier to validate (e.g., "03S", "KSPB")
 * @return bool True if the FAA identifier is a real airport code, false otherwise
 */
function isValidRealFaaCode(string $faaCode): bool {
    if (empty($faaCode)) {
        return false;
    }
    
    $faaCode = strtoupper(trim($faaCode));
    
    // Check format first
    if (!isValidFaaFormat($faaCode)) {
        return false;
    }
    
    // Check APCu cache for previous lookups
    $cacheKey = 'faa_valid_' . $faaCode;
    if (function_exists('apcu_fetch')) {
        $cached = apcu_fetch($cacheKey);
        if ($cached !== false) {
            return (bool)$cached;
        }
    }
    
    // Check against OurAirports data
    $ourairportsData = getOurAirportsData();
    if ($ourairportsData !== null && isset($ourairportsData['faa']) && is_array($ourairportsData['faa'])) {
        $isValid = in_array($faaCode, $ourairportsData['faa'], true);
        
        // Cache the result for 30 days
        if (function_exists('apcu_store')) {
            apcu_store($cacheKey, $isValid, 2592000); // 30 day cache
        }
        
        return $isValid;
    }
    
    return false;
}

/**
 * Get ICAO code from IATA code using OurAirports data
 * 
 * NOTE: This function is now defined in airport-identifiers.php (required at top of this file).
 * The implementation has been moved there to centralize airport identifier logic.
 * 
 * @param string $iataCode The IATA code to look up (e.g., "PDX")
 * @return string|null The corresponding ICAO code (e.g., "KPDX") or null if not found
 */
// Function implementation moved to lib/airport-identifiers.php

/**
 * Get ICAO code from FAA identifier using OurAirports data
 * 
 * NOTE: This function is now defined in airport-identifiers.php (required at top of this file).
 * The implementation has been moved there to centralize airport identifier logic.
 * 
 * @param string $faaCode The FAA identifier to look up (e.g., "PDX")
 * @return string|null The corresponding ICAO code (e.g., "KPDX") or null if not found
 */
// Function implementation moved to lib/airport-identifiers.php

/**
 * Check if a custom identifier format is valid (syntactic validation only)
 * 
 * Custom identifiers are used for informal airports without official codes.
 * Format: lowercase alphanumeric with hyphens, 3-50 characters.
 * 
 * Examples: "sandy-river", "private-strip-1"
 * 
 * @param string $customId The custom identifier to validate
 * @return bool True if valid format, false otherwise
 */
function isValidCustomIdentifierFormat(string $customId): bool {
    if (empty($customId)) {
        return false;
    }
    $customId = strtolower(trim($customId));
    // Must start and end with alphanumeric, can contain hyphens in middle
    return preg_match('/^[a-z0-9]([a-z0-9-]{1,48}[a-z0-9])?$/', $customId) === 1 && 
           strlen($customId) >= 3 && 
           strlen($customId) <= 50;
}

/**
 * Comprehensive structure validation for airports.json
 * 
 * Validates the entire structure of airports.json including:
 * - Config section
 * - Required fields
 * - Field types and ranges
 * - Coordinates
 * - Runways
 * - Frequencies
 * - Services
 * - Weather sources
 * - Webcams (including push config)
 * - Links
 * 
 * @param array $config The configuration array to validate
 * @return array Array with 'valid' (bool), 'errors' (array), and optional 'warnings' (array)
 */
function validateAirportsJsonStructure(array $config): array {
    $errors = [];
    $warnings = [];
    
    // Validate global config section if present
    if (isset($config['config'])) {
        if (!is_array($config['config'])) {
            $errors[] = "'config' must be an object/dictionary";
        } else {
            $cfg = $config['config'];
            
            if (isset($cfg['default_timezone']) && !is_string($cfg['default_timezone'])) {
                $errors[] = "config.default_timezone must be a string";
            }
            
            if (isset($cfg['base_domain'])) {
                if (!is_string($cfg['base_domain']) || strpos($cfg['base_domain'], '.') === false) {
                    $errors[] = "config.base_domain must be a valid domain string";
                }
            }
            
            // Validate staleness thresholds (3-tier model)
            $stalenessKeys = [
                'stale_warning_seconds' => MIN_STALE_WARNING_SECONDS,
                'stale_error_seconds' => MIN_STALE_ERROR_SECONDS,
                'stale_failclosed_seconds' => MIN_STALE_FAILCLOSED_SECONDS,
                'metar_stale_warning_seconds' => MIN_STALE_WARNING_SECONDS,
                'metar_stale_error_seconds' => MIN_STALE_ERROR_SECONDS,
                'metar_stale_failclosed_seconds' => MIN_STALE_FAILCLOSED_SECONDS,
                'notam_stale_warning_seconds' => MIN_STALE_WARNING_SECONDS,
                'notam_stale_error_seconds' => MIN_STALE_ERROR_SECONDS,
                'notam_stale_failclosed_seconds' => MIN_STALE_FAILCLOSED_SECONDS,
            ];
            
            foreach ($stalenessKeys as $key => $minValue) {
                if (isset($cfg[$key])) {
                    if (!is_int($cfg[$key]) || $cfg[$key] < $minValue) {
                        $errors[] = "config.{$key} must be an integer >= {$minValue} seconds";
                    }
                }
            }
            
            // Validate staleness ordering (warning < error < failclosed) for each category
            $stalenessCategories = [
                '' => ['stale_warning_seconds', 'stale_error_seconds', 'stale_failclosed_seconds'],
                'metar_' => ['metar_stale_warning_seconds', 'metar_stale_error_seconds', 'metar_stale_failclosed_seconds'],
                'notam_' => ['notam_stale_warning_seconds', 'notam_stale_error_seconds', 'notam_stale_failclosed_seconds'],
            ];
            
            foreach ($stalenessCategories as $prefix => $keys) {
                $warning = $cfg[$keys[0]] ?? null;
                $error = $cfg[$keys[1]] ?? null;
                $failclosed = $cfg[$keys[2]] ?? null;
                
                if ($warning !== null && $error !== null && $warning >= $error) {
                    $errors[] = "config.{$keys[0]} must be less than {$keys[1]}";
                }
                if ($error !== null && $failclosed !== null && $error >= $failclosed) {
                    $errors[] = "config.{$keys[1]} must be less than {$keys[2]}";
                }
            }
            
            if (isset($cfg['webcam_refresh_default'])) {
                if (!is_int($cfg['webcam_refresh_default']) || $cfg['webcam_refresh_default'] < 1) {
                    $errors[] = "config.webcam_refresh_default must be a positive integer";
                }
            }
            
            if (isset($cfg['weather_refresh_default'])) {
                if (!is_int($cfg['weather_refresh_default']) || $cfg['weather_refresh_default'] < 1) {
                    $errors[] = "config.weather_refresh_default must be a positive integer";
                }
            }
            
            if (isset($cfg['metar_refresh_seconds'])) {
                if (!is_int($cfg['metar_refresh_seconds']) || $cfg['metar_refresh_seconds'] < 60) {
                    $errors[] = "config.metar_refresh_seconds must be an integer >= 60 (METAR updates are hourly)";
                }
            }
            
            // Validate format generation flags
            if (isset($cfg['webcam_generate_webp'])) {
                if (!is_bool($cfg['webcam_generate_webp'])) {
                    $errors[] = "config.webcam_generate_webp must be a boolean (true or false)";
                }
            }
            
            // Validate webcam history settings
            // New time-based settings (preferred)
            if (isset($cfg['webcam_history_retention_hours'])) {
                if (!is_numeric($cfg['webcam_history_retention_hours']) || $cfg['webcam_history_retention_hours'] < 0) {
                    $errors[] = "config.webcam_history_retention_hours must be a non-negative number";
                }
            }
            
            if (isset($cfg['webcam_history_default_hours'])) {
                if (!is_int($cfg['webcam_history_default_hours']) || $cfg['webcam_history_default_hours'] < 1) {
                    $errors[] = "config.webcam_history_default_hours must be a positive integer";
                }
            }
            
            if (isset($cfg['webcam_history_preset_hours'])) {
                if (!is_array($cfg['webcam_history_preset_hours'])) {
                    $errors[] = "config.webcam_history_preset_hours must be an array";
                } else {
                    foreach ($cfg['webcam_history_preset_hours'] as $preset) {
                        if (!is_int($preset) || $preset < 1) {
                            $errors[] = "config.webcam_history_preset_hours must contain only positive integers";
                            break;
                        }
                    }
                }
            }
            
            // Legacy setting (deprecated but still supported)
            if (isset($cfg['webcam_history_max_frames'])) {
                if (!is_int($cfg['webcam_history_max_frames']) || $cfg['webcam_history_max_frames'] < 1) {
                    $errors[] = "config.webcam_history_max_frames must be a positive integer";
                }
            }
            
            // Validate webcam_variant_heights (global default)
            if (isset($cfg['webcam_variant_heights'])) {
                if (!is_array($cfg['webcam_variant_heights'])) {
                    $errors[] = "config.webcam_variant_heights must be an array";
                } else {
                    foreach ($cfg['webcam_variant_heights'] as $height) {
                        $height = (int)$height;
                        if ($height < 1 || $height > 5000) {
                            $errors[] = "config.webcam_variant_heights must contain integers between 1 and 5000";
                            break;
                        }
                    }
                }
            }
            
            // Validate default_preferences
            if (isset($cfg['default_preferences'])) {
                $prefErrors = validateDefaultPreferences($cfg['default_preferences'], 'config');
                $errors = array_merge($errors, $prefErrors);
            }
            
            // =========================================================================
            // Client Version Management Settings
            // =========================================================================
            
            // dead_man_switch_days: Days without update before cleanup triggers (0 = disabled)
            if (isset($cfg['dead_man_switch_days'])) {
                if (!is_int($cfg['dead_man_switch_days']) || $cfg['dead_man_switch_days'] < 0) {
                    $errors[] = "config.dead_man_switch_days must be a non-negative integer (0 = disabled)";
                }
            }
            
            // force_cleanup: Emergency flag to force all clients to cleanup
            if (isset($cfg['force_cleanup'])) {
                if (!is_bool($cfg['force_cleanup'])) {
                    $errors[] = "config.force_cleanup must be a boolean (true or false)";
                }
            }
            
            // stuck_client_cleanup: Inject cleanup for clients stuck on old code
            if (isset($cfg['stuck_client_cleanup'])) {
                if (!is_bool($cfg['stuck_client_cleanup'])) {
                    $errors[] = "config.stuck_client_cleanup must be a boolean (true or false)";
                }
            }
            
            // =========================================================================
            // Worker Pool Settings
            // =========================================================================
            
            // weather_worker_pool_size: Number of concurrent weather fetch workers
            if (isset($cfg['weather_worker_pool_size'])) {
                if (!is_int($cfg['weather_worker_pool_size']) || $cfg['weather_worker_pool_size'] < 1) {
                    $errors[] = "config.weather_worker_pool_size must be a positive integer";
                }
            }
            
            // webcam_worker_pool_size: Number of concurrent webcam fetch workers
            if (isset($cfg['webcam_worker_pool_size'])) {
                if (!is_int($cfg['webcam_worker_pool_size']) || $cfg['webcam_worker_pool_size'] < 1) {
                    $errors[] = "config.webcam_worker_pool_size must be a positive integer";
                }
            }
            
            // worker_timeout_seconds: Maximum time for a single worker task
            if (isset($cfg['worker_timeout_seconds'])) {
                if (!is_int($cfg['worker_timeout_seconds']) || $cfg['worker_timeout_seconds'] < 1) {
                    $errors[] = "config.worker_timeout_seconds must be a positive integer";
                }
            }
            
            // =========================================================================
            // Scheduler Settings
            // =========================================================================
            
            // minimum_refresh_seconds: Minimum allowed refresh interval
            if (isset($cfg['minimum_refresh_seconds'])) {
                if (!is_int($cfg['minimum_refresh_seconds']) || $cfg['minimum_refresh_seconds'] < 1) {
                    $errors[] = "config.minimum_refresh_seconds must be a positive integer";
                }
            }
            
            // scheduler_config_reload_seconds: How often scheduler reloads config
            if (isset($cfg['scheduler_config_reload_seconds'])) {
                if (!is_int($cfg['scheduler_config_reload_seconds']) || $cfg['scheduler_config_reload_seconds'] < 1) {
                    $errors[] = "config.scheduler_config_reload_seconds must be a positive integer";
                }
            }
            
            // =========================================================================
            // NOTAM Settings
            // =========================================================================
            
            // notam_refresh_seconds: How often to refresh NOTAM data
            if (isset($cfg['notam_refresh_seconds'])) {
                if (!is_int($cfg['notam_refresh_seconds']) || $cfg['notam_refresh_seconds'] < 60) {
                    $errors[] = "config.notam_refresh_seconds must be an integer >= 60";
                }
            }
            
            // notam_cache_ttl_seconds: How long to cache NOTAM data
            if (isset($cfg['notam_cache_ttl_seconds'])) {
                if (!is_int($cfg['notam_cache_ttl_seconds']) || $cfg['notam_cache_ttl_seconds'] < 60) {
                    $errors[] = "config.notam_cache_ttl_seconds must be an integer >= 60";
                }
            }
            
            // notam_worker_pool_size: Number of concurrent NOTAM fetch workers
            if (isset($cfg['notam_worker_pool_size'])) {
                if (!is_int($cfg['notam_worker_pool_size']) || $cfg['notam_worker_pool_size'] < 1) {
                    $errors[] = "config.notam_worker_pool_size must be a positive integer";
                }
            }
            
            // notam_api_client_id: FAA NOTAM API client ID (string)
            if (isset($cfg['notam_api_client_id'])) {
                if (!is_string($cfg['notam_api_client_id'])) {
                    $errors[] = "config.notam_api_client_id must be a string";
                }
            }
            
            // notam_api_client_secret: FAA NOTAM API client secret (string)
            if (isset($cfg['notam_api_client_secret'])) {
                if (!is_string($cfg['notam_api_client_secret'])) {
                    $errors[] = "config.notam_api_client_secret must be a string";
                }
            }
            
            // notam_api_base_url: FAA NOTAM API base URL
            if (isset($cfg['notam_api_base_url'])) {
                if (!is_string($cfg['notam_api_base_url']) || 
                    (strpos($cfg['notam_api_base_url'], 'http://') !== 0 && 
                     strpos($cfg['notam_api_base_url'], 'https://') !== 0)) {
                    $errors[] = "config.notam_api_base_url must be a valid HTTP/HTTPS URL";
                }
            }
            
            // =========================================================================
            // Public API Settings
            // =========================================================================
            if (isset($cfg['public_api'])) {
                $apiErrors = validatePublicApiConfig($cfg['public_api']);
                $errors = array_merge($errors, $apiErrors);
            }
        }
    }
    
    // Check root structure
    if (!isset($config['airports'])) {
        $errors[] = "Missing 'airports' key at root level";
        return ['valid' => false, 'errors' => $errors, 'warnings' => $warnings];
    }
    
    if (!is_array($config['airports'])) {
        $errors[] = "'airports' must be an object/dictionary";
        return ['valid' => false, 'errors' => $errors, 'warnings' => $warnings];
    }
    
    if (empty($config['airports'])) {
        $warnings[] = "No airports configured";
    }
    
    // Helper functions
    $validateFrequency = function($freq) {
        if (!is_string($freq)) {
            return false;
        }
        $f = floatval($freq);
        return $f >= 118.0 && $f <= 136.975;
    };
    
    $validateUrl = function($url) {
        if (!is_string($url) || empty($url)) {
            return false;
        }
        return strpos($url, 'http://') === 0 || 
               strpos($url, 'https://') === 0 || 
               strpos($url, 'rtsp://') === 0 || 
               strpos($url, 'rtsps://') === 0;
    };
    
    // Helper to validate logo URLs - accepts full URLs or local paths
    // Local paths must start with / and have a valid image extension
    $validateLogoUrl = function($url) use ($validateUrl) {
        if (!is_string($url) || empty($url)) {
            return false;
        }
        // Accept full URLs
        if ($validateUrl($url)) {
            return true;
        }
        // Accept local paths starting with / (e.g., /partner-logos/logo.jpg)
        if (strpos($url, '/') === 0) {
            // Validate it has a reasonable image extension
            $ext = strtolower(pathinfo($url, PATHINFO_EXTENSION));
            return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg']);
        }
        return false;
    };
    
    // Helper to validate METAR station - must be a valid ICAO code
    // Valid formats:
    //   - Standard ICAO: 4 uppercase letters (e.g., KSEA, EGLL, CYYZ)
    //   - US pseudo-ICAO: K + 3 alphanumeric (e.g., K56S, K03S) for small US airports
    $validateMetarStation = function($station, $airportCode) use (&$errors) {
        if (!is_string($station) || empty($station)) {
            return false;
        }
        
        $station = strtoupper(trim($station));
        
        // Standard ICAO: exactly 4 uppercase letters
        $isStandardIcao = preg_match('/^[A-Z]{4}$/', $station) === 1;
        // US pseudo-ICAO: K prefix + 3 alphanumeric (for small US airports like K56S)
        $isUsPseudoIcao = preg_match('/^K[A-Z0-9]{3}$/', $station) === 1;
        
        if (!$isStandardIcao && !$isUsPseudoIcao) {
            $detectedType = detectIdentifierType($station);
            
            if ($detectedType === 'iata') {
                $errors[] = "Airport '{$airportCode}' has invalid metar_station: '{$station}' is an IATA code (3 letters). METAR stations require ICAO codes (e.g., KSEA)";
            } elseif (strlen($station) === 3 && preg_match('/^[A-Z0-9]{3}$/', $station)) {
                $errors[] = "Airport '{$airportCode}' has invalid metar_station: '{$station}' appears to be an FAA identifier. Use the ICAO equivalent (e.g., K{$station} for US airports)";
            } else {
                $errors[] = "Airport '{$airportCode}' has invalid metar_station: '{$station}' is not a valid ICAO code (must be 4 letters, or K + 3 alphanumeric for US)";
            }
            return false;
        }
        
        return true;
    };
    
    // Track identifiers for uniqueness checks (case-insensitive)
    $nameMap = []; // name => [airportIds]
    $icaoMap = []; // icao => [airportIds]
    $iataMap = []; // iata => [airportIds]
    $faaMap = [];  // faa => [airportIds]
    
    // Validate each airport
    foreach ($config['airports'] as $airportCode => $airport) {
        if (!is_array($airport)) {
            $errors[] = "Airport '{$airportCode}' must be an object";
            continue;
        }
        
        // Required fields
        $requiredFields = ['name', 'lat', 'lon', 'access_type', 'tower_status'];
        foreach ($requiredFields as $field) {
            if (!isset($airport[$field])) {
                $errors[] = "Airport '{$airportCode}' missing required field: '{$field}'";
            }
        }
        
        // Validate access_type
        if (isset($airport['access_type'])) {
            $accessType = $airport['access_type'];
            if (!in_array($accessType, ['public', 'private'], true)) {
                $errors[] = "Airport '{$airportCode}' has invalid access_type: '{$accessType}' (must be 'public' or 'private')";
            }
            
            // If private, permission_required must be set
            if ($accessType === 'private' && !isset($airport['permission_required'])) {
                $errors[] = "Airport '{$airportCode}' with access_type 'private' must have permission_required field set";
            }
        }
        
        // Validate permission_required (only relevant for private airports)
        if (isset($airport['permission_required'])) {
            $permissionRequired = $airport['permission_required'];
            if (!is_bool($permissionRequired)) {
                $errors[] = "Airport '{$airportCode}' permission_required must be a boolean (true or false)";
            }
            // Warn if permission_required is set but access_type is not private
            if (isset($airport['access_type']) && $airport['access_type'] !== 'private') {
                $errors[] = "Airport '{$airportCode}' has permission_required set but access_type is not 'private'";
            }
        }
        
        // Validate tower_status
        if (isset($airport['tower_status'])) {
            $towerStatus = $airport['tower_status'];
            if (!in_array($towerStatus, ['towered', 'non_towered'], true)) {
                $errors[] = "Airport '{$airportCode}' has invalid tower_status: '{$towerStatus}' (must be 'towered' or 'non_towered')";
            }
        }
        
        // Track airport name for uniqueness (case-insensitive)
        if (isset($airport['name']) && is_string($airport['name'])) {
            $nameKey = strtolower(trim($airport['name']));
            if (!isset($nameMap[$nameKey])) {
                $nameMap[$nameKey] = [];
            }
            $nameMap[$nameKey][] = $airportCode;
        }
        
        // Identifiers (icao, iata, faa) are optional - if not set, airport ID is used as identifier
        // No validation required - airport can have no identifiers and use airport ID only
        
        // Validate ICAO format (note: real airport validation is separate) and track for uniqueness
        if (isset($airport['icao'])) {
            if (!isValidIcaoFormat($airport['icao'])) {
                $errors[] = "Airport '{$airportCode}' has invalid ICAO code format: '{$airport['icao']}' (must be 3-4 alphanumeric characters)";
            } else {
                // Guard against null to prevent PHP 8.1+ deprecation warning
                $icaoKey = strtoupper(trim((string)$airport['icao']));
                if (!isset($icaoMap[$icaoKey])) {
                    $icaoMap[$icaoKey] = [];
                }
                $icaoMap[$icaoKey][] = $airportCode;
            }
        }
        
        // Validate IATA format (optional) and track for uniqueness
        if (isset($airport['iata']) && $airport['iata'] !== null && is_string($airport['iata'])) {
            if (!isValidIataFormat($airport['iata'])) {
                $errors[] = "Airport '{$airportCode}' has invalid IATA code format: '{$airport['iata']}' (must be exactly 3 uppercase letters)";
            } else {
                // Type check ensures $airport['iata'] is string, preventing PHP 8.1+ deprecation warnings
                $iataKey = strtoupper(trim($airport['iata']));
                if (!isset($iataMap[$iataKey])) {
                    $iataMap[$iataKey] = [];
                }
                $iataMap[$iataKey][] = $airportCode;
            }
        }
        
        // Validate FAA format (optional) and track for uniqueness
        if (isset($airport['faa']) && $airport['faa'] !== null && is_string($airport['faa'])) {
            if (!isValidFaaFormat($airport['faa'])) {
                $errors[] = "Airport '{$airportCode}' has invalid FAA identifier format: '{$airport['faa']}' (must be 3-4 alphanumeric characters)";
            } else {
                // Type check ensures $airport['faa'] is string, preventing PHP 8.1+ deprecation warnings
                $faaKey = strtoupper(trim($airport['faa']));
                if (!isset($faaMap[$faaKey])) {
                    $faaMap[$faaKey] = [];
                }
                $faaMap[$faaKey][] = $airportCode;
            }
        }
        
        // Validate coordinates
        if (isset($airport['lat'])) {
            $lat = $airport['lat'];
            if (!is_numeric($lat) || $lat < -90 || $lat > 90) {
                $errors[] = "Airport '{$airportCode}' has invalid latitude: {$lat}";
            }
        }
        
        if (isset($airport['lon'])) {
            $lon = $airport['lon'];
            if (!is_numeric($lon) || $lon < -180 || $lon > 180) {
                $errors[] = "Airport '{$airportCode}' has invalid longitude: {$lon}";
            }
        }
        
        // Validate numeric fields
        foreach (['elevation_ft'] as $field) {
            if (isset($airport[$field])) {
                $val = $airport[$field];
                if (!is_numeric($val) || $val < 0) {
                    $errors[] = "Airport '{$airportCode}' has invalid {$field}: {$val} (must be non-negative number)";
                }
            }
        }
        
        // Validate refresh intervals (must be integer >= 5 seconds)
        if (isset($airport['weather_refresh_seconds'])) {
            $val = $airport['weather_refresh_seconds'];
            if (!validateRefreshInterval($val, 5)) {
                $errors[] = "Airport '{$airportCode}' has invalid weather_refresh_seconds: {$val} (must be integer >= 5 seconds)";
            }
        }
        
        if (isset($airport['webcam_refresh_seconds'])) {
            $val = $airport['webcam_refresh_seconds'];
            if (!validateRefreshInterval($val, 5)) {
                $errors[] = "Airport '{$airportCode}' has invalid webcam_refresh_seconds: {$val} (must be integer >= 5 seconds)";
            }
        }
        
        // Validate airport-level staleness thresholds (3-tier model)
        $airportStalenessKeys = [
            'stale_warning_seconds' => MIN_STALE_WARNING_SECONDS,
            'stale_error_seconds' => MIN_STALE_ERROR_SECONDS,
            'stale_failclosed_seconds' => MIN_STALE_FAILCLOSED_SECONDS,
        ];
        
        foreach ($airportStalenessKeys as $key => $minValue) {
            if (isset($airport[$key])) {
                if (!is_int($airport[$key]) || $airport[$key] < $minValue) {
                    $errors[] = "Airport '{$airportCode}' has invalid {$key}: must be integer >= {$minValue} seconds";
                }
            }
        }
        
        // Validate staleness ordering at airport level
        $warning = $airport['stale_warning_seconds'] ?? null;
        $error = $airport['stale_error_seconds'] ?? null;
        $failclosed = $airport['stale_failclosed_seconds'] ?? null;
        
        if ($warning !== null && $error !== null && $warning >= $error) {
            $errors[] = "Airport '{$airportCode}': stale_warning_seconds must be less than stale_error_seconds";
        }
        if ($error !== null && $failclosed !== null && $error >= $failclosed) {
            $errors[] = "Airport '{$airportCode}': stale_error_seconds must be less than stale_failclosed_seconds";
        }
        
        // Validate webcam history settings (per-airport overrides)
        // New time-based settings (preferred)
        if (isset($airport['webcam_history_retention_hours'])) {
            if (!is_numeric($airport['webcam_history_retention_hours']) || $airport['webcam_history_retention_hours'] < 0) {
                $errors[] = "Airport '{$airportCode}' has invalid webcam_history_retention_hours: must be a non-negative number";
            }
        }
        
        if (isset($airport['webcam_history_default_hours'])) {
            if (!is_int($airport['webcam_history_default_hours']) || $airport['webcam_history_default_hours'] < 1) {
                $errors[] = "Airport '{$airportCode}' has invalid webcam_history_default_hours: must be a positive integer";
            }
        }
        
        if (isset($airport['webcam_history_preset_hours'])) {
            if (!is_array($airport['webcam_history_preset_hours'])) {
                $errors[] = "Airport '{$airportCode}' has invalid webcam_history_preset_hours: must be an array";
            } else {
                foreach ($airport['webcam_history_preset_hours'] as $preset) {
                    if (!is_int($preset) || $preset < 1) {
                        $errors[] = "Airport '{$airportCode}' has invalid webcam_history_preset_hours: must contain only positive integers";
                        break;
                    }
                }
            }
        }
        
        // Legacy setting (deprecated but still supported)
        if (isset($airport['webcam_history_max_frames'])) {
            if (!is_int($airport['webcam_history_max_frames']) || $airport['webcam_history_max_frames'] < 1) {
                $errors[] = "Airport '{$airportCode}' has invalid webcam_history_max_frames: must be a positive integer";
            }
        }
        
        // Validate webcam_variant_heights (per-airport override)
        if (isset($airport['webcam_variant_heights'])) {
            if (!is_array($airport['webcam_variant_heights'])) {
                $errors[] = "Airport '{$airportCode}' webcam_variant_heights must be an array";
            } else {
                foreach ($airport['webcam_variant_heights'] as $height) {
                    $height = (int)$height;
                    if ($height < 1 || $height > 5000) {
                        $errors[] = "Airport '{$airportCode}' webcam_variant_heights must contain integers between 1 and 5000";
                        break;
                    }
                }
            }
        }
        
        // Validate default_preferences (per-airport overrides)
        if (isset($airport['default_preferences'])) {
            $prefErrors = validateDefaultPreferences($airport['default_preferences'], "Airport '{$airportCode}'");
            $errors = array_merge($errors, $prefErrors);
        }
        
        // Validate timezone
        if (isset($airport['timezone'])) {
            if (!is_string($airport['timezone']) || empty($airport['timezone'])) {
                $errors[] = "Airport '{$airportCode}' has invalid timezone: must be non-empty string";
            }
        }
        
        // Validate URLs
        if (isset($airport['airnav_url']) && !$validateUrl($airport['airnav_url'])) {
            $errors[] = "Airport '{$airportCode}' has invalid airnav_url: must be a valid URL";
        }
        if (isset($airport['skyvector_url']) && !$validateUrl($airport['skyvector_url'])) {
            $errors[] = "Airport '{$airportCode}' has invalid skyvector_url: must be a valid URL";
        }
        if (isset($airport['aopa_url']) && !$validateUrl($airport['aopa_url'])) {
            $errors[] = "Airport '{$airportCode}' has invalid aopa_url: must be a valid URL";
        }
        if (isset($airport['faa_weather_url']) && !$validateUrl($airport['faa_weather_url'])) {
            $errors[] = "Airport '{$airportCode}' has invalid faa_weather_url: must be a valid URL";
        }
        if (isset($airport['foreflight_url'])) {
            // ForeFlight uses deeplink scheme (foreflightmobile://maps/search?q=), validate separately
            // Accepts ICAO, IATA, or FAA codes
            $foreflightUrl = trim($airport['foreflight_url']);
            if (!preg_match('/^foreflightmobile:\/\/maps\/search\?q=[A-Z0-9]+$/', $foreflightUrl)) {
                $errors[] = "Airport '{$airportCode}' has invalid foreflight_url: must be in format 'foreflightmobile://maps/search?q={identifier}'";
            }
        }
        
        // Validate METAR station (must be valid ICAO code)
        if (isset($airport['metar_station'])) {
            $validateMetarStation($airport['metar_station'], $airportCode);
        }
        
        // Validate runways
        if (isset($airport['runways'])) {
            if (!is_array($airport['runways'])) {
                $errors[] = "Airport '{$airportCode}' runways must be an array";
            } else {
                foreach ($airport['runways'] as $idx => $runway) {
                    if (!is_array($runway)) {
                        $errors[] = "Airport '{$airportCode}' runway[{$idx}] must be an object";
                    } else {
                        if (!isset($runway['name']) || !is_string($runway['name'])) {
                            $errors[] = "Airport '{$airportCode}' runway[{$idx}] missing or invalid 'name' field";
                        }
                        if (isset($runway['heading_1'])) {
                            $h1 = $runway['heading_1'];
                            if (!is_int($h1) || $h1 < 0 || $h1 > 360) {
                                $errors[] = "Airport '{$airportCode}' runway[{$idx}] has invalid heading_1: {$h1} (must be 0-360)";
                            }
                        }
                        if (isset($runway['heading_2'])) {
                            $h2 = $runway['heading_2'];
                            if (!is_int($h2) || $h2 < 0 || $h2 > 360) {
                                $errors[] = "Airport '{$airportCode}' runway[{$idx}] has invalid heading_2: {$h2} (must be 0-360)";
                            }
                        }
                    }
                }
            }
        }
        
        // Validate frequencies
        if (isset($airport['frequencies'])) {
            if (!is_array($airport['frequencies'])) {
                $errors[] = "Airport '{$airportCode}' frequencies must be an object";
            } else {
                foreach ($airport['frequencies'] as $freqName => $freqValue) {
                    if (!$validateFrequency($freqValue)) {
                        $errors[] = "Airport '{$airportCode}' frequency '{$freqName}' has invalid value: '{$freqValue}' (must be 118.0-136.975)";
                    }
                }
            }
        }
        
        // Validate services
        if (isset($airport['services'])) {
            if (!is_array($airport['services'])) {
                $errors[] = "Airport '{$airportCode}' services must be an object";
            } else {
                // Define allowed service fields (strict validation)
                $allowedServiceFields = ['fuel', 'repairs_available'];
                
                // Check for unknown fields and reject them
                foreach ($airport['services'] as $key => $value) {
                    if (!in_array($key, $allowedServiceFields)) {
                        $errors[] = "Airport '{$airportCode}' services has unknown field '{$key}'. Allowed fields: " . implode(', ', $allowedServiceFields);
                    }
                }
                
                // Validate known fields
                if (isset($airport['services']['fuel'])) {
                    if (!is_string($airport['services']['fuel'])) {
                        $errors[] = "Airport '{$airportCode}' service 'fuel' must be a string, got: " . gettype($airport['services']['fuel']);
                    }
                }
                
                if (isset($airport['services']['repairs_available'])) {
                    if (!is_bool($airport['services']['repairs_available'])) {
                        $errors[] = "Airport '{$airportCode}' service 'repairs_available' must be a boolean, got: " . gettype($airport['services']['repairs_available']);
                    }
                }
            }
        }
        
        // Validate weather source
        if (isset($airport['weather_source'])) {
            $ws = $airport['weather_source'];
            if (!is_array($ws)) {
                $errors[] = "Airport '{$airportCode}' weather_source must be an object";
            } else {
                if (!isset($ws['type'])) {
                    $errors[] = "Airport '{$airportCode}' weather_source missing 'type' field";
                } else {
                    $validTypes = ['tempest', 'ambient', 'weatherlink_v2', 'weatherlink_v1', 'pwsweather', 'synopticdata', 'metar'];
                    if (!in_array($ws['type'], $validTypes)) {
                        $errors[] = "Airport '{$airportCode}' weather_source has invalid type: '{$ws['type']}' (must be one of: " . implode(', ', $validTypes) . ")";
                    } else {
                        $wsType = $ws['type'];
                        if ($wsType === 'tempest') {
                            if (!isset($ws['station_id'])) {
                                $errors[] = "Airport '{$airportCode}' weather_source (tempest) missing 'station_id'";
                            }
                            if (!isset($ws['api_key'])) {
                                $errors[] = "Airport '{$airportCode}' weather_source (tempest) missing 'api_key'";
                            }
                        } elseif ($wsType === 'ambient') {
                            if (!isset($ws['api_key'])) {
                                $errors[] = "Airport '{$airportCode}' weather_source (ambient) missing 'api_key'";
                            }
                            if (!isset($ws['application_key'])) {
                                $errors[] = "Airport '{$airportCode}' weather_source (ambient) missing 'application_key'";
                            }
                        } elseif ($wsType === 'weatherlink_v2') {
                            // WeatherLink v2 API (for newer devices: WeatherLink Live, Console, EnviroMonitor)
                            if (!isset($ws['api_key'])) {
                                $errors[] = "Airport '{$airportCode}' weather_source (weatherlink_v2) missing 'api_key'";
                            }
                            if (!isset($ws['api_secret'])) {
                                $errors[] = "Airport '{$airportCode}' weather_source (weatherlink_v2) missing 'api_secret'";
                            }
                            if (!isset($ws['station_id'])) {
                                $errors[] = "Airport '{$airportCode}' weather_source (weatherlink_v2) missing 'station_id'";
                            }
                        } elseif ($wsType === 'weatherlink_v1') {
                            // WeatherLink v1 API (for legacy devices: Vantage Connect, WeatherLinkIP)
                            if (!isset($ws['device_id'])) {
                                $errors[] = "Airport '{$airportCode}' weather_source (weatherlink_v1) missing 'device_id'";
                            }
                            if (!isset($ws['api_token'])) {
                                $errors[] = "Airport '{$airportCode}' weather_source (weatherlink_v1) missing 'api_token'";
                            }
                        } elseif ($wsType === 'pwsweather') {
                            if (!isset($ws['station_id'])) {
                                $errors[] = "Airport '{$airportCode}' weather_source (pwsweather) missing 'station_id'";
                            }
                            if (!isset($ws['client_id'])) {
                                $errors[] = "Airport '{$airportCode}' weather_source (pwsweather) missing 'client_id'";
                            }
                            if (!isset($ws['client_secret'])) {
                                $errors[] = "Airport '{$airportCode}' weather_source (pwsweather) missing 'client_secret'";
                            }
                        } elseif ($wsType === 'synopticdata') {
                            if (!isset($ws['station_id'])) {
                                $errors[] = "Airport '{$airportCode}' weather_source (synopticdata) missing 'station_id'";
                            }
                            if (!isset($ws['api_token'])) {
                                $errors[] = "Airport '{$airportCode}' weather_source (synopticdata) missing 'api_token'";
                            }
                        }
                    }
                }
            }
        }
        
        // Validate backup weather source (same structure as primary)
        if (isset($airport['weather_source_backup'])) {
            $wsBackup = $airport['weather_source_backup'];
            if (!is_array($wsBackup)) {
                $errors[] = "Airport '{$airportCode}' weather_source_backup must be an object";
            } else {
                if (!isset($wsBackup['type'])) {
                    $errors[] = "Airport '{$airportCode}' weather_source_backup missing 'type' field";
                } else {
                    $validTypes = ['tempest', 'ambient', 'weatherlink_v2', 'weatherlink_v1', 'pwsweather', 'synopticdata', 'metar'];
                    if (!in_array($wsBackup['type'], $validTypes)) {
                        $errors[] = "Airport '{$airportCode}' weather_source_backup has invalid type: '{$wsBackup['type']}' (must be one of: " . implode(', ', $validTypes) . ")";
                    } else {
                        $wsBackupType = $wsBackup['type'];
                        if ($wsBackupType === 'tempest') {
                            if (!isset($wsBackup['station_id'])) {
                                $errors[] = "Airport '{$airportCode}' weather_source_backup (tempest) missing 'station_id'";
                            }
                            if (!isset($wsBackup['api_key'])) {
                                $errors[] = "Airport '{$airportCode}' weather_source_backup (tempest) missing 'api_key'";
                            }
                        } elseif ($wsBackupType === 'ambient') {
                            if (!isset($wsBackup['api_key'])) {
                                $errors[] = "Airport '{$airportCode}' weather_source_backup (ambient) missing 'api_key'";
                            }
                            if (!isset($wsBackup['application_key'])) {
                                $errors[] = "Airport '{$airportCode}' weather_source_backup (ambient) missing 'application_key'";
                            }
                        } elseif ($wsBackupType === 'weatherlink_v2') {
                            // WeatherLink v2 API (for newer devices)
                            if (!isset($wsBackup['api_key'])) {
                                $errors[] = "Airport '{$airportCode}' weather_source_backup (weatherlink_v2) missing 'api_key'";
                            }
                            if (!isset($wsBackup['api_secret'])) {
                                $errors[] = "Airport '{$airportCode}' weather_source_backup (weatherlink_v2) missing 'api_secret'";
                            }
                            if (!isset($wsBackup['station_id'])) {
                                $errors[] = "Airport '{$airportCode}' weather_source_backup (weatherlink_v2) missing 'station_id'";
                            }
                        } elseif ($wsBackupType === 'weatherlink_v1') {
                            // WeatherLink v1 API (for legacy devices)
                            if (!isset($wsBackup['device_id'])) {
                                $errors[] = "Airport '{$airportCode}' weather_source_backup (weatherlink_v1) missing 'device_id'";
                            }
                            if (!isset($wsBackup['api_token'])) {
                                $errors[] = "Airport '{$airportCode}' weather_source_backup (weatherlink_v1) missing 'api_token'";
                            }
                        } elseif ($wsBackupType === 'pwsweather') {
                            if (!isset($wsBackup['station_id'])) {
                                $errors[] = "Airport '{$airportCode}' weather_source_backup (pwsweather) missing 'station_id'";
                            }
                            if (!isset($wsBackup['client_id'])) {
                                $errors[] = "Airport '{$airportCode}' weather_source_backup (pwsweather) missing 'client_id'";
                            }
                            if (!isset($wsBackup['client_secret'])) {
                                $errors[] = "Airport '{$airportCode}' weather_source_backup (pwsweather) missing 'client_secret'";
                            }
                        } elseif ($wsBackupType === 'synopticdata') {
                            if (!isset($wsBackup['station_id'])) {
                                $errors[] = "Airport '{$airportCode}' weather_source_backup (synopticdata) missing 'station_id'";
                            }
                            if (!isset($wsBackup['api_token'])) {
                                $errors[] = "Airport '{$airportCode}' weather_source_backup (synopticdata) missing 'api_token'";
                            }
                        }
                    }
                }
            }
        }
        
        // Validate webcams
        if (isset($airport['webcams'])) {
            if (!is_array($airport['webcams'])) {
                $errors[] = "Airport '{$airportCode}' webcams must be an array";
            } else {
                foreach ($airport['webcams'] as $idx => $webcam) {
                    if (!is_array($webcam)) {
                        $errors[] = "Airport '{$airportCode}' webcam[{$idx}] must be an object";
                    } else {
                        $webcamType = $webcam['type'] ?? 'http';
                        
                        if ($webcamType === 'push') {
                            // Define allowed fields for push cameras
                            $allowedPushWebcamFields = ['name', 'type', 'push_config', 'refresh_seconds', 'variant_heights'];
                            
                            // Check for unknown fields in push webcam
                            foreach ($webcam as $key => $value) {
                                if (!in_array($key, $allowedPushWebcamFields)) {
                                    $errors[] = "Airport '{$airportCode}' webcam[{$idx}] (push type) has unknown field '{$key}'. Allowed fields: " . implode(', ', $allowedPushWebcamFields);
                                }
                            }
                            
                            if (!isset($webcam['push_config'])) {
                                $errors[] = "Airport '{$airportCode}' webcam[{$idx}] (push type) missing 'push_config'";
                            } else {
                                $pushConfig = $webcam['push_config'];
                                if (!is_array($pushConfig)) {
                                    $errors[] = "Airport '{$airportCode}' webcam[{$idx}] push_config must be an object";
                                } else {
                                    $requiredPushFields = ['protocol', 'username', 'password', 'max_file_size_mb', 'allowed_extensions'];
                                    foreach ($requiredPushFields as $field) {
                                        if (!isset($pushConfig[$field])) {
                                            $errors[] = "Airport '{$airportCode}' webcam[{$idx}] push_config missing '{$field}'";
                                        }
                                    }
                                    if (isset($pushConfig['port'])) {
                                        $port = $pushConfig['port'];
                                        if (!is_int($port) || $port < 1 || $port > 65535) {
                                            $errors[] = "Airport '{$airportCode}' webcam[{$idx}] push_config.port must be 1-65535, got: {$port}";
                                        }
                                    }
                                    if (isset($pushConfig['max_file_size_mb'])) {
                                        $size = $pushConfig['max_file_size_mb'];
                                        if (!is_int($size) || $size < 1) {
                                            $errors[] = "Airport '{$airportCode}' webcam[{$idx}] push_config.max_file_size_mb must be positive integer";
                                        }
                                    }
                                    if (isset($pushConfig['allowed_extensions']) && !is_array($pushConfig['allowed_extensions'])) {
                                        $errors[] = "Airport '{$airportCode}' webcam[{$idx}] push_config.allowed_extensions must be an array";
                                    }
                                }
                            }
                        } elseif ($webcamType === 'rtsp') {
                            // Define allowed fields for RTSP cameras
                            $allowedRtspWebcamFields = ['name', 'type', 'url', 'rtsp_transport', 'refresh_seconds', 'rtsp_fetch_timeout', 'rtsp_max_runtime', 'transcode_timeout', 'variant_heights'];
                            
                            // Check for unknown fields in RTSP webcam
                            foreach ($webcam as $key => $value) {
                                if (!in_array($key, $allowedRtspWebcamFields)) {
                                    $errors[] = "Airport '{$airportCode}' webcam[{$idx}] (rtsp type) has unknown field '{$key}'. Allowed fields: " . implode(', ', $allowedRtspWebcamFields);
                                }
                            }
                            
                            if (!isset($webcam['rtsp_transport'])) {
                                $errors[] = "Airport '{$airportCode}' webcam[{$idx}] (rtsp type) missing 'rtsp_transport' field";
                            }
                            if (!isset($webcam['url'])) {
                                $errors[] = "Airport '{$airportCode}' webcam[{$idx}] (rtsp type) missing 'url' field";
                            }
                        } else {
                            // Define allowed fields for pull cameras (http/mjpeg/static_jpeg/static_png)
                            $allowedPullWebcamFields = ['name', 'type', 'url', 'refresh_seconds', 'variant_heights'];
                            
                            // Check for unknown fields in pull webcam
                            foreach ($webcam as $key => $value) {
                                if (!in_array($key, $allowedPullWebcamFields)) {
                                    $errors[] = "Airport '{$airportCode}' webcam[{$idx}] (pull type) has unknown field '{$key}'. Allowed fields: " . implode(', ', $allowedPullWebcamFields);
                                }
                            }
                            
                            if (!isset($webcam['url'])) {
                                $errors[] = "Airport '{$airportCode}' webcam[{$idx}] missing required 'url' field";
                            }
                        }
                        
                        if (isset($webcam['url']) && !$validateUrl($webcam['url'])) {
                            $errors[] = "Airport '{$airportCode}' webcam[{$idx}] has invalid url: must be a valid URL";
                        }
                        
                        if (isset($webcam['refresh_seconds'])) {
                            $rs = $webcam['refresh_seconds'];
                            if (!is_int($rs) || $rs < 1) {
                                $errors[] = "Airport '{$airportCode}' webcam[{$idx}] refresh_seconds must be positive integer";
                            }
                        }
                        
                        // Validate variant_heights
                        if (isset($webcam['variant_heights'])) {
                            if (!is_array($webcam['variant_heights'])) {
                                $errors[] = "Airport '{$airportCode}' webcam[{$idx}] variant_heights must be an array";
                            } else {
                                foreach ($webcam['variant_heights'] as $height) {
                                    $height = (int)$height;
                                    if ($height < 1 || $height > 5000) {
                                        $errors[] = "Airport '{$airportCode}' webcam[{$idx}] variant_heights must contain integers between 1 and 5000";
                                        break;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        
        // Validate partners
        if (isset($airport['partners'])) {
            if (!is_array($airport['partners'])) {
                $errors[] = "Airport '{$airportCode}' partners must be an array";
            } else {
                foreach ($airport['partners'] as $idx => $partner) {
                    if (!is_array($partner)) {
                        $errors[] = "Airport '{$airportCode}' partner[{$idx}] must be an object";
                    } else {
                        if (!isset($partner['name']) || !is_string($partner['name']) || empty($partner['name'])) {
                            $errors[] = "Airport '{$airportCode}' partner[{$idx}] missing or invalid 'name' field";
                        }
                        if (!isset($partner['url'])) {
                            $errors[] = "Airport '{$airportCode}' partner[{$idx}] missing 'url' field";
                        } elseif (!$validateUrl($partner['url'])) {
                            $errors[] = "Airport '{$airportCode}' partner[{$idx}] has invalid url: must be a valid URL";
                        }
                        if (isset($partner['logo']) && !$validateLogoUrl($partner['logo'])) {
                            $errors[] = "Airport '{$airportCode}' partner[{$idx}] has invalid logo: must be a valid URL or local path";
                        }
                        if (isset($partner['description']) && !is_string($partner['description'])) {
                            $errors[] = "Airport '{$airportCode}' partner[{$idx}] description must be a string";
                        }
                    }
                }
            }
        }
        
        // Validate links
        if (isset($airport['links'])) {
            if (!is_array($airport['links'])) {
                $errors[] = "Airport '{$airportCode}' links must be an array";
            } else {
                foreach ($airport['links'] as $idx => $link) {
                    if (!is_array($link)) {
                        $errors[] = "Airport '{$airportCode}' link[{$idx}] must be an object";
                    } else {
                        if (!isset($link['label']) || !is_string($link['label']) || empty($link['label'])) {
                            $errors[] = "Airport '{$airportCode}' link[{$idx}] missing or invalid 'label' field";
                        }
                        if (!isset($link['url'])) {
                            $errors[] = "Airport '{$airportCode}' link[{$idx}] missing 'url' field";
                        } elseif (!$validateUrl($link['url'])) {
                            $errors[] = "Airport '{$airportCode}' link[{$idx}] has invalid url: must be a valid URL";
                        }
                    }
                }
            }
        }
    }
    
    // Check uniqueness of airport names
    foreach ($nameMap as $nameKey => $airportIds) {
        if (count($airportIds) > 1) {
            $errors[] = "Duplicate airport name found: '" . $nameKey . "' used by airports: " . implode(', ', $airportIds);
        }
    }
    
    // Check uniqueness of ICAO codes
    foreach ($icaoMap as $icaoKey => $airportIds) {
        if (count($airportIds) > 1) {
            $errors[] = "Duplicate ICAO code found: '" . $icaoKey . "' used by airports: " . implode(', ', $airportIds);
        }
    }
    
    // Check uniqueness of IATA codes
    foreach ($iataMap as $iataKey => $airportIds) {
        if (count($airportIds) > 1) {
            $errors[] = "Duplicate IATA code found: '" . $iataKey . "' used by airports: " . implode(', ', $airportIds);
        }
    }
    
    // Check uniqueness of FAA identifiers
    foreach ($faaMap as $faaKey => $airportIds) {
        if (count($airportIds) > 1) {
            $errors[] = "Duplicate FAA identifier found: '" . $faaKey . "' used by airports: " . implode(', ', $airportIds);
        }
    }
    
    return [
        'valid' => empty($errors),
        'errors' => $errors,
        'warnings' => $warnings
    ];
}

/**
 * Validate airport identifiers (ICAO, IATA, FAA) in airports.json against real airport lists
 * 
 * Checks all identifier codes in the config against OurAirports data (comprehensive, 40,000+ airports).
 * 
 * @param array|null $config Optional config array (if already loaded)
 * @return array Array with 'valid' (bool), 'errors' (array), and optional 'warnings' (array)
 */
function validateAirportsIcaoCodes(?array $config = null): array {
    $errors = [];
    $warnings = [];
    
    if ($config === null) {
        $config = loadConfig();
    }
    
    if ($config === null) {
        return ['valid' => false, 'errors' => ['Could not load configuration']];
    }
    
    if (!isset($config['airports']) || !is_array($config['airports'])) {
        return ['valid' => false, 'errors' => ['No airports found in configuration']];
    }
    
    // Get OurAirports data (will download if needed)
    // If unavailable, we can still do format validation but skip cross-reference checks
    $ourairportsData = getOurAirportsData();
    $hasOurAirportsData = ($ourairportsData !== null);
    
    if (!$hasOurAirportsData) {
        $warnings[] = 'Could not download OurAirports data - skipping cross-reference validation';
    }
    
    // Build lookup arrays for fast access (only if data available)
    $icaoLookup = [];
    $iataLookup = [];
    $faaLookup = [];
    
    if ($hasOurAirportsData) {
        if (isset($ourairportsData['icao']) && is_array($ourairportsData['icao'])) {
            foreach ($ourairportsData['icao'] as $code) {
                $normalized = strtoupper(trim($code));
                if (!empty($normalized)) {
                    $icaoLookup[$normalized] = true;
                }
            }
        }
        
        if (isset($ourairportsData['iata']) && is_array($ourairportsData['iata'])) {
            foreach ($ourairportsData['iata'] as $code) {
                $normalized = strtoupper(trim($code));
                if (!empty($normalized)) {
                    $iataLookup[$normalized] = true;
                }
            }
        }
        
        if (isset($ourairportsData['faa']) && is_array($ourairportsData['faa'])) {
            foreach ($ourairportsData['faa'] as $code) {
                $normalized = strtoupper(trim($code));
                if (!empty($normalized)) {
                    $faaLookup[$normalized] = true;
                }
            }
        }
    }
    
    // Validate each airport's identifiers
    foreach ($config['airports'] as $airportId => $airport) {
        // Validate ICAO code if present
        if (isset($airport['icao']) && !empty($airport['icao'])) {
            $icao = strtoupper(trim((string)$airport['icao']));
            
            // Check format (always validate format)
            if (!isValidIcaoFormat($icao)) {
                $errors[] = "Airport '{$airportId}' has invalid ICAO format: '{$icao}'";
            } elseif ($hasOurAirportsData && !isset($icaoLookup[$icao])) {
                // Missing from OurAirports - warning only (data may be incomplete for very new/rare airports)
                $warnings[] = "Airport '{$airportId}' has ICAO code '{$icao}' which is not found in OurAirports data";
            }
        }
        
        // Validate IATA code if present
        if (isset($airport['iata']) && !empty($airport['iata']) && $airport['iata'] !== null) {
            $iata = strtoupper(trim((string)$airport['iata']));
            
            // Check format (always validate format)
            if (!isValidIataFormat($iata)) {
                $errors[] = "Airport '{$airportId}' has invalid IATA format: '{$iata}'";
            } elseif ($hasOurAirportsData && !isset($iataLookup[$iata])) {
                // Missing from OurAirports - warning only
                $warnings[] = "Airport '{$airportId}' has IATA code '{$iata}' which is not found in OurAirports data";
            }
        }
        
        // Validate FAA identifier if present
        if (isset($airport['faa']) && !empty($airport['faa']) && $airport['faa'] !== null) {
            $faa = strtoupper(trim((string)$airport['faa']));
            
            // Check format (always validate format)
            if (!isValidFaaFormat($faa)) {
                $errors[] = "Airport '{$airportId}' has invalid FAA identifier format: '{$faa}'";
            } elseif ($hasOurAirportsData && !isset($faaLookup[$faa])) {
                // Missing from OurAirports - warning only
                $warnings[] = "Airport '{$airportId}' has FAA identifier '{$faa}' which is not found in OurAirports data";
            }
        }
    }
    
    return [
        'valid' => empty($errors),
        'errors' => $errors,
        'warnings' => $warnings
    ];
}
