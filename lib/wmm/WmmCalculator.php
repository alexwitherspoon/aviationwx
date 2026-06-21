<?php

declare(strict_types=1);

/**
 * WmmCalculator - Magnetic declination from NOAA World Magnetic Model (WMM).
 *
 * Computes geomagnetic declination on demand using vendored WMM.COF coefficients.
 * Spherical harmonic synthesis follows the NOAA WMM Technical Report (geodetic input).
 *
 * @see https://www.ncei.noaa.gov/products/world-magnetic-model/wmm-coefficients
 */
final class WmmCalculator
{
    private const WGS84_A_KM = 6378.137;
    private const WGS84_B_KM = 6356.7523142;
    private const EARTH_MEAN_RADIUS_KM = 6371.2;

    private static ?WmmCoefficients $bundledCoefficients = null;

    /**
     * Magnetic declination in degrees (positive east) at a Unix timestamp.
     *
     * @param int               $timestamp   Unix timestamp (UTC)
     * @param float             $lat         Geodetic latitude (-90 to 90)
     * @param float             $lon         Geodetic longitude (-180 to 180)
     * @param float             $altitudeKm  Height above WGS-84 ellipsoid in km
     * @param WmmCoefficients|null $coefficients Optional coefficient set (defaults to bundled WMM.COF)
     * @return float Declination in degrees
     * @throws \InvalidArgumentException When coordinates or timestamp are invalid
     */
    public static function getDeclination(
        int $timestamp,
        float $lat,
        float $lon,
        float $altitudeKm = 0.0,
        ?WmmCoefficients $coefficients = null
    ): float {
        return self::getElements($timestamp, $lat, $lon, $altitudeKm, $coefficients)['declination'];
    }

    /**
     * Full geomagnetic elements at a Unix timestamp.
     *
     * @param int               $timestamp   Unix timestamp (UTC)
     * @param float             $lat         Geodetic latitude (-90 to 90)
     * @param float             $lon         Geodetic longitude (-180 to 180)
     * @param float             $altitudeKm  Height above WGS-84 ellipsoid in km
     * @param WmmCoefficients|null $coefficients Optional coefficient set (defaults to bundled WMM.COF)
     * @return array{
     *   declination: float,
     *   inclination: float,
     *   x: float,
     *   y: float,
     *   z: float,
     *   h: float,
     *   f: float
     * }
     * @throws \InvalidArgumentException When coordinates or timestamp are invalid
     */
    public static function getElements(
        int $timestamp,
        float $lat,
        float $lon,
        float $altitudeKm = 0.0,
        ?WmmCoefficients $coefficients = null
    ): array {
        self::validateInputs($timestamp, $lat, $lon, $altitudeKm);

        $coefficients ??= self::getBundledCoefficients();
        $decimalYear = self::timestampToDecimalYear($timestamp);

        return self::calculateElements($lat, $lon, $decimalYear, $altitudeKm, $coefficients);
    }

    /**
     * Magnetic declination for an explicit decimal year.
     *
     * Prefer getDeclination() with a Unix timestamp for production use. This entry point
     * matches NOAA published test-vector format and supports golden tests.
     *
     * @param float             $decimalYear Decimal year (for example 2025.5)
     * @param float             $lat         Geodetic latitude (-90 to 90)
     * @param float             $lon         Geodetic longitude (-180 to 180)
     * @param float             $altitudeKm  Height above WGS-84 ellipsoid in km
     * @param WmmCoefficients|null $coefficients Optional coefficient set (defaults to bundled WMM.COF)
     * @return float Declination in degrees
     * @throws \InvalidArgumentException When coordinates are invalid
     */
    public static function getDeclinationForDecimalYear(
        float $decimalYear,
        float $lat,
        float $lon,
        float $altitudeKm = 0.0,
        ?WmmCoefficients $coefficients = null
    ): float {
        self::validateCoordinates($lat, $lon, $altitudeKm);

        $coefficients ??= self::getBundledCoefficients();

        return self::calculateElements($lat, $lon, $decimalYear, $altitudeKm, $coefficients)['declination'];
    }

    /**
     * Convert Unix timestamp to decimal year (UTC), matching NOAA WMM usage.
     *
     * @param int $timestamp Unix timestamp (must be non-negative)
     * @return float Decimal year
     * @throws \InvalidArgumentException When timestamp is negative
     */
    public static function timestampToDecimalYear(int $timestamp): float
    {
        if ($timestamp < 0) {
            throw new \InvalidArgumentException('Timestamp must be non-negative');
        }

        $year = (int) gmdate('Y', $timestamp);
        $dayOfYear = (int) gmdate('z', $timestamp) + 1;
        $hour = (int) gmdate('G', $timestamp);
        $minute = (int) gmdate('i', $timestamp);
        $second = (int) gmdate('s', $timestamp);
        $isLeapYear = (int) gmdate('L', $timestamp);
        $daysInYear = 365 + $isLeapYear;

        $fractionOfYear = (($dayOfYear - 1) * 86400 + $hour * 3600 + $minute * 60 + $second)
            / ($daysInYear * 86400);

        return $year + $fractionOfYear;
    }

    /**
     * Convert decimal year (UTC) to Unix timestamp, inverse of timestampToDecimalYear().
     *
     * @param float $decimalYear Decimal year (for example 2025.5)
     * @return int Unix timestamp at matching UTC instant within the year
     * @throws \InvalidArgumentException When decimal year is out of range for conversion
     */
    public static function decimalYearToTimestamp(float $decimalYear): int
    {
        $year = (int) floor($decimalYear);
        if ($year < 1970) {
            throw new \InvalidArgumentException('Decimal year must be 1970 or later for timestamp conversion');
        }

        $fraction = $decimalYear - $year;
        if ($fraction < 0.0 || $fraction >= 1.0) {
            throw new \InvalidArgumentException(
                'Decimal year fraction must be in [0, 1); got ' . $decimalYear
            );
        }

        $isLeapYear = ($year % 4 === 0 && ($year % 100 !== 0 || $year % 400 === 0)) ? 1 : 0;
        $daysInYear = 365 + $isLeapYear;
        $maxSeconds = $daysInYear * 86400 - 1;
        $secondsIntoYear = (int) round($fraction * $daysInYear * 86400);
        $secondsIntoYear = max(0, min($maxSeconds, $secondsIntoYear));

        $base = gmmktime(0, 0, 0, 1, 1, $year);
        if ($base === false) {
            throw new \InvalidArgumentException('Failed to convert decimal year to timestamp');
        }

        return $base + $secondsIntoYear;
    }

    private static function getBundledCoefficients(): WmmCoefficients
    {
        if (self::$bundledCoefficients === null) {
            self::$bundledCoefficients = WmmCoefficients::fromBundledPath();
        }

        return self::$bundledCoefficients;
    }

    /**
     * @return array{
     *   declination: float,
     *   inclination: float,
     *   x: float,
     *   y: float,
     *   z: float,
     *   h: float,
     *   f: float
     * }
     */
    private static function calculateElements(
        float $lat,
        float $lon,
        float $decimalYear,
        float $altitudeKm,
        WmmCoefficients $coefficients
    ): array {
        $maxord = $coefficients->getMaxDegree();
        $stride = $coefficients->getHarmonicStride();
        $epoch = $coefficients->getEpoch();
        $dt = $decimalYear - $epoch;

        $g = $coefficients->getG();
        $h = $coefficients->getH();
        $dg = $coefficients->getDg();
        $dh = $coefficients->getDh();
        // Working copy: synthesis updates Legendre terms in snorm during the loop.
        $snorm = $coefficients->getSnorm();
        $k = $coefficients->getK();
        $fn = $coefficients->getFn();
        $fm = $coefficients->getFm();

        $a = self::WGS84_A_KM;
        $b = self::WGS84_B_KM;
        $re = self::EARTH_MEAN_RADIUS_KM;
        $a2 = $a * $a;
        $b2 = $b * $b;
        $c2 = $a2 - $b2;
        $a4 = $a2 * $a2;
        $b4 = $b2 * $b2;
        $c4 = $a4 - $b4;

        $dtr = M_PI / 180.0;
        $rlon = $lon * $dtr;
        $rlat = $lat * $dtr;
        $srlon = sin($rlon);
        $srlat = sin($rlat);
        $crlon = cos($rlon);
        $crlat = cos($rlat);
        $srlat2 = $srlat * $srlat;
        $crlat2 = $crlat * $crlat;

        $sp = array_fill(0, $stride, 0.0);
        $cp = array_fill(0, $stride, 0.0);
        $cp[0] = 1.0;
        $tc = array_fill(0, $stride, array_fill(0, $stride, 0.0));
        $dp = array_fill(0, $stride, array_fill(0, $stride, 0.0));
        $pp = array_fill(0, $stride, 0.0);
        $pp[0] = 1.0;
        $dp[0][0] = 0.0;

        $sp[1] = $srlon;
        $cp[1] = $crlon;

        $q = sqrt($a2 - $c2 * $srlat2);
        $q1 = $altitudeKm * $q;
        $q2 = (($q1 + $a2) / ($q1 + $b2)) ** 2;
        $ct = $srlat / sqrt($q2 * $crlat2 + $srlat2);
        $st = sqrt(1.0 - $ct * $ct);
        $r2 = $altitudeKm * $altitudeKm + 2.0 * $q1 + ($a4 - $c4 * $srlat2) / ($q * $q);
        $r = sqrt($r2);
        $d = sqrt($a2 * $crlat2 + $b2 * $srlat2);
        $ca = ($altitudeKm + $d) / $r;
        $sa = $c2 * $crlat * $srlat / ($r * $d);

        for ($m = 2; $m <= $maxord; $m++) {
            $sp[$m] = $sp[1] * $cp[$m - 1] + $cp[1] * $sp[$m - 1];
            $cp[$m] = $cp[1] * $cp[$m - 1] - $sp[1] * $sp[$m - 1];
        }

        $aor = $re / $r;
        $ar = $aor * $aor;
        $br = 0.0;
        $bt = 0.0;
        $bp = 0.0;
        $bpp = 0.0;

        for ($n = 1; $n <= $maxord; $n++) {
            $ar *= $aor;
            $m = 0;
            while ($m <= $n) {
                if ($n === $m) {
                    $snorm[$n + $m * $stride] = $st * $snorm[$n - 1 + ($m - 1) * $stride];
                    $dp[$m][$n] = $st * $dp[$m - 1][$n - 1] + $ct * $snorm[$n - 1 + ($m - 1) * $stride];
                }
                if ($n === 1 && $m === 0) {
                    $snorm[$n + $m * $stride] = $ct * $snorm[$n - 1 + $m * $stride];
                    $dp[$m][$n] = $ct * $dp[$m][$n - 1] - $st * $snorm[$n - 1 + $m * $stride];
                }
                if ($n > 1 && $n !== $m) {
                    if ($m > $n - 2) {
                        $snorm[$n - 2 + $m * $stride] = 0.0;
                        $dp[$m][$n - 2] = 0.0;
                    }
                    $snorm[$n + $m * $stride] = $ct * $snorm[$n - 1 + $m * $stride]
                        - $k[$m][$n] * $snorm[$n - 2 + $m * $stride];
                    $dp[$m][$n] = $ct * $dp[$m][$n - 1] - $st * $snorm[$n - 1 + $m * $stride]
                        - $k[$m][$n] * $dp[$m][$n - 2];
                }

                $tc[$m][$n] = $g[$m][$n] + $dt * $dg[$m][$n];
                if ($m !== 0) {
                    $tc[$n][$m - 1] = $h[$n][$m - 1] + $dt * $dh[$n][$m - 1];
                }

                $par = $ar * $snorm[$n + $m * $stride];
                if ($m === 0) {
                    $temp1 = $tc[$m][$n] * $cp[$m];
                    $temp2 = $tc[$m][$n] * $sp[$m];
                } else {
                    $temp1 = $tc[$m][$n] * $cp[$m] + $tc[$n][$m - 1] * $sp[$m];
                    $temp2 = $tc[$m][$n] * $sp[$m] - $tc[$n][$m - 1] * $cp[$m];
                }

                $bt -= $ar * $temp1 * $dp[$m][$n];
                $bp += $fm[$m] * $temp2 * $par;
                $br += $fn[$n] * $temp1 * $par;

                if ($st === 0.0 && $m === 1) {
                    if ($n === 1) {
                        $pp[$n] = $pp[$n - 1];
                    } else {
                        $pp[$n] = $ct * $pp[$n - 1] - $k[$m][$n] * $pp[$n - 2];
                    }
                    $parp = $ar * $pp[$n];
                    $bpp += $fm[$m] * $temp2 * $parp;
                }

                $m++;
            }
        }

        if ($st === 0.0) {
            $bp = $bpp;
        } else {
            $bp /= $st;
        }

        $bx = -$bt * $ca - $br * $sa;
        $by = $bp;
        $bz = $bt * $sa - $br * $ca;
        $bh = sqrt($bx * $bx + $by * $by);
        $f = sqrt($bh * $bh + $bz * $bz);
        $declination = rad2deg(atan2($by, $bx));
        $inclination = rad2deg(atan2($bz, $bh));

        return [
            'declination' => $declination,
            'inclination' => $inclination,
            'x' => $bx,
            'y' => $by,
            'z' => $bz,
            'h' => $bh,
            'f' => $f,
        ];
    }

    private static function validateInputs(int $timestamp, float $lat, float $lon, float $altitudeKm): void
    {
        if ($timestamp < 0) {
            throw new \InvalidArgumentException('Timestamp must be non-negative');
        }
        self::validateCoordinates($lat, $lon, $altitudeKm);
    }

    private static function validateCoordinates(float $lat, float $lon, float $altitudeKm): void
    {
        if ($lat < -90 || $lat > 90) {
            throw new \InvalidArgumentException('Latitude must be between -90 and 90');
        }
        if ($lon < -180 || $lon > 180) {
            throw new \InvalidArgumentException('Longitude must be between -180 and 180');
        }
        if ($altitudeKm < -10.0 || $altitudeKm > 1000.0) {
            throw new \InvalidArgumentException('Altitude must be between -10 and 1000 km');
        }
    }
}
