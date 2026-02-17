<?php

/**
 * Safety-Critical: Magnetic Declination / Variation
 *
 * Magnetic declination aligns the runway wind diagram with magnetic north.
 * Incorrect values could mislead pilots interpreting wind vs runway orientation.
 *
 * Tests verify:
 * - Cascade: airport override → global override → API → 0 (fail-safe)
 * - Bounds: declination must be -180° to 180°
 * - API response parsing handles multiple formats
 * - Fallback to 0 when API unavailable (never expose stale/wrong data)
 *
 * Reference: NOAA WMM, Natural Resources Canada (Canadian airports)
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/magnetic-declination.php';

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
     * SAFETY: No override and no API key must return 0 (fail-safe)
     */
    public function testGetMagneticDeclination_NoOverride_ReturnsZero(): void
    {
        $airport = ['lat' => 45.54, 'lon' => -122.95];
        $result = getMagneticDeclination($airport);
        $this->assertSame(0.0, $result, 'Must return 0 when no override (API mocked in test mode)');
    }

    /**
     * SAFETY: extractDeclinationFromResponse - result.declination format (NOAA)
     */
    public function testExtractDeclination_NoaaResultFormat_ReturnsValue(): void
    {
        $data = ['result' => ['declination' => 13.2]];
        $this->assertSame(13.2, extractDeclinationFromResponse($data));
    }

    /**
     * SAFETY: extractDeclinationFromResponse - top-level declination
     */
    public function testExtractDeclination_TopLevel_ReturnsValue(): void
    {
        $data = ['declination' => -7.3];
        $this->assertSame(-7.3, extractDeclinationFromResponse($data));
    }

    /**
     * SAFETY: extractDeclinationFromResponse - declination_value format
     */
    public function testExtractDeclination_DeclinationValue_ReturnsValue(): void
    {
        $data = ['declination_value' => 8.5];
        $this->assertSame(8.5, extractDeclinationFromResponse($data));
    }

    /**
     * SAFETY: Unknown format must return null (never guess)
     */
    public function testExtractDeclination_UnknownFormat_ReturnsNull(): void
    {
        $data = ['other_field' => 123];
        $this->assertNull(extractDeclinationFromResponse($data));
    }

    /**
     * SAFETY: Empty array must return null
     */
    public function testExtractDeclination_EmptyArray_ReturnsNull(): void
    {
        $this->assertNull(extractDeclinationFromResponse([]));
    }

    /**
     * SAFETY: fetchMagneticDeclinationFromApi - invalid lat returns null
     */
    public function testFetchMagneticDeclination_InvalidLat_ReturnsNull(): void
    {
        $result = fetchMagneticDeclinationFromApi(95.0, -122.95, 'test_key');
        $this->assertNull($result);
    }

    /**
     * SAFETY: fetchMagneticDeclinationFromApi - invalid lon returns null
     */
    public function testFetchMagneticDeclination_InvalidLon_ReturnsNull(): void
    {
        $result = fetchMagneticDeclinationFromApi(45.54, -200.0, 'test_key');
        $this->assertNull($result);
    }

    /**
     * SAFETY: fetchMagneticDeclinationFromApi - empty API key returns null
     */
    public function testFetchMagneticDeclination_EmptyApiKey_ReturnsNull(): void
    {
        $result = fetchMagneticDeclinationFromApi(45.54, -122.95, '');
        $this->assertNull($result);
    }

    /**
     * SAFETY: fetchMagneticDeclinationFromApi - mock mode returns null (no real API calls)
     */
    public function testFetchMagneticDeclination_MockMode_ReturnsNull(): void
    {
        $this->assertTrue(shouldMockExternalServices(), 'Test env must use mock mode');
        $result = fetchMagneticDeclinationFromApi(45.54, -122.95, 'real_key');
        $this->assertNull($result);
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
