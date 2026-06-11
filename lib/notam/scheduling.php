<?php

declare(strict_types=1);

/**
 * NOTAM refresh timing helpers (stagger offsets, no cache I/O).
 */

require_once __DIR__ . '/../constants.php';

/**
 * Per-airport offset within the refresh window (stable from airport id).
 *
 * @param string $airportId Airport config key
 * @param int $refreshInterval Configured refresh interval in seconds
 * @return int Additional seconds of cache age required before enqueue
 */
function notamStaggerOffsetSeconds(string $airportId, int $refreshInterval): int
{
    $slots = max(1, (int) ($refreshInterval / NOTAM_SCHEDULER_STAGGER_WINDOW_FRACTION));
    $hash = sprintf('%u', crc32(strtolower(trim($airportId))));

    return (int) ($hash % $slots);
}

/**
 * Minimum cache age before this airport is eligible for a scheduler refresh.
 *
 * @param string $airportId Airport config key
 * @param int $refreshInterval Configured refresh interval in seconds
 * @return int Required cache age in seconds (interval + stagger)
 */
function notamRequiredCacheAgeSeconds(string $airportId, int $refreshInterval): int
{
    return $refreshInterval + notamStaggerOffsetSeconds($airportId, $refreshInterval);
}

/**
 * Maximum NOTAM worker jobs to start per scheduler loop iteration.
 */
function notamSchedulerMaxEnqueuePerLoop(): int
{
    return NOTAM_SCHEDULER_MAX_ENQUEUE_PER_LOOP;
}
