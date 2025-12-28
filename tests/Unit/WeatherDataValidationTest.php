<?php
/**
 * Weather Data Validation Smoke Tests
 * 
 * Critical safety tests that verify weather data is within physically possible ranges.
 * These tests catch unit conversion errors and API format changes that could produce
 * dangerous values affecting flight safety decisions.
 * 
 * Background: A production bug where SynopticData returned pressure in hundredths of inHg
 * (3038.93 instead of 30.3893) caused pressure altitude to be calculated as -3,005,848 ft.
 * These tests ensure such issues are caught early.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/weather/validation.php';
require_once __DIR__ . '/../../lib/constants.php';

class WeatherDataValidationTest extends TestCase
{
    /**
     * Test that validateWeatherField correctly identifies out-of-bounds pressure
     * 
     * This test verifies the validation function exists and works correctly.
     * Note: The validation function is defensive code that should be called during aggregation.
     */
    public function testValidateWeatherField_PressureOutOfBounds_InvalidHigh()
    {
        // Pressure 100x too high (API returned hundredths of inHg)
        $result = validateWeatherField('pressure', 3038.93);
        
        $this->assertFalse($result['valid']);
        $this->assertEquals('pressure out of bounds', $result['reason']);
    }

    public function testValidateWeatherField_PressureOutOfBounds_InvalidLow()
    {
        // Pressure impossibly low
        $result = validateWeatherField('pressure', 5.0);
        
        $this->assertFalse($result['valid']);
        $this->assertEquals('pressure out of bounds', $result['reason']);
    }

    public function testValidateWeatherField_PressureValid_NormalHigh()
    {
        // High but valid pressure (32 inHg)
        $result = validateWeatherField('pressure', 32.00);
        
        $this->assertTrue($result['valid']);
    }

    public function testValidateWeatherField_PressureValid_NormalLow()
    {
        // Low but valid pressure (28 inHg)
        $result = validateWeatherField('pressure', 28.00);
        
        $this->assertTrue($result['valid']);
    }

    public function testValidateWeatherField_PressureValid_Standard()
    {
        // Standard sea level pressure (29.92 inHg)
        $result = validateWeatherField('pressure', 29.92);
        
        $this->assertTrue($result['valid']);
    }

    /**
     * Test temperature bounds validation
     */
    public function testValidateWeatherField_TemperatureOutOfBounds_TooHot()
    {
        // Temperature impossibly hot (100°C)
        $result = validateWeatherField('temperature', 100.0);
        
        $this->assertFalse($result['valid']);
        $this->assertEquals('temperature out of bounds', $result['reason']);
    }

    public function testValidateWeatherField_TemperatureOutOfBounds_TooCold()
    {
        // Temperature impossibly cold (-150°C)
        $result = validateWeatherField('temperature', -150.0);
        
        $this->assertFalse($result['valid']);
        $this->assertEquals('temperature out of bounds', $result['reason']);
    }

    public function testValidateWeatherField_TemperatureValid_ExtremeHot()
    {
        // Hot but valid (56°C - Death Valley record)
        $result = validateWeatherField('temperature', 56.0);
        
        $this->assertTrue($result['valid']);
    }

    public function testValidateWeatherField_TemperatureValid_ExtremeCold()
    {
        // Cold but valid (-89°C - Antarctica record)
        $result = validateWeatherField('temperature', -89.0);
        
        $this->assertTrue($result['valid']);
    }

    /**
     * Test wind speed bounds validation
     */
    public function testValidateWeatherField_WindSpeedOutOfBounds()
    {
        // Wind speed impossibly high (500 knots)
        $result = validateWeatherField('wind_speed', 500);
        
        $this->assertFalse($result['valid']);
        $this->assertEquals('wind speed out of bounds', $result['reason']);
    }

    public function testValidateWeatherField_WindSpeedValid_Extreme()
    {
        // High but valid wind (200 knots - hurricane)
        $result = validateWeatherField('wind_speed', 200);
        
        $this->assertTrue($result['valid']);
    }

    public function testValidateWeatherField_WindSpeedValid_Calm()
    {
        // Calm wind
        $result = validateWeatherField('wind_speed', 0);
        
        $this->assertTrue($result['valid']);
    }

    /**
     * Test humidity bounds validation
     */
    public function testValidateWeatherField_HumidityOutOfBounds_TooHigh()
    {
        // Humidity > 100%
        $result = validateWeatherField('humidity', 150);
        
        $this->assertFalse($result['valid']);
        $this->assertEquals('humidity out of bounds', $result['reason']);
    }

    public function testValidateWeatherField_HumidityOutOfBounds_Negative()
    {
        // Negative humidity
        $result = validateWeatherField('humidity', -10);
        
        $this->assertFalse($result['valid']);
        $this->assertEquals('humidity out of bounds', $result['reason']);
    }

    public function testValidateWeatherField_HumidityValid_Saturated()
    {
        // 100% humidity (saturated)
        $result = validateWeatherField('humidity', 100);
        
        $this->assertTrue($result['valid']);
    }

    /**
     * Test null value handling
     */
    public function testValidateWeatherField_NullValue_ReturnsValid()
    {
        // Null values should pass bounds validation (null handling is separate)
        $result = validateWeatherField('pressure', null);
        
        $this->assertTrue($result['valid']);
    }

    /**
     * Test pressure altitude bounds validation
     * 
     * This is critical because incorrect pressure causes extreme pressure altitude values
     */
    public function testValidateWeatherField_PressureAltitudeOutOfBounds()
    {
        // Pressure altitude impossibly negative (-3 million feet from bad pressure)
        $result = validateWeatherField('pressure_altitude', -3000000);
        
        $this->assertFalse($result['valid']);
        $this->assertEquals('pressure altitude out of bounds', $result['reason']);
    }

    public function testValidateWeatherField_PressureAltitudeValid_High()
    {
        // High but valid pressure altitude
        $result = validateWeatherField('pressure_altitude', 15000);
        
        $this->assertTrue($result['valid']);
    }

    public function testValidateWeatherField_PressureAltitudeValid_Negative()
    {
        // Slightly negative pressure altitude (high pressure day at low elevation)
        $result = validateWeatherField('pressure_altitude', -500);
        
        $this->assertTrue($result['valid']);
    }

    /**
     * Test density altitude bounds validation
     */
    public function testValidateWeatherField_DensityAltitudeOutOfBounds()
    {
        // Density altitude impossibly extreme
        $result = validateWeatherField('density_altitude', -100000);
        
        $this->assertFalse($result['valid']);
        $this->assertEquals('density altitude out of bounds', $result['reason']);
    }

    /**
     * Smoke test: Simulate API returning pressure in wrong units
     * This test simulates the actual bug that occurred in production
     */
    public function testSmokeTest_PressureInHundredthsOfInHg_DetectedAsInvalid()
    {
        // Simulates SynopticData returning altimeter in hundredths (3038.93 instead of 30.3893)
        $badPressure = 3038.93;
        
        // The validation function should catch this
        $result = validateWeatherField('pressure', $badPressure);
        
        $this->assertFalse($result['valid'], 
            'Pressure of 3038.93 inHg should be rejected as out of bounds (normal range is 28-32 inHg)');
    }

    /**
     * Smoke test: Simulate API returning pressure in Pascals instead of hectopascals
     * If METAR altim was 102893 Pa (instead of 1028.93 hPa), dividing by 33.8639 gives ~3038 inHg
     */
    public function testSmokeTest_PressureFromPascalsConversion_DetectedAsInvalid()
    {
        // Simulates what happens if API returned Pascals instead of hectopascals
        // 102893 Pa / 33.8639 = 3038.9 inHg (wrong!)
        // Should have been: 102893 Pa / 100 = 1028.93 hPa / 33.8639 = 30.38 inHg
        $badPressure = 3038.9;
        
        $result = validateWeatherField('pressure', $badPressure);
        
        $this->assertFalse($result['valid'],
            'Pressure from Pa->inHg miscalculation should be rejected');
    }

    /**
     * Test that all critical weather fields have bounds defined in constants
     * This ensures we don't miss validation for important fields
     */
    public function testBoundsConstants_AllCriticalFieldsHaveBounds()
    {
        // Pressure bounds
        $this->assertTrue(defined('CLIMATE_PRESSURE_MIN_INHG'), 
            'CLIMATE_PRESSURE_MIN_INHG should be defined');
        $this->assertTrue(defined('CLIMATE_PRESSURE_MAX_INHG'), 
            'CLIMATE_PRESSURE_MAX_INHG should be defined');
        
        // Temperature bounds
        $this->assertTrue(defined('CLIMATE_TEMP_MIN_C'), 
            'CLIMATE_TEMP_MIN_C should be defined');
        $this->assertTrue(defined('CLIMATE_TEMP_MAX_C'), 
            'CLIMATE_TEMP_MAX_C should be defined');
        
        // Wind speed bounds
        $this->assertTrue(defined('CLIMATE_WIND_SPEED_MAX_KTS'), 
            'CLIMATE_WIND_SPEED_MAX_KTS should be defined');
        
        // Humidity bounds
        $this->assertTrue(defined('CLIMATE_HUMIDITY_MIN'), 
            'CLIMATE_HUMIDITY_MIN should be defined');
        $this->assertTrue(defined('CLIMATE_HUMIDITY_MAX'), 
            'CLIMATE_HUMIDITY_MAX should be defined');
        
        // Pressure altitude bounds
        $this->assertTrue(defined('CLIMATE_PRESSURE_ALTITUDE_MIN_FT'), 
            'CLIMATE_PRESSURE_ALTITUDE_MIN_FT should be defined');
        $this->assertTrue(defined('CLIMATE_PRESSURE_ALTITUDE_MAX_FT'), 
            'CLIMATE_PRESSURE_ALTITUDE_MAX_FT should be defined');
        
        // Density altitude bounds
        $this->assertTrue(defined('CLIMATE_DENSITY_ALTITUDE_MIN_FT'), 
            'CLIMATE_DENSITY_ALTITUDE_MIN_FT should be defined');
        $this->assertTrue(defined('CLIMATE_DENSITY_ALTITUDE_MAX_FT'), 
            'CLIMATE_DENSITY_ALTITUDE_MAX_FT should be defined');
    }

    /**
     * Test that bounds are reasonable for flight safety
     */
    public function testBoundsConstants_PressureBoundsAreReasonable()
    {
        // Normal pressure range is approximately 28-32 inHg
        // Bounds should be slightly wider to allow for extreme weather
        $this->assertLessThan(28.0, CLIMATE_PRESSURE_MIN_INHG, 
            'Min pressure bound should allow for extreme low pressure');
        $this->assertGreaterThan(32.0, CLIMATE_PRESSURE_MAX_INHG, 
            'Max pressure bound should allow for extreme high pressure');
        
        // But bounds should catch obviously wrong values (100x errors)
        $this->assertLessThan(100.0, CLIMATE_PRESSURE_MAX_INHG, 
            'Max pressure bound should catch 100x unit errors');
    }

    // ========== Integration Tests for validateWeatherData() ==========

    /**
     * Test validateWeatherData with valid data
     */
    public function testValidateWeatherData_ValidData_ReturnsUnchanged()
    {
        $data = [
            'temperature' => 20.0,
            'dewpoint' => 15.0,
            'humidity' => 70,
            'pressure' => 30.12,
            'wind_speed' => 10,
            'wind_direction' => 180,
            'gust_speed' => 15,
            'visibility' => 10,
            'ceiling' => 5000,
            'precip_accum' => 0.5,
        ];
        
        $result = validateWeatherData($data, 'test');
        
        // All values should be unchanged
        $this->assertEquals(20.0, $result['temperature']);
        $this->assertEquals(30.12, $result['pressure']);
        $this->assertEquals(10, $result['wind_speed']);
        
        // No validation issues should be recorded
        $this->assertArrayNotHasKey('_validation_issues', $result);
    }

    /**
     * Test validateWeatherData with dangerously high pressure (should be nulled)
     */
    public function testValidateWeatherData_DangerousPressure_NulledForSafety()
    {
        $data = [
            'temperature' => 20.0,
            'pressure' => 3038.93,  // 100x too high - dangerous for calculations
        ];
        
        $result = validateWeatherData($data, 'test');
        
        // Pressure should be nulled for safety
        $this->assertNull($result['pressure'], 
            'Dangerously high pressure should be nulled');
        
        // Validation issues should be recorded
        $this->assertArrayHasKey('_validation_issues', $result);
        $this->assertCount(1, $result['_validation_issues']);
        $this->assertEquals('pressure', $result['_validation_issues'][0]['field']);
    }

    /**
     * Test validateWeatherData with extreme but valid pressure (kept with warning)
     */
    public function testValidateWeatherData_ExtremePressure_KeptWithWarning()
    {
        $data = [
            'temperature' => 20.0,
            'pressure' => 36.0,  // Slightly out of bounds but not dangerous
        ];
        
        $result = validateWeatherData($data, 'test');
        
        // Pressure should be kept (not nulled) - might be legitimate extreme
        $this->assertEquals(36.0, $result['pressure'], 
            'Slightly out of bounds pressure should be kept');
        
        // But validation issues should be recorded
        $this->assertArrayHasKey('_validation_issues', $result);
    }

    /**
     * Test validateWeatherData with dangerously extreme temperature (should be nulled)
     */
    public function testValidateWeatherData_DangerousTemperature_NulledForSafety()
    {
        $data = [
            'temperature' => 200.0,  // Way beyond any earth temperature
            'pressure' => 30.12,
        ];
        
        $result = validateWeatherData($data, 'test');
        
        // Temperature should be nulled for safety
        $this->assertNull($result['temperature'], 
            'Dangerously extreme temperature should be nulled');
        
        // Pressure should be unchanged
        $this->assertEquals(30.12, $result['pressure']);
    }

    /**
     * Test validateWeatherData with null values (should pass through)
     */
    public function testValidateWeatherData_NullValues_PassThrough()
    {
        $data = [
            'temperature' => null,
            'pressure' => null,
            'wind_speed' => 10,
        ];
        
        $result = validateWeatherData($data, 'test');
        
        // Null values should pass through unchanged
        $this->assertNull($result['temperature']);
        $this->assertNull($result['pressure']);
        $this->assertEquals(10, $result['wind_speed']);
        
        // No validation issues for null values
        $this->assertArrayNotHasKey('_validation_issues', $result);
    }

    /**
     * Test shouldNullInvalidField for pressure thresholds
     */
    public function testShouldNullInvalidField_PressureThresholds()
    {
        // Way too high - should null
        $this->assertTrue(shouldNullInvalidField('pressure', 100, 'out of bounds'),
            'Pressure 100 should be nulled');
        
        // Way too low - should null
        $this->assertTrue(shouldNullInvalidField('pressure', 5, 'out of bounds'),
            'Pressure 5 should be nulled');
        
        // Slightly out of bounds - should keep
        $this->assertFalse(shouldNullInvalidField('pressure', 36, 'out of bounds'),
            'Pressure 36 should be kept (slightly out of bounds)');
        
        // Normal pressure - should keep
        $this->assertFalse(shouldNullInvalidField('pressure', 30, 'any reason'),
            'Normal pressure should be kept');
    }

    /**
     * Test shouldNullInvalidField for temperature thresholds
     */
    public function testShouldNullInvalidField_TemperatureThresholds()
    {
        // Way too high - should null
        $this->assertTrue(shouldNullInvalidField('temperature', 100, 'out of bounds'),
            'Temperature 100°C should be nulled');
        
        // Way too low - should null
        $this->assertTrue(shouldNullInvalidField('temperature', -150, 'out of bounds'),
            'Temperature -150°C should be nulled');
        
        // Slightly out of bounds - should keep
        $this->assertFalse(shouldNullInvalidField('temperature', 60, 'out of bounds'),
            'Temperature 60°C should be kept (hot but possible)');
    }
}

