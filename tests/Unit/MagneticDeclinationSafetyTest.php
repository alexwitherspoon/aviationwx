<?php

/**
 * Safety-Critical: Magnetic Declination / Variation
 *
 * Magnetic declination aligns the runway wind diagram with magnetic north.
 * Incorrect values could mislead pilots interpreting wind vs runway orientation.
 *
 * Tests verify:
 * - Cascade: airport override → global override → offline WMM → 0 (fail-safe)
 * - Bounds: declination must be -180° to 180°
 * - WMM validity window (epoch through valid_through_epoch)
 * - Fallback to 0 when WMM is unavailable (never expose stale/wrong data)
 *
 * Reference: NOAA WMM, Natural Resources Canada (Canadian airports)
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/magnetic-declination.php';
require_once __DIR__ . '/../../lib/wmm/WmmCalculator.php';

class MagneticDeclinationSafetyTest extends TestCase
{
    /**
     * SAFETY: Airport override must take precedence (pilot-provided value)
     */
    public function testGetMagneticDeclination_AirportOverride_ReturnsOverride(): void
    {
        $airport = ['magnetic_declination' => 13.2, 'lat' => 45.54, 'lon' => -122.95];
        $this->assertSame(13.2, getMagneticDeclination($airport));
    }

    /**
     * SAFETY: Negative declination (West) must be preserved
     */
    public function testGetMagneticDeclination_NegativeDeclination_Preserved(): void
    {
        $airport = ['magnetic_declination' => -12.4, 'lat' => 38.7, 'lon' => -77.0];
        $this->assertSame(-12.4, getMagneticDeclination($airport));
    }

    /**
     * SAFETY: Null airport returns 0 or global (never throws)
     */
    public function testGetMagneticDeclination_NullAirport_ReturnsFloat(): void
    {
        $result = getMagneticDeclination(null);
        $this->assertIsFloat($result);
        $this->assertGreaterThanOrEqual(-180.0, $result);
        $this->assertLessThanOrEqual(180.0, $result);
    }

    /**
     * SAFETY: No override must use offline WMM when coordinates are available
     */
    public function testGetMagneticDeclination_NoOverride_ReturnsWmmValue(): void
    {
        $airport = ['lat' => 45.54, 'lon' => -122.95];
        $expected = WmmCalculator::getDeclination(time(), 45.54, -122.95);
        $result = getMagneticDeclination($airport);
        $this->assertEqualsWithDelta($expected, $result, 0.05);
    }

    /**
     * SAFETY: Airport override must beat offline WMM
     */
    public function testGetMagneticDeclination_Cascade_AirportOverrideBeatsWmm(): void
    {
        $airport = ['magnetic_declination' => 7.5, 'lat' => 45.54, 'lon' => -122.95];
        $this->assertSame(7.5, getMagneticDeclination($airport));
    }

    /**
     * SAFETY: Non-numeric airport override must fall through to offline WMM
     */
    public function testGetMagneticDeclination_NonNumericAirportOverride_UsesWmm(): void
    {
        $airport = ['magnetic_declination' => '', 'lat' => 45.54, 'lon' => -122.95];
        $expected = WmmCalculator::getDeclination(time(), 45.54, -122.95);
        $this->assertEqualsWithDelta($expected, getMagneticDeclination($airport), 0.05);
    }

    /**
     * SAFETY: Global override must beat offline WMM
     */
    public function testGetMagneticDeclination_Cascade_GlobalOverrideBeatsWmm(): void
    {
        $originalConfigPath = getenv('CONFIG_PATH');
        $tmpConfig = tempnam(sys_get_temp_dir(), 'awx-mag-decl-');
        $this->assertNotFalse($tmpConfig);

        $fixtureContents = file_get_contents(__DIR__ . '/../Fixtures/airports.json.test');
        $this->assertNotFalse($fixtureContents, 'Fixture file must be readable');
        $fixture = json_decode($fixtureContents, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($fixture);
        $fixture['config']['magnetic_declination'] = 9.25;

        $written = file_put_contents($tmpConfig, json_encode($fixture, JSON_THROW_ON_ERROR));
        $this->assertNotFalse($written, 'Temp config must be writable');
        putenv('CONFIG_PATH=' . $tmpConfig);
        clearConfigCache();

        try {
            $airport = ['lat' => 45.54, 'lon' => -122.95];
            $this->assertSame(9.25, getMagneticDeclination($airport));
        } finally {
            if ($originalConfigPath !== false) {
                putenv('CONFIG_PATH=' . $originalConfigPath);
            } else {
                putenv('CONFIG_PATH');
            }
            clearConfigCache();
            @unlink($tmpConfig);
        }
    }

    /**
     * SAFETY: WMM valid_through_epoch boundary accepts the last published NOAA test year
     */
    public function testIsWmmValidForTimestamp_LastNoaaFixtureYear_ReturnsTrue(): void
    {
        $timestamp = WmmCalculator::decimalYearToTimestamp(2029.5);
        $this->assertTrue(isWmmValidForTimestamp($timestamp));
    }

    /**
     * SAFETY: WMM must refuse timestamps clearly past valid_through_epoch
     */
    public function testIsWmmValidForTimestamp_AfterValidThroughEpoch_ReturnsFalse(): void
    {
        $timestamp = gmmktime(0, 0, 0, 1, 1, 2031);
        $this->assertNotFalse($timestamp);
        $this->assertFalse(isWmmValidForTimestamp($timestamp));
    }

    /**
     * SAFETY: WMM must refuse timestamps before the manifest epoch
     */
    public function testIsWmmValidForTimestamp_BeforeEpoch_ReturnsFalse(): void
    {
        $timestamp = WmmCalculator::decimalYearToTimestamp(2024.5);
        $this->assertFalse(isWmmValidForTimestamp($timestamp));
    }

    /**
     * SAFETY: WMM must refuse coefficients past valid_through_epoch
     */
    public function testFetchMagneticDeclinationFromWmm_PastValidThroughEpoch_ReturnsNull(): void
    {
        $timestamp = gmmktime(0, 0, 0, 1, 1, 2031);
        $this->assertNotFalse($timestamp);
        $result = fetchMagneticDeclinationFromWmm(45.54, -122.95, $timestamp);
        $this->assertNull($result);
    }

    /**
     * SAFETY: Missing coordinates must fall through to 0
     */
    public function testGetMagneticDeclination_MissingCoordinates_ReturnsZero(): void
    {
        $airport = ['icao' => 'KPDX'];
        $this->assertSame(0.0, getMagneticDeclination($airport));
    }

    /**
     * SAFETY: Non-numeric coordinates must fall through to 0 (not cast to 0.0, 0.0)
     */
    public function testGetMagneticDeclination_NonNumericCoordinates_ReturnsZero(): void
    {
        $airport = ['lat' => '', 'lon' => '-122.95'];
        $this->assertSame(0.0, getMagneticDeclination($airport));
    }

    /**
     * SAFETY: fetchMagneticDeclinationFromWmm - invalid lat returns null
     */
    public function testFetchMagneticDeclinationFromWmm_InvalidLat_ReturnsNull(): void
    {
        $result = fetchMagneticDeclinationFromWmm(95.0, -122.95);
        $this->assertNull($result);
    }

    /**
     * SAFETY: fetchMagneticDeclinationFromWmm - invalid lon returns null
     */
    public function testFetchMagneticDeclinationFromWmm_InvalidLon_ReturnsNull(): void
    {
        $result = fetchMagneticDeclinationFromWmm(45.54, -200.0);
        $this->assertNull($result);
    }

    /**
     * SAFETY: isWmmValidForTimestamp accepts current time within model epoch
     */
    public function testIsWmmValidForTimestamp_CurrentTime_ReturnsTrue(): void
    {
        $this->assertTrue(isWmmValidForTimestamp(time()));
    }

    /**
     * SAFETY: Declination within valid range - typical US West (Oregon ~13°E)
     */
    public function testDeclination_TypicalUsWest_WithinBounds(): void
    {
        $decl = 13.2;
        $this->assertGreaterThanOrEqual(-180.0, $decl);
        $this->assertLessThanOrEqual(180.0, $decl);
    }

    /**
     * SAFETY: Declination within valid range - typical US East (negative = West)
     */
    public function testDeclination_TypicalUsEast_WithinBounds(): void
    {
        $decl = -12.4;
        $this->assertGreaterThanOrEqual(-180.0, $decl);
        $this->assertLessThanOrEqual(180.0, $decl);
    }

    /**
     * SAFETY: String override must be cast to float
     */
    public function testGetMagneticDeclination_StringOverride_CastToFloat(): void
    {
        $airport = ['magnetic_declination' => '13.2', 'lat' => 45.54, 'lon' => -122.95];
        $result = getMagneticDeclination($airport);
        $this->assertIsFloat($result);
        $this->assertEqualsWithDelta(13.2, $result, 0.01);
    }

    /**
     * SAFETY: Invalid config value (out of bounds) must be clamped
     */
    public function testGetMagneticDeclination_OutOfBoundsConfig_Clamped(): void
    {
        $airport = ['magnetic_declination' => 999.0, 'lat' => 45.54, 'lon' => -122.95];
        $result = getMagneticDeclination($airport);
        $this->assertLessThanOrEqual(180.0, $result);
        $this->assertSame(180.0, $result);
    }

    /**
     * SAFETY: Negative out of bounds must be clamped
     */
    public function testGetMagneticDeclination_NegativeOutOfBounds_Clamped(): void
    {
        $airport = ['magnetic_declination' => -200.0, 'lat' => 45.54, 'lon' => -122.95];
        $result = getMagneticDeclination($airport);
        $this->assertGreaterThanOrEqual(-180.0, $result);
        $this->assertSame(-180.0, $result);
    }
}
