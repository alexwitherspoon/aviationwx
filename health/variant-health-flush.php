<?php
/**
 * Internal variant-health flush endpoint (localhost only).
 *
 * The scheduler calls this over HTTP so APCu counters are updated in PHP-FPM.
 * Metrics hourly aggregation uses spill files merged by scripts/aggregate-metrics-spills.php.
 *
 * @see variant_health_flush()
 */

declare(strict_types=1);

$remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($remoteAddr, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Forbidden', 'detail' => 'Localhost only']);
    exit;
}

require_once __DIR__ . '/../lib/variant-health.php';

header('Content-Type: application/json');

$results = [];
$success = true;

try {
    $results['variant_health_flush'] = variant_health_flush();
    $success = (bool) $results['variant_health_flush'];
} catch (Throwable $e) {
    $success = false;
    $msg = $e->getMessage();
    $results['error'] = $msg;
    $results['flush_endpoint_error'] = $msg;
}

echo json_encode([
    'success' => $success,
    'results' => $results,
    'timestamp' => time(),
]);
