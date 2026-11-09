<?php

/**
 * Runway end ident parsing shared by config validation and DA performance.
 */

/**
 * Canonicalize a runway end ident to two-digit number plus optional L/C/R suffix.
 *
 * @return string|null Canonical ident (e.g. 09, 09L) or null when invalid
 */
function canonicalizeRunwayEndIdent(string $endId): ?string
{
    $trimmed = trim($endId);
    if ($trimmed === '' || !preg_match('/^(\d{1,2})([LCR])?$/i', $trimmed, $matches)) {
        return null;
    }

    $num = (int) $matches[1];
    if ($num < 1 || $num > 36) {
        return null;
    }

    $suffix = isset($matches[2]) ? strtoupper($matches[2]) : '';

    return sprintf('%02d%s', $num, $suffix);
}

/**
 * Magnetic heading for a cardinal runway end ident (N, NE, SW, ...).
 *
 * OurAirports and NASR occasionally name a runway end by compass point instead
 * of a numeric designator, for example a private strip published as N/S or
 * NE/SW. Cardinal points are already magnetic, so the mapping is fixed and
 * needs no declination correction.
 *
 * @param string $endId Runway end ident (e.g. N, ne, SW)
 * @return int|null Heading in degrees, or null when not a cardinal direction
 */
function parseCardinalRunwayEndHeading(string $endId): ?int
{
    $headingByDirection = [
        'N' => 360,
        'NE' => 45,
        'E' => 90,
        'SE' => 135,
        'S' => 180,
        'SW' => 225,
        'W' => 270,
        'NW' => 315,
    ];

    $direction = strtoupper(trim($endId));

    return $headingByDirection[$direction] ?? null;
}

/**
 * Whether two cardinal runway end idents are opposite ends of the same strip.
 *
 * Cardinal runways come in reciprocal pairs (N/S, NE/SW, E/W, NW/SE). Any
 * other pairing, such as N/E or a repeated N/N, is malformed and must be
 * dropped rather than drawn as a bogus compass line.
 *
 * @param string $endIdA First cardinal end ident (e.g. N, NE)
 * @param string $endIdB Second cardinal end ident (e.g. S, SW)
 * @return bool True when both idents are cardinal and 180° apart
 */
function cardinalRunwayEndIdentsAreReciprocal(string $endIdA, string $endIdB): bool
{
    $headingA = parseCardinalRunwayEndHeading($endIdA);
    $headingB = parseCardinalRunwayEndHeading($endIdB);

    return $headingA !== null && $headingB !== null && abs($headingA - $headingB) === 180;
}

/**
 * Parse a runway end ident into magnetic heading degrees.
 *
 * Cardinal direction idents resolve to their magnetic bearing, numeric idents
 * resolve to their runway number times ten, and any other ident returns null.
 * Unlike parseIdentHeading(), does not treat invalid idents as 360° north.
 *
 * @param string $endId Runway end ident, numeric (09, 28L) or cardinal (N, NE)
 * @return float|null Magnetic heading in degrees, or null when not numeric or cardinal
 */
function parseRunwayEndIdentMagneticHeading(string $endId): ?float
{
    $cardinal = parseCardinalRunwayEndHeading($endId);
    if ($cardinal !== null) {
        return (float) $cardinal;
    }

    $canonical = canonicalizeRunwayEndIdent($endId);
    if ($canonical === null) {
        return null;
    }

    if (!preg_match('/^(\d{2})/', $canonical, $matches)) {
        return null;
    }

    return (float) ((int) $matches[1] * 10);
}

/**
 * Whether two runway end idents are opposite ends of the same strip.
 *
 * Requires canonical numeric designators that differ by 18 (1-36) and matching L/C/R suffixes.
 */
function runwayEndIdentsAreReciprocal(string $endIdA, string $endIdB): bool
{
    $canonicalA = canonicalizeRunwayEndIdent($endIdA);
    $canonicalB = canonicalizeRunwayEndIdent($endIdB);
    if ($canonicalA === null || $canonicalB === null || strcasecmp($canonicalA, $canonicalB) === 0) {
        return false;
    }

    if (!preg_match('/^(\d{2})([LCR])?$/', $canonicalA, $matchesA)
        || !preg_match('/^(\d{2})([LCR])?$/', $canonicalB, $matchesB)) {
        return false;
    }

    $suffixA = $matchesA[2] ?? '';
    $suffixB = $matchesB[2] ?? '';
    if ($suffixA !== $suffixB) {
        return false;
    }

    $numA = (int) $matchesA[1];
    $numB = (int) $matchesB[1];
    $reciprocalOfA = (($numA + 17) % 36) + 1;

    return $reciprocalOfA === $numB;
}

/**
 * Whether a runway row has exactly two reciprocal ends.
 *
 * @param array $runway Runway with ends[]
 */
function runwayHasReciprocalEndPair(array $runway): bool
{
    $canonicalIds = [];
    foreach ($runway['ends'] ?? [] as $end) {
        if (!is_array($end)) {
            continue;
        }

        $endId = isset($end['end_id']) ? trim((string) $end['end_id']) : '';
        if ($endId === '') {
            continue;
        }

        $canonical = canonicalizeRunwayEndIdent($endId);
        if ($canonical === null) {
            continue;
        }

        $canonicalIds[] = $canonical;
    }

    if (count($canonicalIds) !== 2) {
        return false;
    }

    return runwayEndIdentsAreReciprocal($canonicalIds[0], $canonicalIds[1]);
}
