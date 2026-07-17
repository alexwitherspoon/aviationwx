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
