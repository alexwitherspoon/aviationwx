<?php
/**
 * Public API - Bridge bootstrap
 *
 * GET /v1/bridge/bootstrap
 */

require_once __DIR__ . '/../../lib/bridge/store.php';
require_once __DIR__ . '/../../lib/bridge/config.php';
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/public-api/response.php';

/**
 * Handle GET /v1/bridge/bootstrap
 *
 * @param array $params Unused path params
 * @param array $context Bridge auth context from processBridgeApiRequest()
 * @return void
 */
function handleBridgeBootstrap(array $params, array $context): void
{
    unset($params);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'GET') {
        sendPublicApiError('METHOD_NOT_ALLOWED', 'Use GET for bootstrap', 405);
        return;
    }

    $airportId = $context['airport_id'];
    $bridgeId = $context['bridge_id'];

    $config = loadConfig();
    $airport = $config['airports'][$airportId] ?? null;
    if (!is_array($airport)) {
        sendPublicApiError(PUBLIC_API_ERROR_AIRPORT_NOT_FOUND, 'Airport not found for bridge key', 500);
        return;
    }

    $decl = getMagneticDeclinationWithSource($airport);
    $enabled = [];
    foreach (listBridgeCacheWeatherSources($airport, $bridgeId) as $ws) {
        $enabled[] = [
            'kind' => 'weather',
            'provider' => $ws['type'] ?? null,
            'bridge_source_id' => $ws['bridge_source_id'] ?? null,
            'core_station_id' => $ws['station_id'] ?? null,
            'enabled' => true,
        ];
    }

    bridgeTouchMeta($airportId, $bridgeId, 'bootstrap');

    sendPublicApiSuccess([
        'airport' => [
            'id' => strtoupper($airportId),
            'name' => $airport['name'] ?? strtoupper($airportId),
        ],
        'bridge_id' => $bridgeId,
        'declination_deg' => $decl['declination_deg'],
        'declination_source' => $decl['declination_source'],
        'heartbeat_interval_seconds' => BRIDGE_HEARTBEAT_INTERVAL_SECONDS,
        'enabled_sources' => $enabled,
    ]);
}
