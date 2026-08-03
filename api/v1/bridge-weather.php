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
require_once __DIR__ . '/../../lib/logger.php';

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

    // Validate the full batch before any write so a late 400 cannot leave partial samples
    $prepared = bridgePrepareWeatherIngestBatch($extracted['items'], $bridgeId, $airport);
    if (!$prepared['ok']) {
        sendPublicApiError(
            $prepared['code'] ?? PUBLIC_API_ERROR_INVALID_REQUEST,
            $prepared['error'] ?? 'Invalid weather item',
            400
        );
        return;
    }

    foreach ($prepared['pending'] as $row) {
        $itemBodyBridge = $row['record']['body_bridge_id'] ?? null;
        if (is_string($itemBodyBridge)) {
            warnIfBridgeBodyIdMismatch($context, $itemBodyBridge);
        }
    }

    $accepted = 0;
    $enabledHits = 0;
    $pending = $prepared['pending'];
    foreach ($pending as $row) {
        if (!bridgeStoreWeatherObservation($airportId, $bridgeId, $row['record'])) {
            // Prior items in this request may already be on disk; client should retry the batch
            aviationwx_log('error', 'bridge weather persist failed mid-batch', [
                'airport_id' => $airportId,
                'bridge_id' => $bridgeId,
                'source_id' => $row['record']['source_id'] ?? null,
                'accepted_before_failure' => $accepted,
                'batch_size' => count($pending),
            ], 'bridge');
            sendPublicApiError(PUBLIC_API_ERROR_SERVICE_UNAVAILABLE, 'Failed to persist weather observation', 503);
            return;
        }
        $accepted++;
        if ($row['enabled']) {
            $enabledHits++;
        }
    }

    sendBridgeApiNoContent([
        // Diagnostic counts for installers; not part of the public wire contract
        'X-Bridge-Weather-Accepted' => (string) $accepted,
        'X-Bridge-Weather-Enabled' => (string) $enabledHits,
    ]);
}
