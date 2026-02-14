<?php
/**
 * Unit Tests for parseRawMETARToWeatherArray()
 *
 * Safety-critical: Raw METAR parsing is used by AWOSnet and other sources.
 * Tests cover division-by-zero guards, VRB wind, visibility fractions,
 * cloud cover, and malformed input handling.
 *
 * @package AviationWX\Tests\Unit
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/weather/adapter/metar-v1.php';

class RawMetarParsingTest extends TestCase
{
    /**
     * Division-by-zero: malformed "1 1/0SM" should not cause error
     * Uses whole number part when denominator is zero
     */
    public function testVisibility_MalformedFraction_ZeroDenominator_WholePart_NoDivisionByZero(): void
    {
        $metar = 'METAR KSPB 132345Z 09007KT 1 1/0SM CLR 05/05 A3003';
        $result = parseRawMETARToWeatherArray($metar);
        $this->assertIsArray($result);
        $this->assertEquals(1.0, $result['visibility'], 'Should use whole part when denominator is zero');
    }

    /**
     * Division-by-zero: malformed "1/0SM" should not cause error
     * Returns null when fraction has zero denominator
     */
    public function testVisibility_MalformedFraction_ZeroDenominatorOnly_ReturnsNull(): void
    {
        $metar = 'METAR KSPB 132345Z 09007KT 1/0SM CLR 05/05 A3003';
        $result = parseRawMETARToWeatherArray($metar);
        $this->assertIsArray($result);
        $this->assertNull($result['visibility'], 'Should return null for 1/0SM');
    }

    /**
     * Valid visibility fraction: 1 1/2SM = 1.5 SM
     */
    public function testVisibility_ValidFraction_OneAndHalf_ReturnsCorrectValue(): void
    {
        $metar = 'METAR KSPB 132345Z 09007KT 1 1/2SM CLR 05/05 A3003';
        $result = parseRawMETARToWeatherArray($metar);
        $this->assertIsArray($result);
        $this->assertEqualsWithDelta(1.5, $result['visibility'], 0.01);
    }

    /**
     * Valid visibility fraction: 1/2SM = 0.5 SM
     */
    public function testVisibility_ValidFraction_Half_ReturnsCorrectValue(): void
    {
        $metar = 'METAR KSPB 132345Z 09007KT 1/2SM CLR 05/05 A3003';
        $result = parseRawMETARToWeatherArray($metar);
        $this->assertIsArray($result);
        $this->assertEqualsWithDelta(0.5, $result['visibility'], 0.01);
    }

    /**
     * Valid whole number visibility: 10SM
     */
    public function testVisibility_WholeNumber_10SM_Returns10(): void
    {
        $metar = 'METAR KSPB 132345Z 09007KT 10SM CLR 05/05 A3003';
        $result = parseRawMETARToWeatherArray($metar);
        $this->assertIsArray($result);
        $this->assertEquals(10.0, $result['visibility']);
        $this->assertFalse($result['visibility_greater_than'] ?? false, 'Plain 10SM has no greater-than semantics');
    }

    /**
     * P6SM: METAR "greater than" prefix - visibility is at least 6 SM
     * Per ICAO METAR format, P prefix indicates value exceeds the reported number.
     */
    public function testVisibility_P6SM_GreaterThan_SetsFlagAndValue(): void
    {
        $metar = 'METAR KSPB 132345Z 09007KT P6SM CLR 05/05 A3003';
        $result = parseRawMETARToWeatherArray($metar);
        $this->assertIsArray($result);
        $this->assertEquals(6.0, $result['visibility']);
        $this->assertTrue($result['visibility_greater_than'] ?? false, 'P6SM must set visibility_greater_than');
    }

    /**
     * P10SM: greater than 10 SM (common for unlimited conditions)
     */
    public function testVisibility_P10SM_GreaterThan_SetsFlagAndValue(): void
    {
        $metar = 'METAR KSPB 132345Z 09007KT P10SM CLR 05/05 A3003';
        $result = parseRawMETARToWeatherArray($metar);
        $this->assertIsArray($result);
        $this->assertEquals(10.0, $result['visibility']);
        $this->assertTrue($result['visibility_greater_than'] ?? false);
    }

    /**
     * 6SM without P: exact value, no greater-than flag
     */
    public function testVisibility_6SM_NoPrefix_NoGreaterThanFlag(): void
    {
        $metar = 'METAR KSPB 132345Z 09007KT 6SM CLR 05/05 A3003';
        $result = parseRawMETARToWeatherArray($metar);
        $this->assertIsArray($result);
        $this->assertEquals(6.0, $result['visibility']);
        $this->assertFalse($result['visibility_greater_than'] ?? false);
    }

    /**
     * P prefix must be checked before plain number - P6SM must not match (\d+)SM first
     */
    public function testVisibility_P6SM_OrderOfRegex_PrefixTakesPrecedence(): void
    {
        $metar = 'METAR KSPB 132345Z 09007KT P6SM CLR 05/05 A3003';
        $result = parseRawMETARToWeatherArray($metar);
        $this->assertTrue($result['visibility_greater_than'] ?? false, 'P6SM must be parsed with P prefix, not as plain 6SM');
    }

    /**
     * VRB wind: variable wind direction should be preserved as 'VRB'
     */
    public function testWind_VRB_VariableWind_PreservesVRB(): void
    {
        $metar = 'METAR KSPB 132345Z VRB05KT 10SM CLR 05/05 A3003';
        $result = parseRawMETARToWeatherArray($metar);
        $this->assertIsArray($result);
        $this->assertEquals('VRB', $result['wind_direction']);
        $this->assertEquals(5, $result['wind_speed']);
    }

    /**
     * Normal wind: numeric direction
     */
    public function testWind_NumericDirection_ReturnsValues(): void
    {
        $metar = 'METAR KSPB 132345Z 09007KT 10SM CLR 05/05 A3003';
        $result = parseRawMETARToWeatherArray($metar);
        $this->assertIsArray($result);
        $this->assertEquals(90, $result['wind_direction']);
        $this->assertEquals(7, $result['wind_speed']);
    }

    /**
     * Wind with gust: dddffGggKT
     */
    public function testWind_WithGust_ParsesGustSpeed(): void
    {
        $metar = 'METAR KSPB 132345Z 09007G15KT 10SM CLR 05/05 A3003';
        $result = parseRawMETARToWeatherArray($metar);
        $this->assertIsArray($result);
        $this->assertEquals(7, $result['wind_speed']);
        $this->assertEquals(15, $result['gust_speed']);
    }

    /**
     * Cloud cover: BKN sets ceiling
     */
    public function testCloudCover_BKN_SetsCeilingAndCover(): void
    {
        $metar = 'METAR KSPB 132345Z 09007KT 10SM BKN030 05/05 A3003';
        $result = parseRawMETARToWeatherArray($metar);
        $this->assertIsArray($result);
        $this->assertEquals('BKN', $result['cloud_cover']);
        $this->assertEquals(3000, $result['ceiling']);
    }

    /**
     * Cloud cover: OVC sets ceiling
     */
    public function testCloudCover_OVC_SetsCeilingAndCover(): void
    {
        $metar = 'METAR KSPB 132345Z 09007KT 10SM OVC100 05/05 A3003';
        $result = parseRawMETARToWeatherArray($metar);
        $this->assertIsArray($result);
        $this->assertEquals('OVC', $result['cloud_cover']);
        $this->assertEquals(10000, $result['ceiling']);
    }

    /**
     * Cloud cover: FEW/SCT do not set ceiling
     */
    public function testCloudCover_FEW_NoCeiling(): void
    {
        $metar = 'METAR KSPB 132345Z 09007KT 10SM FEW015 05/05 A3003';
        $result = parseRawMETARToWeatherArray($metar);
        $this->assertIsArray($result);
        $this->assertEquals('FEW', $result['cloud_cover']);
        $this->assertNull($result['ceiling']);
    }

    /**
     * Cloud cover: CLR/SKC
     */
    public function testCloudCover_CLR_SetsCover(): void
    {
        $metar = 'METAR KSPB 132345Z 09007KT 10SM CLR 05/05 A3003';
        $result = parseRawMETARToWeatherArray($metar);
        $this->assertIsArray($result);
        $this->assertEquals('CLR', $result['cloud_cover']);
        $this->assertNull($result['ceiling']);
    }

    /**
     * Temperature: M05/M05 (minus)
     */
    public function testTemperature_Negative_ParsesCorrectly(): void
    {
        $metar = 'METAR KSPB 132345Z 09007KT 10SM CLR M05/M05 A3003';
        $result = parseRawMETARToWeatherArray($metar);
        $this->assertIsArray($result);
        $this->assertEquals(-5, $result['temperature']);
        $this->assertEquals(-5, $result['dewpoint']);
    }

    /**
     * Pressure: A3012 = 30.12 inHg
     */
    public function testPressure_Altimeter_ParsesCorrectly(): void
    {
        $metar = 'METAR KSPB 132345Z 09007KT 10SM CLR 05/05 A3012';
        $result = parseRawMETARToWeatherArray($metar);
        $this->assertIsArray($result);
        $this->assertEqualsWithDelta(30.12, $result['pressure'], 0.01);
    }

    /**
     * Too short METAR returns null
     */
    public function testMalformed_TooShort_ReturnsNull(): void
    {
        $result = parseRawMETARToWeatherArray('METAR');
        $this->assertNull($result);
    }

    /**
     * Empty string returns null
     */
    public function testMalformed_Empty_ReturnsNull(): void
    {
        $result = parseRawMETARToWeatherArray('');
        $this->assertNull($result);
    }

    /**
     * Partial METAR: missing some fields still returns array with available data
     */
    public function testPartialMetar_MissingFields_ReturnsPartialArray(): void
    {
        $metar = 'METAR KSPB 132345Z 09007KT 10SM CLR';
        $result = parseRawMETARToWeatherArray($metar);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('temperature', $result);
        $this->assertArrayHasKey('visibility', $result);
        $this->assertEquals(10.0, $result['visibility']);
        $this->assertEquals(90, $result['wind_direction']);
    }

    /**
     * Real-world METAR: KSEA format with multiple cloud layers, full observation
     * Source: Standard METAR format per ICAO/WMO (e.g. aviationweather.gov)
     */
    public function testRealWorldMetar_KSEA_CompleteObservation_ParsesCorrectly(): void
    {
        $metar = 'METAR KSEA 032151Z 26004KT 10SM FEW011 BKN022 OVC047 16/15 A2978 RMK AO2';
        $result = parseRawMETARToWeatherArray($metar);

        $this->assertIsArray($result);
        $this->assertEquals(16, $result['temperature']);
        $this->assertEquals(15, $result['dewpoint']);
        $this->assertEquals(260, $result['wind_direction']);
        $this->assertEquals(4, $result['wind_speed']);
        $this->assertNull($result['gust_speed']);
        $this->assertEqualsWithDelta(29.78, $result['pressure'], 0.01);
        $this->assertEquals(10.0, $result['visibility']);
        $this->assertEquals(2200, $result['ceiling'], 'BKN022 = 2200 ft ceiling');
        $this->assertEquals('BKN', $result['cloud_cover']);
        $this->assertNotNull($result['obs_time']);
    }
}
