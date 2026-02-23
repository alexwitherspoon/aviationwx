<?php
/**
 * Public API Response Helpers
 * 
 * Provides consistent response formatting for all public API endpoints.
 * Includes success responses, error responses, and standard headers.
 * CORS is tightened to *.aviationwx.org (M2M API, not third-party embeds).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../cors.php';

/**
 * Standard error codes for the public API
 */
define('PUBLIC_API_ERROR_INVALID_REQUEST', 'INVALID_REQUEST');
define('PUBLIC_API_ERROR_INVALID_API_KEY', 'INVALID_API_KEY');
define('PUBLIC_API_ERROR_AIRPORT_NOT_FOUND', 'AIRPORT_NOT_FOUND');
define('PUBLIC_API_ERROR_WEBCAM_NOT_FOUND', 'WEBCAM_NOT_FOUND');
define('PUBLIC_API_ERROR_RATE_LIMITED', 'RATE_LIMITED');
define('PUBLIC_API_ERROR_API_NOT_ENABLED', 'API_NOT_ENABLED');
define('PUBLIC_API_ERROR_SERVICE_UNAVAILABLE', 'SERVICE_UNAVAILABLE');

/**
 * Send a successful JSON response
 *
 * @param array $data Response data
 * @param array $meta Optional metadata to include
 * @param int $httpCode HTTP status code (default 200)
 * @param array $extraHeaders Additional headers to send
 * @param bool $useEmbedCors When true, use permissive CORS (*) for third-party embeds
 */
function sendPublicApiSuccess(array $data, array $meta = [], int $httpCode = 200, array $extraHeaders = [], bool $useEmbedCors = false): void
{
    $response = [
        'success' => true,
        'meta' => array_merge([
            'api_version' => getPublicApiVersion(),
            'request_time' => gmdate('c'),
            'attribution' => getPublicApiAttributionText(),
        ], $meta),
    ];

    if (isset($data['airports'])) {
        $response['airports'] = $data['airports'];
    } elseif (isset($data['airport'])) {
        $response['airport'] = $data['airport'];
    } elseif (isset($data['weather'])) {
        $response['weather'] = $data['weather'];
    } elseif (isset($data['webcams'])) {
        $response['webcams'] = $data['webcams'];
    } elseif (isset($data['observations'])) {
        $response['observations'] = $data['observations'];
    } elseif (isset($data['frames'])) {
        $response['frames'] = $data['frames'];
    } elseif (isset($data['status'])) {
        $response['status'] = $data['status'];
    } elseif (isset($data['embed']) || isset($data['diff'])) {
        $response['data'] = $data;
    } else {
        $response['data'] = $data;
    }

    sendPublicApiResponse($response, $httpCode, $extraHeaders, $useEmbedCors);
}

/**
 * Send an error JSON response
 *
 * @param string $code Error code constant
 * @param string $message Human-readable error message
 * @param int $httpCode HTTP status code
 * @param int|null $retryAfter Seconds until client can retry (for rate limiting)
 * @param array $extraHeaders Additional headers to send
 * @param bool $useEmbedCors When true, use permissive CORS (*) for third-party embeds
 */
function sendPublicApiError(string $code, string $message, int $httpCode = 400, ?int $retryAfter = null, array $extraHeaders = [], bool $useEmbedCors = false): void
{
    $response = [
        'success' => false,
        'error' => [
            'code' => $code,
            'message' => $message,
        ],
    ];

    if ($retryAfter !== null) {
        $response['error']['retry_after'] = $retryAfter;
        $extraHeaders['Retry-After'] = (string)$retryAfter;
    }

    sendPublicApiResponse($response, $httpCode, $extraHeaders, $useEmbedCors);
}

/**
 * Send a JSON response with headers
 *
 * @param array $response Response data
 * @param int $httpCode HTTP status code
 * @param array $extraHeaders Additional headers
 * @param bool $useEmbedCors When true, use permissive CORS (*) for third-party embeds
 */
function sendPublicApiResponse(array $response, int $httpCode = 200, array $extraHeaders = [], bool $useEmbedCors = false): void
{
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');

    if ($useEmbedCors) {
        sendEmbedCorsHeaders();
    } else {
        $allowedOrigin = getCorsAllowOriginForAviationWx($_SERVER['HTTP_ORIGIN'] ?? null);
        if ($allowedOrigin !== null) {
            header('Access-Control-Allow-Origin: ' . $allowedOrigin);
        }
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: X-API-Key');
        header('Access-Control-Max-Age: 86400');
    }
    
    // Send extra headers
    foreach ($extraHeaders as $name => $value) {
        header($name . ': ' . $value);
    }
    
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * Get cache headers for different content types
 * 
 * @param string $type Content type: 'live', 'metadata', 'immutable', 'none'
 * @return array Headers as key-value pairs
 */
function getPublicApiCacheHeaders(string $type): array
{
    switch ($type) {
        case 'live':
            // Live data (weather, webcam images) - 60 second cache
            return [
                'Cache-Control' => 'public, max-age=60, s-maxage=60, stale-while-revalidate=30',
            ];
            
        case 'metadata':
            // Static metadata (airport info) - 1 hour cache
            return [
                'Cache-Control' => 'public, max-age=3600, s-maxage=3600, stale-while-revalidate=300',
            ];
            
        case 'immutable':
            // Immutable data (historical frames) - cache forever
            return [
                'Cache-Control' => 'public, max-age=31536000, immutable',
            ];
            
        case 'none':
        default:
            // No caching (status, errors)
            return [
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ];
    }
}

/**
 * Send cache headers
 * 
 * @param string $type Content type for caching
 */
function sendPublicApiCacheHeaders(string $type): void
{
    $headers = getPublicApiCacheHeaders($type);
    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }
}

/**
 * Handle CORS preflight request
 * 
 * @return bool True if this was a preflight request (caller should exit)
 */
function handlePublicApiCorsPreflightIfNeeded(): bool
{
    if ($_SERVER['REQUEST_METHOD'] !== 'OPTIONS') {
        return false;
    }

    $uri = $_SERVER['REQUEST_URI'] ?? $_SERVER['HTTP_X_ORIGINAL_URI'] ?? '';
    $useEmbedCors = (strpos($uri, '/embed') !== false);

    http_response_code(204);
    if ($useEmbedCors) {
        sendEmbedCorsHeaders();
    } else {
        $allowedOrigin = getCorsAllowOriginForAviationWx($_SERVER['HTTP_ORIGIN'] ?? null);
        if ($allowedOrigin !== null) {
            header('Access-Control-Allow-Origin: ' . $allowedOrigin);
        }
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: X-API-Key');
        header('Access-Control-Max-Age: 86400');
    }
    header('Content-Length: 0');
    return true;
}

/**
 * Get units metadata for weather responses
 * 
 * @return array Units information
 */
function getPublicApiWeatherUnits(): array
{
    return [
        'temperature' => 'celsius',
        'wind_speed' => 'knots',
        'pressure' => 'inHg',
        'visibility' => 'statute_miles',
        'ceiling' => 'feet_agl',
        'precipitation' => 'inches',
        'elevation' => 'feet',
    ];
}

