<?php
/**
 * Public API Middleware
 * 
 * Main middleware for processing public API requests.
 * Handles API enablement check, authentication, rate limiting,
 * and CORS. Should be included at the top of all API endpoints.
 */

require_once __DIR__ . '/../logger.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/rate-limit.php';
require_once __DIR__ . '/response.php';

/**
 * Process a public API request through middleware
 * 
 * Performs all standard checks and returns context for the endpoint.
 * Automatically sends error responses and exits if checks fail.
 * 
 * @return array {
 *   'authenticated' => bool,
 *   'partner' => array|null,
 *   'tier' => string,
 *   'rate_limit' => array,
 *   'ip' => string
 * }
 */
function processPublicApiRequest(): array
{
    // Handle CORS preflight
    if (handlePublicApiCorsPreflightIfNeeded()) {
        exit;
    }
    
    // Check if API is enabled
    if (!isPublicApiEnabled()) {
        sendPublicApiError(
            PUBLIC_API_ERROR_API_NOT_ENABLED,
            'Public API is not enabled',
            404
        );
        exit;
    }
    
    // Get client IP
    $ip = getPublicApiClientIp();
    
    // Check for API key
    $apiKey = getPublicApiKeyFromRequest();
    $partner = null;
    $tier = 'anonymous';
    
    if ($apiKey !== null) {
        $partner = validatePublicApiKey($apiKey);
        if ($partner === null) {
            sendPublicApiError(
                PUBLIC_API_ERROR_INVALID_API_KEY,
                'Invalid or disabled API key',
                401
            );
            exit;
        }
        $tier = 'partner';
    }
    
    // Check for internal health check (bypass rate limiting)
    $isHealthCheck = isPublicApiHealthCheckRequest();
    
    // Check rate limits
    $identifier = $apiKey ?? $ip;
    $rateLimitResult = checkPublicApiRateLimit($identifier, $tier, $isHealthCheck);
    
    // Send rate limit headers on every response
    $rateLimitHeaders = getPublicApiRateLimitHeaders($rateLimitResult);
    foreach ($rateLimitHeaders as $name => $value) {
        header($name . ': ' . $value);
    }
    
    if (!$rateLimitResult['allowed']) {
        sendPublicApiError(
            PUBLIC_API_ERROR_RATE_LIMITED,
            'Rate limit exceeded. Try again in ' . $rateLimitResult['retry_after'] . ' seconds.',
            429,
            $rateLimitResult['retry_after']
        );
        exit;
    }
    
    // Log the request
    logPublicApiRequest($ip, $partner, $tier);
    
    return [
        'authenticated' => $partner !== null,
        'partner' => $partner,
        'tier' => $tier,
        'rate_limit' => $rateLimitResult,
        'ip' => $ip,
    ];
}

/**
 * Get the client IP address
 * 
 * Respects X-Forwarded-For header for proxied requests (Cloudflare)
 * 
 * @return string Client IP address
 */
function getPublicApiClientIp(): string
{
    // Check for Cloudflare CF-Connecting-IP first
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    
    // Check X-Forwarded-For
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

/**
 * Get API key from request
 * 
 * Checks X-API-Key header first, then api_key query parameter
 * 
 * @return string|null API key or null if not provided
 */
function getPublicApiKeyFromRequest(): ?string
{
    // Check header first (preferred)
    if (!empty($_SERVER['HTTP_X_API_KEY'])) {
        return $_SERVER['HTTP_X_API_KEY'];
    }
    
    // Check query parameter (fallback)
    if (!empty($_GET['api_key'])) {
        return $_GET['api_key'];
    }
    
    return null;
}

/**
 * Check if this is an internal health check request
 * 
 * Health checks from localhost with the special header bypass rate limiting
 * 
 * @return bool True if this is a health check
 */
function isPublicApiHealthCheckRequest(): bool
{
    $healthCheckHeader = $_SERVER['HTTP_X_HEALTH_CHECK'] ?? '';
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
    
    return $healthCheckHeader === 'internal' 
        && ($remoteAddr === '127.0.0.1' || $remoteAddr === '::1');
}

/**
 * Log a public API request
 * 
 * @param string $ip Client IP
 * @param array|null $partner Partner info if authenticated
 * @param string $tier Rate limit tier
 */
function logPublicApiRequest(string $ip, ?array $partner, string $tier): void
{
    $endpoint = $_SERVER['REQUEST_URI'] ?? '/';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    aviationwx_log('info', 'public api request', [
        'endpoint' => $endpoint,
        'method' => $method,
        'ip' => $ip,
        'partner' => $partner['name'] ?? null,
        'tier' => $tier,
        'user_agent' => substr($userAgent, 0, 100),
    ], 'api');
}

/**
 * Validate and normalize an airport ID from the request
 * 
 * @param string $airportId Raw airport ID from request
 * @return string|null Normalized airport ID or null if invalid
 */
function validatePublicApiAirportId(string $airportId): ?string
{
    $trimmed = trim($airportId);
    
    // Airport IDs should be 3-4 alphanumeric characters
    if (empty($trimmed) || strlen($trimmed) < 3 || strlen($trimmed) > 10) {
        return null;
    }
    
    // Allow alphanumeric and hyphens (for custom-airport style IDs)
    if (!preg_match('/^[a-zA-Z0-9-]+$/i', $trimmed)) {
        return null;
    }
    
    return strtolower($trimmed);
}

/**
 * Get an airport by ID for the public API
 * 
 * Only returns enabled, non-maintenance airports (unless maintenance flag is acceptable)
 * 
 * @param string $airportId Airport ID
 * @param bool $allowMaintenance Include airports in maintenance mode
 * @return array|null Airport config or null if not found
 */
function getPublicApiAirport(string $airportId, bool $allowMaintenance = true): ?array
{
    require_once __DIR__ . '/../config.php';
    
    $config = loadConfig();
    if (!isset($config['airports'][$airportId])) {
        return null;
    }
    
    $airport = $config['airports'][$airportId];
    
    // Check if enabled
    if (!isAirportEnabled($airport)) {
        return null;
    }
    
    // Check maintenance status if not allowing maintenance airports
    if (!$allowMaintenance && isset($airport['maintenance']) && $airport['maintenance'] === true) {
        return null;
    }
    
    return $airport;
}

/**
 * Get all enabled airports for the public API
 * 
 * @param bool $includeMaintenance Include airports in maintenance mode
 * @return array Associative array of airport ID => airport config
 */
function getPublicApiAirports(bool $includeMaintenance = true): array
{
    require_once __DIR__ . '/../config.php';
    
    $config = loadConfig();
    $enabledAirports = getEnabledAirports($config);
    
    if (!$includeMaintenance) {
        $enabledAirports = array_filter($enabledAirports, function ($airport) {
            return !isset($airport['maintenance']) || $airport['maintenance'] !== true;
        });
    }
    
    return $enabledAirports;
}

