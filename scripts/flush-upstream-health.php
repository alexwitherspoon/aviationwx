<?php
/**
 * Flush weather and NOTAM upstream health counters to cache files.
 *
 * Usage: php scripts/flush-upstream-health.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/weather-health.php';
require_once __DIR__ . '/../lib/notam-health.php';

$weatherOk = weatherHealthFlush();
$notamOk = notamHealthFlush();
$ok = $weatherOk || $notamOk;

if (!$ok) {
    aviationwx_log('warning', 'flush-upstream-health: weather and NOTAM flush both failed', [], 'app');
}

echo json_encode([
    'weather_ok' => $weatherOk,
    'notam_ok' => $notamOk,
    'success' => $ok,
]) . PHP_EOL;

exit($ok ? 0 : 1);
