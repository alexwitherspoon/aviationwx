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
 * Parse a runway end ident into magnetic heading degrees.
 *
 * Returns null when the ident is not a valid runway number (1-36, optional L/C/R).
 * Unlike parseIdentHeading(), does not treat invalid idents as 360° north.
 */
function parseRunwayEndIdentMagneticHeading(string $endId): ?float
{
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
