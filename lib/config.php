<?php
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/constants.php';

/**
 * Shared Configuration Utilities
 * Provides centralized config loading, caching, and validation
 */

/**
 * Validate and sanitize airport ID
 * 
 * Airport IDs must be 3-4 lowercase alphanumeric characters (ICAO format).
 * Validates format before trimming to prevent "k spb" from becoming "kspb".
 * 
 * @param string $id Airport ID to validate
 * @return bool True if valid ICAO format, false otherwise
 */
function validateAirportId(string $id): bool {
    if (empty($id)) {
        return false;
    }
    // Check for whitespace BEFORE trimming (reject IDs with whitespace)
    // This prevents "k spb" from becoming "kspb" after trim
    if (preg_match('/\s/', $id)) {
        return false;
    }
    return preg_match('/^[a-z0-9]{3,4}$/', strtolower(trim($id))) === 1;
}

/**
 * Check if we're in a production environment
 * @return bool True if production, false otherwise
 */
function isProduction() {
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
 * Get global configuration value with fallback to default
 * @param string $key Configuration key
 * @param mixed $default Default value if not set
 * @return mixed Configuration value or default
 */
function getGlobalConfig($key, $default = null) {
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
function getDefaultTimezone() {
    return getGlobalConfig('default_timezone', 'UTC');
}

/**
 * Get base domain from global config
 * @return string Base domain (default: aviationwx.org)
 */
function getBaseDomain() {
    return getGlobalConfig('base_domain', 'aviationwx.org');
}

/**
 * Get maximum stale hours from global config
 * @return int Maximum stale hours (default: 3)
 */
function getMaxStaleHours() {
    return (int)getGlobalConfig('max_stale_hours', 3);
}

/**
 * Get default webcam refresh interval from global config
 * @return int Default webcam refresh in seconds (default: 60)
 */
function getDefaultWebcamRefresh() {
    return (int)getGlobalConfig('webcam_refresh_default', 60);
}

/**
 * Get default weather refresh interval from global config
 * @return int Default weather refresh in seconds (default: 60)
 */
function getDefaultWeatherRefresh() {
    return (int)getGlobalConfig('weather_refresh_default', 60);
}

/**
 * Get weather worker pool size from global config
 * @return int Number of concurrent workers (default: 5)
 */
function getWeatherWorkerPoolSize() {
    return (int)getGlobalConfig('weather_worker_pool_size', 5);
}

/**
 * Get webcam worker pool size from global config
 * @return int Number of concurrent workers (default: 5)
 */
function getWebcamWorkerPoolSize() {
    return (int)getGlobalConfig('webcam_worker_pool_size', 5);
}

/**
 * Get worker timeout from global config
 * @return int Timeout in seconds (default: 45)
 */
function getWorkerTimeout() {
    return (int)getGlobalConfig('worker_timeout_seconds', 45);
}

/**
 * Load airport configuration with caching
 * Uses APCu cache if available, falls back to static variable for request lifetime
 * Automatically invalidates cache when file modification time changes
 * 
 * SECURITY: Prevents test data (airports.json.test) from being used in production
 */
function loadConfig($useCache = true) {
    static $cachedConfig = null;
    
    // Get config file path
    $envConfigPath = getenv('CONFIG_PATH');
    // Check if CONFIG_PATH is set and is a file (not a directory)
    if ($envConfigPath && file_exists($envConfigPath) && is_file($envConfigPath)) {
        $configFile = $envConfigPath;
    } else {
        // Fall back to default path
        $configFile = __DIR__ . '/../config/airports.json';
        // If default path doesn't exist, try /var/www/html/airports.json (production mount point)
        if (!file_exists($configFile) || is_dir($configFile)) {
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
    if (!file_exists($configFile)) {
        aviationwx_log('error', 'config file not found', ['path' => $configFile], 'app');
        return null;
    }
    if (is_dir($configFile)) {
        aviationwx_log('error', 'config path is directory', ['path' => $configFile], 'app');
        return null;
    }
    
    // Get file modification time for cache invalidation
    $fileMtime = filemtime($configFile);
    $cacheKey = 'aviationwx_config';
    $cacheTimeKey = 'aviationwx_config_mtime';
    
    // Try APCu cache first (if available)
    if ($useCache && function_exists('apcu_fetch')) {
        // Check if cached file mtime matches current file mtime
        $cachedMtime = apcu_fetch($cacheTimeKey);
        if ($cachedMtime !== false && $cachedMtime === $fileMtime) {
            // File hasn't changed, return cached config
            $cached = apcu_fetch($cacheKey);
            if ($cached !== false) {
                // Also update static cache
                $cachedConfig = $cached;
                return $cached;
            }
        } else {
            // File changed or cache expired, clear old cache
            apcu_delete($cacheKey);
            apcu_delete($cacheTimeKey);
        }
    }
    
    // Use static cache for request lifetime (but check file hasn't changed)
    // We also store file mtime in a static variable to detect changes
    static $cachedConfigMtime = null;
    
    if ($cachedConfig !== null && $cachedConfigMtime === $fileMtime) {
        // File hasn't changed in this request, return cached config
        return $cachedConfig;
    }
    
    // File changed or no cache, clear static cache
    $cachedConfig = null;
    $cachedConfigMtime = null;
    
    // Read and validate JSON
    $jsonContent = @file_get_contents($configFile);
    if ($jsonContent === false) {
        aviationwx_log('error', 'config read failed', ['path' => $configFile], 'app');
        return null;
    }
    
    $config = json_decode($jsonContent, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($config)) {
        aviationwx_log('error', 'config invalid json', ['error' => json_last_error_msg(), 'path' => $configFile], 'app');
        return null;
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
                $errors[] = "Airport key '{$aid}' is invalid (3-4 lowercase alphanumerics)";
            }
            
            // Validate VPN configuration if present
            if (isset($ap['vpn']) && is_array($ap['vpn'])) {
                $vpn = $ap['vpn'];
                if (isset($vpn['enabled']) && $vpn['enabled']) {
                    if (empty($vpn['connection_name'])) {
                        $errors[] = "Airport '{$aid}' VPN missing connection_name";
                    }
                    if (empty($vpn['remote_subnet'])) {
                        $errors[] = "Airport '{$aid}' VPN missing remote_subnet";
                    } elseif (!preg_match('/^\d+\.\d+\.\d+\.\d+\/\d+$/', $vpn['remote_subnet'])) {
                        $errors[] = "Airport '{$aid}' VPN remote_subnet invalid format (expected CIDR)";
                    }
                    if (empty($vpn['psk'])) {
                        $errors[] = "Airport '{$aid}' VPN missing psk";
                    }
                    if (isset($vpn['type']) && !in_array($vpn['type'], ['ipsec', 'wireguard', 'openvpn'])) {
                        $errors[] = "Airport '{$aid}' VPN has invalid type '{$vpn['type']}'";
                    }
                }
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
        aviationwx_log('error', 'config schema errors', ['errors' => $errors], 'app');
        return null;
    }

    aviationwx_log('info', 'config loaded', ['path' => $configFile, 'mtime' => $fileMtime], 'app');
    
    // Cache in static variable (with mtime)
    $cachedConfig = $config;
    $cachedConfigMtime = $fileMtime;
    
    // Cache in APCu if available (1 hour TTL, but invalidated on file change)
    if ($useCache && function_exists('apcu_store')) {
        apcu_store($cacheKey, $config, CONFIG_CACHE_TTL);
        apcu_store($cacheTimeKey, $fileMtime, CONFIG_CACHE_TTL);
    }
    
    return $config;
}

/**
 * Clear configuration cache
 * Removes both the config data and the modification time check
 */
function clearConfigCache() {
    if (function_exists('apcu_delete')) {
        apcu_delete('aviationwx_config');
        apcu_delete('aviationwx_config_mtime');
    }
}

/**
 * Get sanitized airport ID from request
 * 
 * Checks both query parameter and subdomain, validating format before returning.
 * Supports both ?airport=kspb and kspb.aviationwx.org patterns.
 * 
 * @return string Validated airport ID (3-4 lowercase alphanumeric) or empty string
 */
function getAirportIdFromRequest(): string {
    $airportId = '';
    
    // First, try query parameter
    if (isset($_GET['airport']) && !empty($_GET['airport'])) {
        $rawId = strtolower(trim($_GET['airport']));
        if (validateAirportId($rawId)) {
            $airportId = $rawId;
        }
    } else {
        // Try extracting from subdomain
        $host = isset($_SERVER['HTTP_HOST']) ? strtolower(trim($_SERVER['HTTP_HOST'])) : '';
        $baseDomain = getBaseDomain();
        
        // Match subdomain pattern (e.g., kspb.aviationwx.org)
        // Uses base domain from config to support custom domains
        $pattern = '/^([a-z0-9]{3,4})\.' . preg_quote($baseDomain, '/') . '$/';
        if (preg_match($pattern, $host, $matches)) {
            $rawId = $matches[1];
            if (validateAirportId($rawId)) {
                $airportId = $rawId;
            }
        } else {
            // Fallback: check if host has 3+ parts (handles other TLDs and custom domains)
            $hostParts = explode('.', $host);
            if (count($hostParts) >= 3) {
                $rawId = $hostParts[0];
                if (validateAirportId($rawId)) {
                    $airportId = $rawId;
                }
            }
        }
    }
    
    return $airportId;
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
