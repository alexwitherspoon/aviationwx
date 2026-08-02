<?php
/**
 * Public API - Bridge health heartbeat
 *
 * POST /v1/bridge/health
 */

require_once __DIR__ . '/../../lib/bridge/middleware.php';
require_once __DIR__ . '/../../lib/bridge/health.php';
require_once __DIR__ . '/../../lib/bridge/auth.php';
require_once __DIR__ . '/../../lib/public-api/response.php';

/**
 * Handle POST /v1/bridge/health
 *
 * @param array $params Unused path params
 * @param array $context Bridge auth context
 * @return void
 */
function handleBridgeHealth(array $params, array $context): void
{
    unset($params);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'POST') {
        sendPublicApiError('METHOD_NOT_ALLOWED', 'Use POST for health', 405);
        return;
    }

    $parsed = bridgeReadJsonBody();
    if (!$parsed['ok']) {
        sendPublicApiError(PUBLIC_API_ERROR_INVALID_REQUEST, $parsed['error'] ?? 'Invalid body', 400);
        return;
    }
    $body = $parsed['body'];

    $bodyBridgeId = isset($body['bridge_id']) && is_string($body['bridge_id']) ? $body['bridge_id'] : null;
    warnIfBridgeBodyIdMismatch($context, $bodyBridgeId);

    $normalized = bridgeNormalizeHealthPayload($body);
    if (!$normalized['ok']) {
        sendPublicApiError(
            $normalized['code'] ?? PUBLIC_API_ERROR_INVALID_REQUEST,
            $normalized['error'] ?? 'Invalid health payload',
            400
        );
        return;
    }

    $ok = bridgeStoreHealth($context['airport_id'], $context['bridge_id'], $normalized['health']);
    if (!$ok) {
        sendPublicApiError(PUBLIC_API_ERROR_SERVICE_UNAVAILABLE, 'Failed to persist bridge health', 503);
        return;
    }

    // 204 No Content
    http_response_code(204);
    $allowedOrigin = getCorsAllowOriginForAviationWx($_SERVER['HTTP_ORIGIN'] ?? null);
    if ($allowedOrigin !== null) {
        header('Access-Control-Allow-Origin: ' . $allowedOrigin);
    }
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: X-API-Key, X-Api-Key, Content-Type');
    exit;
}
