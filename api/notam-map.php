<?php
/**
 * Internal NOTAM TFR map layer API (airports directory map only).
 *
 * Drawable geometry is cached on disk; status and map colors are revalidated
 * at serve time from per-airport NOTAM caches. HTTP Cache-Control uses
 * {@see NOTAM_API_CACHE_TTL_SECONDS} (shared with api/notam.php); JSON
 * cache_ttl_seconds reflects {@see getNotamCacheTtlSeconds()} for client poll.
 * Production access is limited to browser use from the airport map UI; see
 * {@see notamMapLayerApiRequestIsAllowed()}.
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/constants.php';
require_once __DIR__ . '/../lib/notam/map-api-access.php';
require_once __DIR__ . '/../lib/notam/http-cache-headers.php';
require_once __DIR__ . '/../lib/notam/map-layer-cache.php';

ob_start();
header('Content-Type: application/json; charset=UTF-8');

if (!notamMapLayerApiRequestIsAllowed()) {
    http_response_code(403);
    aviationwx_log('warning', 'notam map layer api: request denied', [
        'sec_fetch_site' => $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '',
        'referer' => isset($_SERVER['HTTP_REFERER']) ? substr((string)$_SERVER['HTTP_REFERER'], 0, 200) : '',
    ], 'app');
    echo json_encode([
        'type' => 'FeatureCollection',
        'features' => [],
        'generated_at' => time(),
        'cache_ttl_seconds' => getNotamCacheTtlSeconds(),
        'error' => 'Forbidden',
    ], JSON_UNESCAPED_SLASHES);
    ob_end_flush();
    exit;
}

try {
    $payload = notamTfrMapLayerServeOrRebuild();
    notamInternalApiSendSharedCacheHeaders();
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    aviationwx_log('error', 'notam map layer api: failure', [
        'error' => $e->getMessage(),
    ], 'app');
    echo json_encode([
        'type' => 'FeatureCollection',
        'features' => [],
        'generated_at' => time(),
        'cache_ttl_seconds' => getNotamCacheTtlSeconds(),
        'error' => 'Map layer unavailable',
    ], JSON_UNESCAPED_SLASHES);
}

ob_end_flush();
