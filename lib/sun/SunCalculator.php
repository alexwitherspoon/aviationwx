<?php

declare(strict_types=1);

/**
 * SunCalculator - Sunrise, Sunset, and Twilight Times
 *
 * Implements NOAA Solar Calculator formulas for NOAA-aligned accuracy.
 * Primary reference: NOAA GML Solar Calculator (±1 min for ±72°; high latitudes inherently harder).
 *
 * Returns null for polar regions when sun does not rise/set (no data available).
 * Errors are communicated via exceptions, not null.
 *
 * @see https://gml.noaa.gov/grad/solcalc/solareqns.PDF
 * @see docs/DATA_FLOW.md#sun-calculations
 */

class SunCalculator
{
    /** Zenith angle for sunrise/sunset: 90° + 0.833° atmospheric refraction (NOAA standard) */
    private const ZENITH_SUNRISE_SUNSET = 90.833;

    /** Zenith for civil twilight: sun 6° below horizon */
    private const ZENITH_CIVIL = 96.0;

    /** Zenith for nautical twilight: sun 12° below horizon */
    private const ZENITH_NAUTICAL = 102.0;

    /**
     * Get sun rise/set and twilight times for a location and date
     *
     * Returns Unix timestamps (UTC) or null when the event does not occur (polar regions).
     * Structure matches date_sun_info for compatibility.
     *
     * @param int   $timestamp Unix timestamp (used to determine calendar day)
     * @param float $lat       Latitude in degrees (-90 to 90)
     * @param float $lon       Longitude in degrees (-180 to 180)
     * @return array{
     *   sunrise: int|null,
     *   sunset: int|null,
     *   civil_twilight_begin: int|null,
     *   civil_twilight_end: int|null,
     *   nautical_twilight_begin: int|null,
     *   nautical_twilight_end: int|null
     * }
     * @throws \InvalidArgumentException If lat, lon, or timestamp invalid
     */
    public static function getSunInfo(int $timestamp, float $lat, float $lon): array
    {
        if ($timestamp < 0) {
            throw new \InvalidArgumentException('Timestamp must be non-negative');
        }
        if ($lat < -90 || $lat > 90) {
            throw new \InvalidArgumentException('Latitude must be between -90 and 90');
        }
        if ($lon < -180 || $lon > 180) {
            throw new \InvalidArgumentException('Longitude must be between -180 and 180');
        }

        $dayOfYear = (int) gmdate('z', $timestamp) + 1;
        $hour = (int) gmdate('G', $timestamp) + (int) gmdate('i', $timestamp) / 60.0;
        $isLeapYear = (int) gmdate('L', $timestamp);
        $daysInYear = 365 + $isLeapYear;

        $gamma = 2 * M_PI / $daysInYear * ($dayOfYear - 1 + ($hour - 12) / 24);
        $eqtime = self::equationOfTime($gamma);
        $decl = self::solarDeclination($gamma);

        $sunrise = self::riseSetMinutes($lat, $lon, $decl, $eqtime, self::ZENITH_SUNRISE_SUNSET, true, $timestamp);
        $sunset = self::riseSetMinutes($lat, $lon, $decl, $eqtime, self::ZENITH_SUNRISE_SUNSET, false, $timestamp);

        $result = [
            'sunrise' => $sunrise,
            'sunset' => $sunset,
            'civil_twilight_begin' => self::riseSetMinutes($lat, $lon, $decl, $eqtime, self::ZENITH_CIVIL, true, $timestamp),
            'civil_twilight_end' => self::riseSetMinutes($lat, $lon, $decl, $eqtime, self::ZENITH_CIVIL, false, $timestamp),
            'nautical_twilight_begin' => self::riseSetMinutes($lat, $lon, $decl, $eqtime, self::ZENITH_NAUTICAL, true, $timestamp),
            'nautical_twilight_end' => self::riseSetMinutes($lat, $lon, $decl, $eqtime, self::ZENITH_NAUTICAL, false, $timestamp),
        ];

        return $result;
    }

    /**
     * Equation of time in minutes (NOAA formula)
     */
    private static function equationOfTime(float $gamma): float
    {
        return 229.18 * (
            0.000075
            + 0.001868 * cos($gamma)
            - 0.032077 * sin($gamma)
            - 0.014615 * cos(2 * $gamma)
            - 0.040849 * sin(2 * $gamma)
        );
    }

    /**
     * Solar declination in radians (NOAA formula)
     */
    private static function solarDeclination(float $gamma): float
    {
        return 0.006918
            - 0.399912 * cos($gamma)
            + 0.070257 * sin($gamma)
            - 0.006758 * cos(2 * $gamma)
            + 0.000907 * sin(2 * $gamma)
            - 0.002697 * cos(3 * $gamma)
            + 0.00148 * sin(3 * $gamma);
    }

    /**
     * Calculate rise or set time using NOAA formula
     *
     * NOAA: sunrise = 720 - 4*(longitude + ha) - eqtime (minutes from midnight UTC)
     * Positive ha = sunrise, negative ha = sunset
     *
     * @return int|null Unix timestamp or null if no rise/set
     */
    private static function riseSetMinutes(
        float $lat,
        float $lon,
        float $declRad,
        float $eqtime,
        float $zenith,
        bool $rise,
        int $timestamp
    ): ?int {
        $latRad = deg2rad($lat);
        $cosZenith = cos(deg2rad($zenith));
        $denom = cos($latRad) * cos($declRad);
        if (abs($denom) < 1e-10) {
            return null;
        }
        $cosHa = ($cosZenith - sin($latRad) * sin($declRad)) / $denom;

        if ($cosHa > 1.0 || $cosHa < -1.0) {
            return null;
        }

        $haDeg = rad2deg(acos($cosHa));
        $ha = $rise ? $haDeg : -$haDeg;

        $minutesFromMidnight = 720 - 4 * ($lon + $ha) - $eqtime;

        $dayStart = strtotime(gmdate('Y-m-d', $timestamp) . ' 00:00:00 UTC');
        return $dayStart + (int) round($minutesFromMidnight * 60);
    }
}
