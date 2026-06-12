<?php

declare(strict_types=1);

/**
 * NOTAM scheduler queue helpers - spread low-urgency refreshes across time.
 */

require_once __DIR__ . '/scheduling.php';
require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/circuit-breaker.php';

/**
 * Choose airports to refresh this scheduler tick (oldest cache first, capped).
 *
 * @param list<string> $airportIds Enabled airport config keys to consider
 * @param int $refreshInterval Configured refresh interval in seconds
 * @param int $maxEnqueue Maximum jobs to return
 * @param int|null $now Optional Unix timestamp for tests
 * @return list<string> Airport ids to pass to the NOTAM worker pool
 */
function notamSelectAirportsToEnqueue(
    array $airportIds,
    int $refreshInterval,
    int $maxEnqueue,
    ?int $now = null
): array {
    if ($maxEnqueue <= 0 || $airportIds === []) {
        return [];
    }

    $now = $now ?? time();
    if (checkNotamGlobalBackoff($now)['skip']) {
        return [];
    }

    $eligible = [];

    foreach ($airportIds as $airportId) {
        if (!is_string($airportId) || $airportId === '') {
            continue;
        }
        if (!notamShouldEnqueueRefresh($airportId, $refreshInterval, $now)) {
            continue;
        }

        $cacheFile = notamCacheFilePath($airportId);
        $cacheMtime = is_file($cacheFile) ? (int) filemtime($cacheFile) : 0;
        $eligible[] = [
            'id' => $airportId,
            'mtime' => $cacheMtime,
        ];
    }

    usort($eligible, static function (array $a, array $b): int {
        return $a['mtime'] <=> $b['mtime'];
    });

    return array_slice(array_column($eligible, 'id'), 0, $maxEnqueue);
}
