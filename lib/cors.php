<?php
/**
 * CORS Helpers for Embed Widget
 *
 * Embed widgets are loaded in iframes or web components on third-party sites.
 * These endpoints must return CORS headers to allow cross-origin fetch requests.
 */

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
