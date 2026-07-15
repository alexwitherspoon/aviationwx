<?php
/**
 * Runway end heading resolution and wind-based departure end selection for DA performance.
 */

require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../heading-conversion.php';
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

    $parsed = parseIdentHeading($endId);
    if ($parsed < 1 || $parsed > 360) {
        return null;
    }

    return (float) $parsed;
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
