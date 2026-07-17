<?php
/**
 * Departure obstruction mapping for density altitude performance.
 */

require_once __DIR__ . '/../runway-end-ident.php';

/**
 * Whether an obstruction block has usable height and distance for POH stress.
 *
 * @param array $obstruction Parsed obstruction row
 */
function runwayObstructionIsUsable(array $obstruction): bool
{
    if ($obstruction === []) {
        return false;
    }

    $hgt = $obstruction['hgt_ft'] ?? null;
    $dist = $obstruction['dist_ft'] ?? null;

    return is_numeric($hgt) && is_numeric($dist) && (float) $hgt > 0 && (float) $dist > 0;
}

/**
 * Reciprocal runway end for approach-side obstruction lookup.
 *
 * Requires exactly two reciprocal ends; asymmetric strips fail closed (no reciprocal stress).
 *
 * @param array $departureEnd Runway end row being scored for departure
 * @param array $runway Selected runway with ends[]
 * @return array|null Reciprocal end row or null when unavailable
 */
function findReciprocalRunwayEnd(array $departureEnd, array $runway): ?array
{
    $departureId = isset($departureEnd['end_id']) ? trim((string) $departureEnd['end_id']) : '';
    if ($departureId === '') {
        return null;
    }

    $candidates = [];
    foreach ($runway['ends'] ?? [] as $end) {
        if (!is_array($end)) {
            continue;
        }

        $endId = isset($end['end_id']) ? trim((string) $end['end_id']) : '';
        if ($endId === '') {
            continue;
        }

        $candidates[] = $end;
    }

    if (count($candidates) !== 2) {
        return null;
    }

    $reciprocal = null;
    $matchedDeparture = false;
    foreach ($candidates as $end) {
        $endId = trim((string) $end['end_id']);
        if (strcasecmp($endId, $departureId) === 0) {
            $matchedDeparture = true;
            continue;
        }

        $reciprocal = $end;
    }

    if (!$matchedDeparture || $reciprocal === null) {
        return null;
    }

    if (!runwayEndIdentsAreReciprocal($departureId, (string) $reciprocal['end_id'])) {
        return null;
    }

    return $reciprocal;
}

/**
 * Resolve obstruction ahead on a departure roll from NASR/config approach-side filing.
 *
 * FAA NASR and operator runway_ends file controlling obstacles on the approach side of
 * threshold R (OBSTN_HGT / DIST_FROM_THR on end R). That obstacle lies ahead when
 * departing from the reciprocal end D, at along-track distance
 * `(runway_length - departure_displaced_thr_len) + dist_from_R` when the departure
 * end publishes a displaced threshold, otherwise `runway_length + dist_from_R`.
 *
 * @param array $departureEnd Runway end row scored for departure
 * @param array $runway Selected runway with length_ft and ends[]
 * @return array{
 *     type: ?string,
 *     hgt_ft: ?float,
 *     dist_ft: ?float,
 *     slope: ?float,
 *     source_end_id: ?string
 * }
 */
function resolveDepartureObstructionForEnd(array $departureEnd, array $runway): array
{
    $empty = [
        'type' => null,
        'hgt_ft' => null,
        'dist_ft' => null,
        'slope' => null,
        'source_end_id' => null,
    ];

    $reciprocal = findReciprocalRunwayEnd($departureEnd, $runway);
    if ($reciprocal === null) {
        return $empty;
    }

    $raw = is_array($reciprocal['obstruction'] ?? null) ? $reciprocal['obstruction'] : [];
    if (!runwayObstructionIsUsable($raw)) {
        return $empty;
    }

    $runwayLen = (int) ($runway['length_ft'] ?? 0);
    if ($runwayLen <= 0) {
        return $empty;
    }

    $departureDisplaced = isset($departureEnd['displaced_thr_len']) && is_numeric($departureEnd['displaced_thr_len'])
        ? max(0, (int) round((float) $departureEnd['displaced_thr_len']))
        : 0;
    $alongTrackFt = max(0, $runwayLen - $departureDisplaced) + (float) $raw['dist_ft'];

    $result = [
        'type' => isset($raw['type']) && is_string($raw['type']) ? $raw['type'] : null,
        'hgt_ft' => (float) $raw['hgt_ft'],
        'dist_ft' => $alongTrackFt,
        'slope' => null,
        'source_end_id' => isset($reciprocal['end_id']) ? (string) $reciprocal['end_id'] : null,
    ];

    if (isset($raw['slope']) && is_numeric($raw['slope']) && (float) $raw['slope'] > 0) {
        $result['slope'] = (float) $raw['slope'];
    }

    return $result;
}
