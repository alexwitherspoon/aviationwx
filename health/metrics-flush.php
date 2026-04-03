<?php
/**
 * Internal metrics and variant-health flush endpoint
 *
 * Called by the scheduler via localhost so APCu counters are read and persisted in PHP-FPM.
 * CLI cannot see FPM APCu; this script runs in the web worker context.
 *
 * Security: only 127.0.0.1 and ::1 (REMOTE_ADDR is authoritative; not X-Forwarded-For).
 *
 * JSON response shape:
 *
 * **Success (HTTP 200):**
 * - `success` (bool): true only if both flushes succeed.
 * - `timestamp` (int): Unix time.
 * - `results` (object):
 *   - `metrics_flush` (bool)
 *   - `metrics_flush_error` (string|null): set when `metrics_flush` is false; diagnostic code or message
 *     from metrics_get_last_metrics_flush_error(), or `unknown`.
 *   - `variant_health_flush` (bool)
 *
 * **Uncaught exception (HTTP 200 body still returned):**
 * - `success` (bool): false
 * - `timestamp` (int)
 * - `results` (object):
 *   - `error` (string): exception message (same as `flush_endpoint_error`)
 *   - `flush_endpoint_error` (string): use for either metrics or variant_health failure; do not assume
 *     `metrics_flush_error` (that field is only set on the normal path when metrics_flush returns false).
 *
 * @see metrics_flush()
 * @see variant_health_flush()
 */

// Security: Only allow localhost requests
// Note: REMOTE_ADDR is the actual connection IP, not affected by X-Forwarded-For
$remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($remoteAddr, ['127.0.0.1', '::1'])) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Forbidden', 'detail' => 'Localhost only']);
    exit;
}

require_once __DIR__ . '/../lib/metrics.php';
require_once __DIR__ . '/../lib/variant-health.php';

header('Content-Type: application/json');

$results = [];
$success = true;

try {
    // Flush metrics APCu counters to JSON files
    $results['metrics_flush'] = metrics_flush();
    $results['metrics_flush_error'] = $results['metrics_flush']
        ? null
        : (metrics_get_last_metrics_flush_error() ?? 'unknown');

    // Flush variant health APCu counters to cache file
    $results['variant_health_flush'] = variant_health_flush();

    // Overall success requires both to succeed
    $success = $results['metrics_flush'] && $results['variant_health_flush'];

} catch (Throwable $e) {
    $success = false;
    $msg = $e->getMessage();
    $results['error'] = $msg;
    $results['flush_endpoint_error'] = $msg;
}

echo json_encode([
    'success' => $success,
    'results' => $results,
    'timestamp' => time()
]);
