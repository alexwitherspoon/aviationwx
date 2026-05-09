<?php
/**
 * Refresh status-bundle APCu mirror from disk (localhost only).
 *
 * Invalidates the mirror then runs metrics_get_status_bundle(), which rebuilds from JSON files
 * and re-stores APCu. Called by the scheduler after spill merges so the status page stays hot.
 */

declare(strict_types=1);

$remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($remoteAddr, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Forbidden', 'detail' => 'Localhost only']);
    exit;
}

require_once __DIR__ . '/../lib/metrics.php';

header('Content-Type: application/json');

try {
    metrics_invalidate_status_bundle_mirror();
    metrics_get_status_bundle();

    echo json_encode([
        'success' => true,
        'timestamp' => time(),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => time(),
    ]);
}
