<?php

/**
 * Runway end ident parsing shared by config validation and DA performance.
 */

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
