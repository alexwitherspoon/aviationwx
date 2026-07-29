<?php
/**
 * Shared airspace identity helpers (no store I/O).
 */

declare(strict_types=1);

/** @var string Source type written into field_sources for NMS-ingested rows */
const NOTAM_AIRSPACE_SOURCE_NMS = 'nms';

/** Bump when AirspaceAggregator merge preference or capability recompute rules change. */
const NOTAM_AIRSPACE_MERGE_LOGIC_VERSION = 1;

/**
 * Extract normalized NOTAM number bucket key from a public id.
 *
 * @param string $notamId Public NOTAM id (e.g. A3389/2026, 2698/2026, 6/0543)
 * @return string|null Bucket such as N:3389, or null when not parseable
 */
function notamAirspaceNormNumberFromId(string $notamId): ?string
{
    $notamId = trim($notamId);
    if ($notamId === '') {
        return null;
    }

    // NMS public ids: A3389/2026 or 2698/2026 (calendar year 20xx)
    if (preg_match('/^([A-Za-z]?\d+)\/(20\d{2})$/', $notamId, $matches) === 1) {
        if (preg_match('/(\d+)/', $matches[1], $numberMatch) !== 1) {
            return null;
        }

        return 'N:' . (int) $numberMatch[1];
    }

    // WFS NOTAM_KEY / short form: 6/0543 or 6/0543-1-FDC-F (single-digit year series)
    if (preg_match('/^(\d)\/(\d+)(?:-|$)/', $notamId, $wfsMatches) === 1) {
        return 'N:' . (int) $wfsMatches[2];
    }

    return null;
}
