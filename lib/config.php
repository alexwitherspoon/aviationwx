<?php
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/airport-identifiers.php';

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
 * @param string|null $id Airport ID to validate
 * @return bool True if valid ICAO format, false otherwise
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
    return preg_match('/^[a-z0-9]{3,4}$/', strtolower(trim($id))) === 1;
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

/**
 * Get maximum stale hours from global config
 * @return int Maximum stale hours (default: 3)
 */
function getMaxStaleHours(): int {
    return (int)getGlobalConfig('max_stale_hours', 3);
}

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
 * @return int Timeout in seconds (default: 45)
 */
function getWorkerTimeout(): int {
    return (int)getGlobalConfig('worker_timeout_seconds', 45);
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
    // Use @ to suppress errors for non-critical file operations
    // We handle failures explicitly with null check and logging below
    $jsonContent = @file_get_contents($configFile);
    if ($jsonContent === false) {
        aviationwx_log('error', 'config read failed', ['path' => $configFile], 'app', true);
        return null;
    }
    
    $config = json_decode($jsonContent, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($config)) {
        aviationwx_log('error', 'config invalid json', ['error' => json_last_error_msg(), 'path' => $configFile], 'app', true);
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
        aviationwx_log('error', 'config schema errors', ['errors' => $errors], 'app', true);
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
 * 
 * Removes both the config data and the modification time check from APCu.
 * This forces the next loadConfig() call to reload from disk.
 * 
 * @return void
 */
function clearConfigCache(): void {
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
            
            if (isset($cfg['max_stale_hours'])) {
                if (!is_int($cfg['max_stale_hours']) || $cfg['max_stale_hours'] < 1) {
                    $errors[] = "config.max_stale_hours must be a positive integer";
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
    
    $validateIcaoFormat = function($icao) {
        if (!is_string($icao)) {
            return false;
        }
        return preg_match('/^[A-Z]{3,4}$/', $icao) === 1;
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
        $requiredFields = ['name', 'lat', 'lon'];
        foreach ($requiredFields as $field) {
            if (!isset($airport[$field])) {
                $errors[] = "Airport '{$airportCode}' missing required field: '{$field}'";
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
        foreach (['elevation_ft', 'webcam_refresh_seconds', 'weather_refresh_seconds'] as $field) {
            if (isset($airport[$field])) {
                $val = $airport[$field];
                if (!is_numeric($val) || $val < 0) {
                    $errors[] = "Airport '{$airportCode}' has invalid {$field}: {$val} (must be non-negative number)";
                }
            }
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
        
        // Validate METAR station
        if (isset($airport['metar_station']) && !$validateIcaoFormat($airport['metar_station'])) {
            $errors[] = "Airport '{$airportCode}' has invalid metar_station: '{$airport['metar_station']}' (must be 3-4 uppercase letters)";
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
                    $validTypes = ['tempest', 'ambient', 'weatherlink', 'pwsweather', 'metar'];
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
                        } elseif ($wsType === 'weatherlink') {
                            if (!isset($ws['api_key'])) {
                                $errors[] = "Airport '{$airportCode}' weather_source (weatherlink) missing 'api_key'";
                            }
                            if (!isset($ws['api_secret'])) {
                                $errors[] = "Airport '{$airportCode}' weather_source (weatherlink) missing 'api_secret'";
                            }
                            if (!isset($ws['station_id'])) {
                                $errors[] = "Airport '{$airportCode}' weather_source (weatherlink) missing 'station_id'";
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
                            $allowedPushWebcamFields = ['name', 'type', 'push_config', 'refresh_seconds'];
                            
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
                            $allowedRtspWebcamFields = ['name', 'type', 'url', 'rtsp_transport', 'refresh_seconds', 'rtsp_fetch_timeout', 'rtsp_max_runtime', 'transcode_timeout'];
                            
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
                            $allowedPullWebcamFields = ['name', 'type', 'url', 'refresh_seconds'];
                            
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
                        if (isset($partner['logo']) && !$validateUrl($partner['logo'])) {
                            $errors[] = "Airport '{$airportCode}' partner[{$idx}] has invalid logo: must be a valid URL";
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
    $ourairportsData = getOurAirportsData();
    if ($ourairportsData === null) {
        // If we can't get the data, we can't validate - but don't fail
        // This allows deployments to proceed even if OurAirports is temporarily unavailable
        return ['valid' => true, 'errors' => [], 'warnings' => ['Could not download OurAirports data - skipping validation']];
    }
    
    // Build lookup arrays for fast access
    $icaoLookup = [];
    if (isset($ourairportsData['icao']) && is_array($ourairportsData['icao'])) {
        foreach ($ourairportsData['icao'] as $code) {
            $normalized = strtoupper(trim($code));
            if (!empty($normalized)) {
                $icaoLookup[$normalized] = true;
            }
        }
    }
    
    $iataLookup = [];
    if (isset($ourairportsData['iata']) && is_array($ourairportsData['iata'])) {
        foreach ($ourairportsData['iata'] as $code) {
            $normalized = strtoupper(trim($code));
            if (!empty($normalized)) {
                $iataLookup[$normalized] = true;
            }
        }
    }
    
    $faaLookup = [];
    if (isset($ourairportsData['faa']) && is_array($ourairportsData['faa'])) {
        foreach ($ourairportsData['faa'] as $code) {
            $normalized = strtoupper(trim($code));
            if (!empty($normalized)) {
                $faaLookup[$normalized] = true;
            }
        }
    }
    
    // Validate each airport's identifiers
    foreach ($config['airports'] as $airportId => $airport) {
        // Validate ICAO code if present
        if (isset($airport['icao']) && !empty($airport['icao'])) {
            $icao = strtoupper(trim((string)$airport['icao']));
            
            // Check format
            if (!isValidIcaoFormat($icao)) {
                $errors[] = "Airport '{$airportId}' has invalid ICAO format: '{$icao}'";
            } elseif (!isset($icaoLookup[$icao])) {
                // Missing from OurAirports - warning only (data may be incomplete for very new/rare airports)
                $warnings[] = "Airport '{$airportId}' has ICAO code '{$icao}' which is not found in OurAirports data";
            }
        }
        
        // Validate IATA code if present
        if (isset($airport['iata']) && !empty($airport['iata']) && $airport['iata'] !== null) {
            $iata = strtoupper(trim((string)$airport['iata']));
            
            // Check format
            if (!isValidIataFormat($iata)) {
                $errors[] = "Airport '{$airportId}' has invalid IATA format: '{$iata}'";
            } elseif (!isset($iataLookup[$iata])) {
                // Missing from OurAirports - warning only
                $warnings[] = "Airport '{$airportId}' has IATA code '{$iata}' which is not found in OurAirports data";
            }
        }
        
        // Validate FAA identifier if present
        if (isset($airport['faa']) && !empty($airport['faa']) && $airport['faa'] !== null) {
            $faa = strtoupper(trim((string)$airport['faa']));
            
            // Check format
            if (!isValidFaaFormat($faa)) {
                $errors[] = "Airport '{$airportId}' has invalid FAA identifier format: '{$faa}'";
            } elseif (!isset($faaLookup[$faa])) {
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
