<?php
/**
 * Public API v1 Router
 * 
 * Routes incoming requests to the appropriate endpoint handler.
 * This is the main entry point for all /v1/* requests.
 * 
 * Routing is handled by parsing the request path and dispatching
 * to the appropriate endpoint file.
 */

// Start output buffering to catch any stray output
ob_start();

require_once __DIR__ . '/../../lib/public-api/middleware.php';
require_once __DIR__ . '/../../lib/public-api/response.php';
require_once __DIR__ . '/../../lib/logger.php';

// Get the request path (strip /api/v1 prefix if present)
// Use X-Original-URI header if set by nginx proxy, fallback to REQUEST_URI
$requestUri = $_SERVER['HTTP_X_ORIGINAL_URI'] ?? $_SERVER['REQUEST_URI'] ?? '/';
$requestPath = parse_url($requestUri, PHP_URL_PATH);

// Normalize path - remove /api/v1 prefix and trailing slashes
$path = preg_replace('#^/api/v1#', '', $requestPath);
$path = preg_replace('#^/v1#', '', $path);
$path = rtrim($path, '/');
if (empty($path)) {
    $path = '/';
}

// Process through middleware (checks API enabled, auth, rate limits)
$context = processPublicApiRequest();

// Route the request
try {
    routePublicApiRequest($path, $context);
} catch (Throwable $e) {
    aviationwx_log('error', 'public api unhandled exception', [
        'path' => $path,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ], 'api');
    
    ob_clean();
    sendPublicApiError(
        PUBLIC_API_ERROR_SERVICE_UNAVAILABLE,
        'An unexpected error occurred',
        503
    );
}

/**
 * Route a public API request to the appropriate handler
 * 
 * @param string $path Request path (after /v1 prefix)
 * @param array $context Request context from middleware
 */
function routePublicApiRequest(string $path, array $context): void
{
    // Pattern matching for routes
    $routes = [
        // GET /v1/airports
        '#^/airports$#' => ['file' => 'airports.php', 'handler' => 'handleListAirports'],
        
        // GET /v1/airports/{id}
        '#^/airports/([a-zA-Z0-9-]+)$#' => ['file' => 'airport.php', 'handler' => 'handleGetAirport'],
        
        // GET /v1/airports/{id}/weather
        '#^/airports/([a-zA-Z0-9-]+)/weather$#' => ['file' => 'weather.php', 'handler' => 'handleGetWeather'],
        
        // GET /v1/airports/{id}/weather/history
        '#^/airports/([a-zA-Z0-9-]+)/weather/history$#' => ['file' => 'weather-history.php', 'handler' => 'handleGetWeatherHistory'],
        
        // GET /v1/airports/{id}/webcams
        '#^/airports/([a-zA-Z0-9-]+)/webcams$#' => ['file' => 'webcams.php', 'handler' => 'handleListWebcams'],
        
        // GET /v1/airports/{id}/webcams/{cam}/image
        '#^/airports/([a-zA-Z0-9-]+)/webcams/(\d+)/image$#' => ['file' => 'webcam-image.php', 'handler' => 'handleGetWebcamImage'],
        
        // GET /v1/airports/{id}/webcams/{cam}/history
        '#^/airports/([a-zA-Z0-9-]+)/webcams/(\d+)/history$#' => ['file' => 'webcam-history.php', 'handler' => 'handleGetWebcamHistory'],
        
        // GET /v1/weather/bulk
        '#^/weather/bulk$#' => ['file' => 'weather-bulk.php', 'handler' => 'handleGetWeatherBulk'],
        
        // GET /v1/status
        '#^/status$#' => ['file' => 'status.php', 'handler' => 'handleGetStatus'],
    ];
    
    // Try to match the path to a route
    foreach ($routes as $pattern => $route) {
        if (preg_match($pattern, $path, $matches)) {
            // Remove the full match, keep only capture groups
            array_shift($matches);
            
            // Include the handler file
            $handlerFile = __DIR__ . '/' . $route['file'];
            if (!file_exists($handlerFile)) {
                aviationwx_log('error', 'public api handler file not found', [
                    'file' => $route['file'],
                    'path' => $path,
                ], 'api');
                
                ob_clean();
                sendPublicApiError(
                    PUBLIC_API_ERROR_SERVICE_UNAVAILABLE,
                    'Endpoint handler not available',
                    503
                );
                return;
            }
            
            require_once $handlerFile;
            
            // Call the handler function
            $handlerFunction = $route['handler'];
            if (!function_exists($handlerFunction)) {
                aviationwx_log('error', 'public api handler function not found', [
                    'function' => $handlerFunction,
                    'path' => $path,
                ], 'api');
                
                ob_clean();
                sendPublicApiError(
                    PUBLIC_API_ERROR_SERVICE_UNAVAILABLE,
                    'Endpoint handler not available',
                    503
                );
                return;
            }
            
            // Clear any buffered output before calling handler
            ob_clean();
            
            // Call the handler with path parameters and context
            $handlerFunction($matches, $context);
            return;
        }
    }
    
    // No route matched - 404
    ob_clean();
    sendPublicApiError(
        PUBLIC_API_ERROR_INVALID_REQUEST,
        'Unknown endpoint: ' . $path,
        404
    );
}

