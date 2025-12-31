<?php
/**
 * Internal Metrics Flush Endpoint
 * 
 * Called by scheduler via localhost to flush APCu counters within PHP-FPM context.
 * APCu is process-isolated: CLI scheduler cannot read PHP-FPM's APCu cache.
 * This endpoint runs in PHP-FPM, giving access to the actual metrics counters.
 * 
 * Security: Only accepts requests from localhost (127.0.0.1 or ::1).
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
    
    // Flush variant health APCu counters to cache file
    $results['variant_health_flush'] = variant_health_flush();
    
    // Overall success requires both to succeed
    $success = $results['metrics_flush'] && $results['variant_health_flush'];
    
} catch (Throwable $e) {
    $success = false;
    $results['error'] = $e->getMessage();
}

echo json_encode([
    'success' => $success,
    'results' => $results,
    'timestamp' => time()
]);
