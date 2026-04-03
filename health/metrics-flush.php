<?php
/**
 * Internal metrics and variant-health flush endpoint (localhost only).
 *
 * The scheduler calls this over HTTP so APCu counters are read in PHP-FPM. CLI cannot see FPM APCu.
 * Response JSON is documented in `docs/OPERATIONS.md` (Metrics System, internal flush endpoint).
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
