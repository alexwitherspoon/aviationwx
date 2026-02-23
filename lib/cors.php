<?php
/**
 * CORS Helpers for Embed Widget and Public API
 *
 * Embed widgets are loaded in iframes or web components on third-party sites.
 * These endpoints must return CORS headers to allow cross-origin fetch requests.
 *
 * Public API (M2M) uses allowlist CORS: *.aviationwx.org and localhost for dev.
 */

/**
 * Check if an origin is allowed for CORS (M2M API)
 *
 * Uses base_domain from airports.json config. Allows https://{base_domain},
 * https://*.{base_domain}, and localhost for dev.
 *
 * @param string|null $origin The Origin header value (e.g. "https://kspb.aviationwx.org")
 * @param string|null $baseDomain Base domain from config (e.g. "aviationwx.org"). When null, uses getBaseDomain().
 * @return string|null The origin if allowed, null if not
 */
function getCorsAllowOriginForAviationWx(?string $origin, ?string $baseDomain = null): ?string
{
    if ($origin === null || $origin === '') {
        return null;
    }

    $parsed = parse_url($origin);
    if ($parsed === false || !isset($parsed['host'])) {
        return null;
    }

    $host = strtolower($parsed['host']);
    $scheme = $parsed['scheme'] ?? '';

    $domain = $baseDomain ?? (function_exists('getBaseDomain') ? getBaseDomain() : 'aviationwx.org');
    $domain = strtolower($domain);
    $escaped = preg_quote($domain, '/');

    if ($scheme === 'https' && ($host === $domain || preg_match('/^([a-z0-9-]+\.)*' . $escaped . '$/', $host))) {
        return $origin;
    }

    if ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1'], true)) {
        return $origin;
    }

    return null;
}

/**
 * Send CORS headers for embed widget API endpoints
 *
 * Allows requests from any origin (embed.aviationwx.org, third-party sites).
 * Call at the start of any API endpoint used by the embed widget.
 *
 * @return void
 */
function sendEmbedCorsHeaders(): void
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Accept');
    header('Access-Control-Max-Age: 86400');
}

/**
 * Handle CORS preflight (OPTIONS) request for embed endpoints
 *
 * @return bool True if preflight was handled (caller should exit)
 */
function handleEmbedCorsPreflight(): bool
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        sendEmbedCorsHeaders();
        http_response_code(204);
        header('Content-Length: 0');
        return true;
    }
    return false;
}
