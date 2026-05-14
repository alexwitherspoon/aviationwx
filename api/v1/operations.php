<?php
/**
 * Public API - Operations snapshot
 *
 * GET /v1/operations
 *
 * Returns a scheduler-built production health snapshot (see lib/operations-snapshot.php).
 */

require_once __DIR__ . '/../../lib/public-api/middleware.php';
require_once __DIR__ . '/../../lib/public-api/response.php';
require_once __DIR__ . '/../../lib/public-api/config.php';
require_once __DIR__ . '/../../lib/operations-snapshot.php';

/**
 * Handle GET /v1/operations
 *
 * @param array<int, string> $params Path parameters (unused)
 * @param array<string, mixed> $context Request context from middleware
 * @return void
 */
function handleGetOperations(array $params, array $context): void
{
    unset($params, $context);
    $payload = operations_snapshot_get_api_payload();
    sendPublicApiCacheHeaders('live');
    sendPublicApiSuccess($payload, []);
}
