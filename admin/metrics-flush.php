<?php
/**
 * Internal Metrics Flush Endpoint
 * 
 * Called by scheduler via localhost to flush APCu counters within PHP-FPM context.
 * APCu is process-isolated: CLI scheduler cannot read PHP-FPM's APCu cache.
 * This endpoint runs in PHP-FPM, giving access to the actual metrics counters.
 * 
 * Security: Only accepts requests from localhost.
 */

// Security: Only allow localhost requests
$remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($remoteAddr, ['127.0.0.1', '::1', 'localhost'])) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Forbidden', 'detail' => 'Localhost only']);
    exit;
}

require_once __DIR__ . '/../lib/metrics.php';
require_once __DIR__ . '/../lib/variant-health.php';

header('Content-Type: application/json');

$results = [];

// Flush metrics
$metricsResult = metrics_flush();
$results['metrics_flush'] = $metricsResult;

// Flush variant health
$variantResult = variant_health_flush();
$results['variant_health_flush'] = $variantResult;

// Return result
echo json_encode([
    'success' => $metricsResult,
    'results' => $results,
    'timestamp' => time()
]);

