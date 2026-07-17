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
 * Parse a runway end ident into magnetic heading degrees.
 *
 * Returns null when the ident is not a valid runway number (1-36, optional L/C/R).
 * Unlike parseIdentHeading(), does not treat invalid idents as 360° north.
 */
function parseRunwayEndIdentMagneticHeading(string $endId): ?float
{
    $trimmed = trim($endId);
    if ($trimmed === '' || !preg_match('/^(\d{1,2})([LCR])?$/i', $trimmed, $matches)) {
        return null;
    }

    $num = (int) $matches[1];
    if ($num < 1 || $num > 36) {
        return null;
    }

    return (float) ($num * 10);
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
 * Pick the departure end aligned with mean wind across all performance runways.
 *
 * @param list<array> $runways Selected runways with ends[]
 * @param array $airport Airport configuration
 * @param float $windFromMagDeg Mean wind direction (magnetic, meteorological FROM)
 * @return array|null Matched end row with magnetic_heading and rwy_id, or null when no end resolves
 */
function pickDepartureEndByWindAcrossRunways(array $runways, array $airport, float $windFromMagDeg): ?array
{
    $bestEnd = null;
    $bestDiff = null;

    foreach ($runways as $runway) {
        if (!is_array($runway)) {
            continue;
        }

        $picked = pickDepartureEndByWindFromMagnetic($runway, $airport, $windFromMagDeg);
        if ($picked === null) {
            continue;
        }

        $heading = (float) ($picked['magnetic_heading'] ?? 0.0);
        $diff = angularDifference($heading, $windFromMagDeg);
        if ($bestDiff === null || $diff < $bestDiff) {
            $bestDiff = $diff;
            $bestEnd = $picked;
            $bestEnd['rwy_id'] = isset($runway['rwy_id']) ? (string) $runway['rwy_id'] : null;
        }
    }

    return $bestEnd;
}

/**
 * Find a performance runway record by published runway id.
 *
 * @param list<array> $runways Selected runways
 */
function findPerformanceRunwayById(array $runways, ?string $rwyId): ?array
{
    if ($rwyId === null || $rwyId === '') {
        return null;
    }

    foreach ($runways as $runway) {
        if (!is_array($runway)) {
            continue;
        }
        if ((string) ($runway['rwy_id'] ?? '') === $rwyId) {
            return $runway;
        }
    }

    return null;
}
