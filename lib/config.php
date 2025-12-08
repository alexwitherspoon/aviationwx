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
    // Note: In CI (GitHub Actions), airports.json doesn't exist - this is expected and handled gracefully
    if (!file_exists($configFile)) {
        // In CI, this is normal - tests use CONFIG_PATH pointing to test fixtures
        // In production, this indicates a deployment issue
        if (!isProduction()) {
            // Non-production: log as info (expected in CI/test environments)
            aviationwx_log('info', 'config file not found (using defaults)', ['path' => $configFile], 'app');
        } else {
            // Production: log as error (deployment issue)
            aviationwx_log('error', 'config file not found', ['path' => $configFile], 'app');
        }
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
 * 
 * Removes both the config data and the modification time check from APCu.
 * This forces the next loadConfig() call to reload from disk.
 * 
 * @return void
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
        $icao = isset($airport['icao']) ? strtoupper(trim($airport['icao'])) : '';
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
 * Download and cache ICAO airport codes list from GitHub
 * 
 * Downloads from lxndrblz/Airports repository (CC-BY-SA-4.0 license)
 * Caches the list locally to avoid repeated downloads
 * 
 * @param bool $forceRefresh Force refresh even if cache exists
 * @return array|null Array of ICAO codes (uppercase) or null on error
 */
function getIcaoAirportList(bool $forceRefresh = false): ?array {
    $cacheFile = __DIR__ . '/../cache/icao_airports.json';
    $cacheMaxAge = 30 * 24 * 3600; // 30 days
    
    // Check cache first (unless forcing refresh)
    if (!$forceRefresh && file_exists($cacheFile)) {
        $cacheAge = time() - filemtime($cacheFile);
        if ($cacheAge < $cacheMaxAge) {
            $cached = @json_decode(file_get_contents($cacheFile), true);
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
                $cached = @json_decode(file_get_contents($cacheFile), true);
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
            @mkdir($cacheDir, 0755, true);
        }
        @file_put_contents($cacheFile, json_encode($icaoCodes, JSON_PRETTY_PRINT));
        
        aviationwx_log('info', 'ICAO airport list downloaded and cached', ['count' => count($icaoCodes)], 'app');
        return $icaoCodes;
        
    } catch (Exception $e) {
        aviationwx_log('error', 'error downloading ICAO list', ['error' => $e->getMessage()], 'app');
        // Return cached version if available
        if (file_exists($cacheFile)) {
            $cached = @json_decode(file_get_contents($cacheFile), true);
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
 * 2. Cached ICAO airport list from GitHub (lxndrblz/Airports)
 * 3. Cached lookup results (APCu)
 * 4. METAR data availability (fallback, only if list unavailable)
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
            $airportIcao = isset($airport['icao']) ? strtoupper(trim($airport['icao'])) : '';
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
    
    // Check against cached ICAO airport list (from GitHub)
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
 * @param string $icaoCode The ICAO code to validate
 * @return bool True if valid format (3-4 alphanumeric characters), false otherwise
 */
function isValidIcaoFormat(string $icaoCode): bool {
    if (empty($icaoCode)) {
        return false;
    }
    $icaoCode = strtoupper(trim($icaoCode));
    return preg_match('/^[A-Z0-9]{3,4}$/', $icaoCode) === 1;
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
    
    // Validate each airport
    foreach ($config['airports'] as $airportCode => $airport) {
        if (!is_array($airport)) {
            $errors[] = "Airport '{$airportCode}' must be an object";
            continue;
        }
        
        // Required fields
        $requiredFields = ['name', 'icao', 'lat', 'lon'];
        foreach ($requiredFields as $field) {
            if (!isset($airport[$field])) {
                $errors[] = "Airport '{$airportCode}' missing required field: '{$field}'";
            }
        }
        
        // Validate ICAO format (note: real airport validation is separate)
        if (isset($airport['icao'])) {
            if (!$validateIcaoFormat($airport['icao'])) {
                $errors[] = "Airport '{$airportCode}' has invalid ICAO code format: '{$airport['icao']}' (must be 3-4 uppercase letters)";
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
                foreach ($airport['services'] as $serviceName => $serviceValue) {
                    if (!is_bool($serviceValue)) {
                        $errors[] = "Airport '{$airportCode}' service '{$serviceName}' must be a boolean, got: " . gettype($serviceValue);
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
                    $validTypes = ['tempest', 'ambient', 'weatherlink', 'metar'];
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
                            if (!isset($webcam['rtsp_transport'])) {
                                $errors[] = "Airport '{$airportCode}' webcam[{$idx}] (rtsp type) missing 'rtsp_transport' field";
                            }
                            if (!isset($webcam['url'])) {
                                $errors[] = "Airport '{$airportCode}' webcam[{$idx}] (rtsp type) missing 'url' field";
                            }
                        } else {
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
                        
                        if (isset($webcam['partner_link']) && !$validateUrl($webcam['partner_link'])) {
                            $errors[] = "Airport '{$airportCode}' webcam[{$idx}] has invalid partner_link: must be a valid URL";
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
    
    return [
        'valid' => empty($errors),
        'errors' => $errors,
        'warnings' => $warnings
    ];
}

/**
 * Validate ICAO codes in airports.json against real airport list
 * 
 * Checks all ICAO codes in the config against the cached airport list.
 * Used for CI/CD validation to catch invalid airport codes before deployment.
 * 
 * @param array|null $config Optional config array (if already loaded)
 * @return array Array with 'valid' (bool) and 'errors' (array of error messages)
 */
function validateAirportsIcaoCodes(?array $config = null): array {
    $errors = [];
    
    if ($config === null) {
        $config = loadConfig();
    }
    
    if ($config === null) {
        return ['valid' => false, 'errors' => ['Could not load configuration']];
    }
    
    if (!isset($config['airports']) || !is_array($config['airports'])) {
        return ['valid' => false, 'errors' => ['No airports found in configuration']];
    }
    
    // Get ICAO list (will download if needed)
    $icaoList = getIcaoAirportList();
    if ($icaoList === null || empty($icaoList)) {
        // If we can't get the list, we can't validate - but don't fail
        // This allows deployments to proceed even if GitHub is temporarily unavailable
        return ['valid' => true, 'errors' => [], 'warnings' => ['Could not download ICAO airport list - skipping validation']];
    }
    
    // Convert to associative array for fast lookup
    $icaoLookup = array_flip($icaoList);
    
    foreach ($config['airports'] as $airportId => $airport) {
        $icao = isset($airport['icao']) ? strtoupper(trim($airport['icao'])) : '';
        
        if (empty($icao)) {
            $errors[] = "Airport '{$airportId}' is missing ICAO code";
            continue;
        }
        
        // Check if it's a valid format
        if (!isValidIcaoFormat($icao)) {
            $errors[] = "Airport '{$airportId}' has invalid ICAO format: '{$icao}'";
            continue;
        }
        
        // Check if it's a real airport
        if (!isset($icaoLookup[$icao])) {
            $errors[] = "Airport '{$airportId}' has ICAO code '{$icao}' which is not found in the official airport list";
        }
    }
    
    return [
        'valid' => empty($errors),
        'errors' => $errors
    ];
}
