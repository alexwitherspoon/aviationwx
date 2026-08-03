<?php
/**
 * Public API - Bridge weather ingest
 *
 * POST /v1/bridge/weather
 *
 * Accepts keyed provider-tagged observations with provider_meta.raw (installer
 * verification). Public weather requires an explicit weather_sources enable row.
 */

require_once __DIR__ . '/../../lib/bridge/middleware.php';
require_once __DIR__ . '/../../lib/bridge/auth.php';
require_once __DIR__ . '/../../lib/bridge/weather-store.php';
require_once __DIR__ . '/../../lib/public-api/response.php';
require_once __DIR__ . '/../../lib/config.php';

/**
 * Handle POST /v1/bridge/weather
 *
 * @param array $params Unused path params
 * @param array $context Bridge auth context
 * @return void
 */
function handleBridgeWeather(array $params, array $context): void
{
    unset($params);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'POST') {
        sendPublicApiError('METHOD_NOT_ALLOWED', 'Use POST for weather', 405);
        return;
    }

    $parsed = bridgeReadJsonBody(131072);
    if (!$parsed['ok']) {
        sendPublicApiError(PUBLIC_API_ERROR_INVALID_REQUEST, $parsed['error'] ?? 'Invalid body', 400);
        return;
    }
    $body = $parsed['body'];

    $bodyBridgeId = isset($body['bridge_id']) && is_string($body['bridge_id']) ? $body['bridge_id'] : null;
    warnIfBridgeBodyIdMismatch($context, $bodyBridgeId);

    $extracted = bridgeExtractWeatherItems($body);
    if (!$extracted['ok']) {
        sendPublicApiError(
            $extracted['code'] ?? PUBLIC_API_ERROR_INVALID_REQUEST,
            $extracted['error'] ?? 'Invalid weather payload',
            400
        );
        return;
    }

    $airportId = $context['airport_id'];
    $bridgeId = $context['bridge_id'];
    $config = loadConfig();
    $airport = is_array($config) ? ($config['airports'][$airportId] ?? null) : null;

    $accepted = 0;
    $enabledHits = 0;
    foreach ($extracted['items'] as $item) {
        $normalized = bridgeNormalizeWeatherItem($item);
        if (!$normalized['ok']) {
            sendPublicApiError(
                $normalized['code'] ?? PUBLIC_API_ERROR_INVALID_REQUEST,
                $normalized['error'] ?? 'Invalid weather item',
                400
            );
            return;
        }
        $record = $normalized['record'];
        $itemBodyBridge = $record['body_bridge_id'] ?? null;
        if (is_string($itemBodyBridge)) {
            warnIfBridgeBodyIdMismatch($context, $itemBodyBridge);
        }

        if (!bridgeStoreWeatherObservation($airportId, $bridgeId, $record)) {
            sendPublicApiError(PUBLIC_API_ERROR_SERVICE_UNAVAILABLE, 'Failed to persist weather observation', 503);
            return;
        }
        $accepted++;
        if (is_array($airport) && isBridgeWeatherSourceEnabled($airport, $bridgeId, $record['source_id'])) {
            $enabledHits++;
        }
    }

    http_response_code(204);
    $allowedOrigin = getCorsAllowOriginForAviationWx($_SERVER['HTTP_ORIGIN'] ?? null);
    if ($allowedOrigin !== null) {
        header('Access-Control-Allow-Origin: ' . $allowedOrigin);
    }
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: X-API-Key, X-Api-Key, Content-Type');
    // Installer verification only - not part of the public wire contract
    header('X-Bridge-Weather-Accepted: ' . (string) $accepted);
    header('X-Bridge-Weather-Enabled: ' . (string) $enabledHits);
    exit;
}
