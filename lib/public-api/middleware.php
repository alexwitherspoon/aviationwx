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
    
    // Get client IP for rate limiting
    $ip = getPublicApiClientIp();
    
    // Check for first-party internal requests (embeds, dashboard, scheduler)
    // First-party requests come from localhost and forward the original client IP
    $isFirstParty = isFirstPartyRequest();
    $originalClientIp = null;
    
    if ($isFirstParty) {
        // First-party requests forward the original client IP for rate limiting
        // We trust this header ONLY because we verified REMOTE_ADDR is localhost
        $originalClientIp = $_SERVER['HTTP_X_FORWARDED_CLIENT_IP'] ?? null;
        if ($originalClientIp !== null && filter_var($originalClientIp, FILTER_VALIDATE_IP)) {
            $ip = $originalClientIp;
        }
        // First-party requests use anonymous tier - rate limited per original user
    }
    
    // Determine tier (partner or anonymous)
    $apiKey = getPublicApiKeyFromRequest();
    $partner = null;
    $tier = 'anonymous';
    
    if ($apiKey !== null) {
        // Check for partner API key
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
    
    // Check for internal health check (bypass rate limiting entirely)
    $isHealthCheck = isPublicApiHealthCheckRequest();
    
    // Check rate limits using the appropriate identifier
    // - Partner requests: use API key
    // - Anonymous/first-party: use client IP (original user's IP for first-party)
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
        'is_first_party' => $isFirstParty,
        'rate_limit' => $rateLimitResult,
        'ip' => $ip,
    ];
}

/**
 * Get the client IP address for rate limiting
 * 
 * Respects X-Forwarded-For header for proxied requests (CDN).
 * 
 * SECURITY WARNING: This function trusts forwarded headers which CAN BE SPOOFED.
 * Use this ONLY for rate limiting identification (where spoofing just means
 * the attacker rate-limits themselves under a fake IP - no security impact).
 * 
 * DO NOT use this for security decisions like first-party detection.
 * For security checks, use $_SERVER['REMOTE_ADDR'] directly.
 * See isFirstPartyRequest() for the secure implementation.
 * 
 * @return string Client IP address (may be from trusted proxy headers)
 */
function getPublicApiClientIp(): string
{
    // Check for CDN CF-Connecting-IP first (Cloudflare-specific header)
    // This is set by Cloudflare and should be trusted when behind CF
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    
    // Check X-Forwarded-For (standard proxy header)
    // Takes first IP in chain (original client)
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    
    // Direct connection - use actual TCP source
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

/**
 * Check if request is from a first-party internal service
 * 
 * First-party requests must meet BOTH criteria:
 * 1. Come from localhost (127.0.0.1 or ::1) - verified via REMOTE_ADDR
 * 2. Include a valid internal request header
 * 
 * SECURITY: Uses REMOTE_ADDR directly, NOT getPublicApiClientIp().
 * This is critical because X-Forwarded-For can be spoofed by attackers.
 * REMOTE_ADDR is set by the TCP connection and cannot be spoofed for HTTP.
 * 
 * @return bool True if request is verified first-party
 */
function isFirstPartyRequest(): bool
{
    // SECURITY: Use REMOTE_ADDR directly - NOT getPublicApiClientIp()
    // getPublicApiClientIp() trusts X-Forwarded-For which can be spoofed.
    // REMOTE_ADDR is the actual TCP connection source - cannot be spoofed.
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
    
    // Only allow localhost connections
    // These are the only IPs that can make internal requests
    $localhostIps = ['127.0.0.1', '::1'];
    if (!in_array($remoteAddr, $localhostIps, true)) {
        return false;
    }
    
    // Check for internal request header
    $internalHeader = $_SERVER['HTTP_X_INTERNAL_REQUEST'] ?? '';
    if (empty($internalHeader)) {
        return false;
    }
    
    // Validate header value (must be a known internal service)
    $validServices = ['embed-widget', 'dashboard', 'scheduler', 'health-check'];
    if (!in_array($internalHeader, $validServices, true)) {
        return false;
    }
    
    return true;
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
    
    // Airport IDs should be 3-10 alphanumeric characters (allow longer for custom IDs)
    if (empty($trimmed) || strlen($trimmed) < 3 || strlen($trimmed) > 20) {
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

