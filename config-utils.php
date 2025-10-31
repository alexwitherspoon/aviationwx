<?php
/**
 * Shared Configuration Utilities
 * Provides centralized config loading, caching, and validation
 */

/**
 * Validate and sanitize airport ID
 * Airport IDs must be 3-4 lowercase alphanumeric characters (ICAO format)
 */
function validateAirportId($id) {
    if (empty($id)) {
        return false;
    }
    return preg_match('/^[a-z0-9]{3,4}$/', strtolower(trim($id))) === 1;
}

/**
 * Load airport configuration with caching
 * Uses APCu cache if available, falls back to static variable for request lifetime
 */
function loadConfig($useCache = true) {
    static $cachedConfig = null;
    
    // Try APCu cache first (if available)
    if ($useCache && function_exists('apcu_fetch')) {
        $cacheKey = 'aviationwx_config';
        $cached = apcu_fetch($cacheKey);
        if ($cached !== false) {
            return $cached;
        }
    }
    
    // Use static cache for request lifetime
    if ($cachedConfig !== null) {
        return $cachedConfig;
    }
    
    // Load from file
    $envConfigPath = getenv('CONFIG_PATH');
    $configFile = ($envConfigPath && file_exists($envConfigPath)) ? $envConfigPath : (__DIR__ . '/airports.json');
    
    // Validate file exists and is not a directory
    if (!file_exists($configFile)) {
        error_log('Configuration file not found: ' . $configFile);
        return null;
    }
    if (is_dir($configFile)) {
        error_log('Configuration path is a directory: ' . $configFile);
        return null;
    }
    
    // Read and validate JSON
    $jsonContent = @file_get_contents($configFile);
    if ($jsonContent === false) {
        error_log('Failed to read configuration file: ' . $configFile);
        return null;
    }
    
    $config = json_decode($jsonContent, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($config)) {
        error_log('Configuration file is not valid JSON: ' . json_last_error_msg() . ' (file: ' . $configFile . ')');
        return null;
    }
    
    // Cache in static variable
    $cachedConfig = $config;
    
    // Cache in APCu if available (1 hour TTL)
    if ($useCache && function_exists('apcu_store')) {
        apcu_store('aviationwx_config', $config, 3600);
    }
    
    return $config;
}

/**
 * Clear configuration cache
 */
function clearConfigCache() {
    if (function_exists('apcu_delete')) {
        apcu_delete('aviationwx_config');
    }
}

/**
 * Get sanitized airport ID from request
 * Checks both query parameter and subdomain
 */
function getAirportIdFromRequest() {
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
        
        // Match subdomain pattern (kspb.aviationwx.org)
        if (preg_match('/^([a-z0-9]{3,4})\.aviationwx\.org$/', $host, $matches)) {
            $rawId = $matches[1];
            if (validateAirportId($rawId)) {
                $airportId = $rawId;
            }
        } else {
            // Also check if host has 3+ parts (handles other TLDs)
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
