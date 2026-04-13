<?php
/**
 * Internal JSON API: canonical station power cache for the airport dashboard.
 *
 * Read-only: serves data written by `scripts/fetch-station-power.php` (scheduler).
 * Provider-agnostic body (same shape as `cache/station-power/<id>.json`).
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/constants.php';
require_once __DIR__ . '/../lib/rate-limit.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/station-power/station-power-api-handler.php';

// Only execute endpoint logic when called as a web request (not when included for testing)
if (php_sapi_name() !== 'cli' && !empty($_SERVER['REQUEST_METHOD'])) {
    header('Content-Type: application/json; charset=utf-8');

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        header('Allow: GET');
        $out = stationPowerApiEncodeJson(['success' => false, 'error' => 'Method not allowed']);
        echo $out ?? '{"success":false,"error":"Method not allowed"}';
        exit;
    }

    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Request-ID: ' . aviationwx_get_request_id());

    if (!checkRateLimit('station_power_api', RATE_LIMIT_STATION_POWER_MAX, RATE_LIMIT_STATION_POWER_WINDOW)) {
        http_response_code(429);
        header('Retry-After: ' . RATE_LIMIT_STATION_POWER_WINDOW);
        $out = stationPowerApiEncodeJson(['success' => false, 'error' => 'Too many requests. Please try again later.']);
        echo $out ?? '{"success":false,"error":"Too many requests. Please try again later."}';
        exit;
    }

    [$httpCode, $body] = stationPowerApiBuildResponse(isset($_GET['airport']) ? (string) $_GET['airport'] : '');

    $json = stationPowerApiEncodeJson($body);
    if ($json === null) {
        aviationwx_log('error', 'station_power api json_encode failed', [
            'http_code' => $httpCode,
            'message' => json_last_error_msg(),
        ], 'app');
        http_response_code(HTTP_STATUS_SERVICE_UNAVAILABLE);
        echo '{"success":false,"error":"Internal error"}';
        exit;
    }

    http_response_code($httpCode);
    echo $json;
    exit;
}
