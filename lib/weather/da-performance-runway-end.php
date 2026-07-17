<?php
/**
 * Runway end heading resolution and wind-based departure end selection for DA performance.
 */

require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../heading-conversion.php';
require_once __DIR__ . '/../runway-end-ident.php';
require_once __DIR__ . '/../runways.php';

/**
 * Smallest angular difference between two headings (degrees 0-360).
 */
function angularDifference(float $headingA, float $headingB): float
{
    $diff = fmod(abs($headingA - $headingB), 360.0);
    return $diff > 180.0 ? 360.0 - $diff : $diff;
}

/**
 * Resolve magnetic departure heading for a runway end.
 *
 * Precedence: NASR true_alignment (converted to magnetic), config runway headings,
 * then runway end ident parsing.
 *
 * @param array $end Runway end row
 * @param array $runway Selected runway (may include config heading fields)
 * @param array $airport Airport configuration
 * @return float|null Magnetic heading 0-360, or null when unavailable
 */
function resolveRunwayEndMagneticHeading(array $end, array $runway, array $airport): ?float
{
    if (isset($end['true_alignment']) && is_numeric($end['true_alignment'])) {
        return convertTrueToMagnetic((float) $end['true_alignment'], getMagneticDeclination($airport));
    }

    $endId = isset($end['end_id']) ? trim((string) $end['end_id']) : '';
    if ($endId === '') {
        return null;
    }

    $rwyId = (string) ($runway['rwy_id'] ?? '');
    if ($rwyId !== '' && isset($runway['heading_1'], $runway['heading_2'])) {
        $idents = explode('/', $rwyId, 2);
        $leIdent = $idents[0] ?? '';
        $heIdent = $idents[1] ?? $leIdent;
        if (strcasecmp($endId, $leIdent) === 0 && is_numeric($runway['heading_1'])) {
            return (float) $runway['heading_1'];
        }
        if (strcasecmp($endId, $heIdent) === 0 && is_numeric($runway['heading_2'])) {
            return (float) $runway['heading_2'];
        }
    }

    $parsed = parseRunwayEndIdentMagneticHeading($endId);
    if ($parsed === null) {
        return null;
    }

    return $parsed;
}

/**
 * Pick the departure runway end aligned with mean wind (into-wind takeoff).
 *
 * @param array $runway Selected runway with ends[]
 * @param array $airport Airport configuration
 * @param float $windFromMagDeg Mean wind direction (magnetic, meteorological FROM)
 * @return array|null Matched end row with magnetic_heading, or null when no end resolves
 */
function pickDepartureEndByWindFromMagnetic(array $runway, array $airport, float $windFromMagDeg): ?array
{
    $ends = $runway['ends'] ?? [];
    if ($ends === []) {
        return null;
    }

    $bestEnd = null;
    $bestDiff = null;

    foreach ($ends as $end) {
        if (!is_array($end)) {
            continue;
        }
        $heading = resolveRunwayEndMagneticHeading($end, $runway, $airport);
        if ($heading === null) {
            continue;
        }
        $diff = angularDifference($heading, $windFromMagDeg);
        if ($bestDiff === null || $diff < $bestDiff) {
            $bestDiff = $diff;
            $bestEnd = $end;
            $bestEnd['magnetic_heading'] = $heading;
        }
    }

    return $bestEnd;
}

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
 * Find the opposite runway end on the same strip.
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

    return $reciprocal;
}

/**
 * Resolve obstruction ahead on a departure roll from NASR/config approach-side filing.
 *
 * FAA NASR and operator runway_ends file controlling obstacles on the approach side of
 * threshold R (OBSTN_HGT / DIST_FROM_THR on end R). That obstacle lies ahead when
 * departing from the reciprocal end D, at along-track distance runway_length + dist from R.
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
