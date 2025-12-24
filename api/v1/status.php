<?php
/**
 * Public API - Status Endpoint
 * 
 * GET /v1/status
 * 
 * Returns API health and status information.
 */

require_once __DIR__ . '/../../lib/public-api/middleware.php';
require_once __DIR__ . '/../../lib/public-api/response.php';
require_once __DIR__ . '/../../lib/public-api/config.php';

/**
 * Handle GET /v1/status request
 * 
 * @param array $params Path parameters (empty for this endpoint)
 * @param array $context Request context from middleware
 */
function handleGetStatus(array $params, array $context): void
{
    $status = 'operational';
    $checks = [];
    
    // Check configuration
    $config = loadConfig();
    $checks['configuration'] = [
        'status' => $config !== null ? 'operational' : 'down',
        'message' => $config !== null ? 'Configuration loaded' : 'Configuration error',
    ];
    
    if ($config === null) {
        $status = 'degraded';
    }
    
    // Check APCu availability (for rate limiting)
    $apcuAvailable = function_exists('apcu_fetch');
    $checks['rate_limiting'] = [
        'status' => 'operational',
        'message' => $apcuAvailable ? 'APCu available' : 'File-based fallback',
    ];
    
    // Check cache directory
    $cacheDir = __DIR__ . '/../../cache';
    $cacheWritable = is_dir($cacheDir) && is_writable($cacheDir);
    $checks['cache'] = [
        'status' => $cacheWritable ? 'operational' : 'degraded',
        'message' => $cacheWritable ? 'Cache directory accessible' : 'Cache directory not writable',
    ];
    
    if (!$cacheWritable) {
        $status = 'degraded';
    }
    
    // Get airport count
    $airportCount = 0;
    if ($config !== null) {
        $enabledAirports = getPublicApiAirports(true);
        $airportCount = count($enabledAirports);
    }
    
    // Build response
    $statusData = [
        'status' => $status,
        'version' => getPublicApiVersion(),
        'timestamp' => gmdate('c'),
        'checks' => $checks,
        'airports_available' => $airportCount,
    ];
    
    // No caching for status endpoint
    sendPublicApiCacheHeaders('none');
    
    // Send response
    sendPublicApiSuccess(
        ['status' => $statusData],
        []
    );
}

