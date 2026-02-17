<?php
/**
 * Heading Conversion - True North ↔ Magnetic North
 *
 * SAFETY-CRITICAL: Single source of truth for all heading/direction conversions.
 * Used by runway wind diagram, wind display, and weather ingest normalization.
 *
 * Convention: Declination positive = East (magnetic north east of true north).
 * - True = Magnetic + Declination
 * - Magnetic = True - Declination
 *
 * All angles in degrees 0-360. Coordinate system: North = +y, East = +x.
 *
 * @package AviationWX
 */

/**
 * Convert magnetic heading to true heading
 *
 * Formula: True = Magnetic + Declination
 *
 * @param float $magneticDegrees Heading in magnetic (0-360)
 * @param float $declinationDegrees Declination (positive=East)
 * @return float Heading in true north (0-360)
 */
function convertMagneticToTrue(float $magneticDegrees, float $declinationDegrees): float
{
    $result = fmod($magneticDegrees + $declinationDegrees + 360, 360);
    return $result === 0.0 ? 360.0 : $result;
}

/**
 * Convert true heading to magnetic heading
 *
 * Formula: Magnetic = True - Declination
 *
 * @param float $trueDegrees Heading in true north (0-360)
 * @param float $declinationDegrees Declination (positive=East)
 * @return float Heading in magnetic (0-360)
 */
function convertTrueToMagnetic(float $trueDegrees, float $declinationDegrees): float
{
    $result = fmod($trueDegrees - $declinationDegrees + 360, 360);
    return $result === 0.0 ? 360.0 : $result;
}

/**
 * Rotate a 2D point from true north to magnetic north frame
 *
 * Used for runway segments: coordinates in true north (x=East, y=North)
 * are rotated so they align with magnetic compass (N at top = magnetic north).
 *
 * Rotation: +declination (clockwise when declination > 0).
 *
 * @param float $x East component (-1 to 1)
 * @param float $y North component (-1 to 1)
 * @param float $declinationDegrees Declination (positive=East)
 * @return array{x: float, y: float} Rotated point
 */
function rotatePointTrueToMagnetic(float $x, float $y, float $declinationDegrees): array
{
    $rad = ($declinationDegrees * M_PI) / 180;
    $cosD = cos($rad);
    $sinD = sin($rad);
    return [
        'x' => $x * $cosD - $y * $sinD,
        'y' => $x * $sinD + $y * $cosD,
    ];
}
