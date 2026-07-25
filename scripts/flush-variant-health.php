<?php
/**
 * Flush variant-health APCu counters via PHP-FPM HTTP (CLI cannot see FPM APCu).
 *
 * Usage: php scripts/flush-variant-health.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/metrics.php';

$ok = variant_health_flush_via_http();
echo json_encode(['success' => $ok]) . PHP_EOL;
exit($ok ? 0 : 1);
