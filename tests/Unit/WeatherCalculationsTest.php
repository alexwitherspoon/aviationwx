<?php
/**
 * Unit Tests for Weather Calculation Functions
 * 
 * Tests core weather calculation functions that are critical for flight safety
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../api/weather.php';

class WeatherCalculationsTest extends TestCase
{
    /**
     * Test calculateFlightCategory - VFR conditions
     */
    public function testCalculateFlightCategory_VFR_StandardConditions()
    {
        $weather = [
            'visibility' => 10.0,
            'ceiling' => 5000
        ];
        $result = calculateFlightCategory($weather);
        $this->assertEquals('VFR', $result);
    }

    public function testCalculateFlightCategory_VFR_HighCeiling()
    {
        $weather = [
            'visibility' => 10.0,
            'ceiling' => null  // Unlimited
        ];
        $result = calculateFlightCategory($weather);
        $this->assertEquals('VFR', $result);
    }

    public function testCalculateFlightCategory_VFR_NoCeilingButGoodVisibility()
    {
        $weather = [
            'visibility' => 10.0,
            'ceiling' => null
        ];
        $result = calculateFlightCategory($weather);
        $this->assertEquals('VFR', $result);
    }

    /**
     * Test calculateFlightCategory - MVFR conditions
     */
    public function testCalculateFlightCategory_MVFR_MarginalVisibility()
    {
        $weather = [
            'visibility' => 4.0,
            'ceiling' => 5000
        ];
        $result = calculateFlightCategory($weather);
        $this->assertEquals('MVFR', $result);
    }

    public function testCalculateFlightCategory_MVFR_MarginalCeiling()
    {
        $weather = [
            'visibility' => 10.0,
            'ceiling' => 2000
        ];
        $result = calculateFlightCategory($weather);
        $this->assertEquals('MVFR', $result);
    }

    /**
     * Test calculateFlightCategory - IFR conditions
     */
    public function testCalculateFlightCategory_IFR_Visibility()
    {
        $weather = [
            'visibility' => 2.0,
            'ceiling' => 5000
        ];
        $result = calculateFlightCategory($weather);
        $this->assertEquals('IFR', $result);
    }

    public function testCalculateFlightCategory_IFR_Ceiling()
    {
        $weather = [
            'visibility' => 10.0,
            'ceiling' => 800
        ];
        $result = calculateFlightCategory($weather);
        $this->assertEquals('IFR', $result);
    }

    /**
     * Test calculateFlightCategory - LIFR conditions
     */
    public function testCalculateFlightCategory_LIFR_BothConditions()
    {
        $weather = [
            'visibility' => 0.5,
            'ceiling' => 400
        ];
        $result = calculateFlightCategory($weather);
        $this->assertEquals('LIFR', $result);
    }

    public function testCalculateFlightCategory_LIFR_VisibilityOnly()
    {
        $weather = [
            'visibility' => 0.5,
            'ceiling' => null
        ];
        $result = calculateFlightCategory($weather);
        $this->assertEquals('LIFR', $result);
    }

    public function testCalculateFlightCategory_LIFR_CeilingOnly()
    {
        $weather = [
            'visibility' => null,
            'ceiling' => 400
        ];
        $result = calculateFlightCategory($weather);
        $this->assertEquals('LIFR', $result);
    }

    /**
     * Test calculateFlightCategory - Edge cases
     */
    public function testCalculateFlightCategory_EdgeCase_BoundaryValues()
    {
        // Visibility exactly at 3 SM (IFR threshold)
        $weather = [
            'visibility' => 3.0,
            'ceiling' => 5000
        ];
        $result = calculateFlightCategory($weather);
        $this->assertEquals('IFR', $result);  // Exactly 3 SM is IFR
    }

    public function testCalculateFlightCategory_EdgeCase_JustAboveIFR()
    {
        $weather = [
            'visibility' => 3.1,
            'ceiling' => 5000
        ];
        $result = calculateFlightCategory($weather);
        $this->assertEquals('MVFR', $result);  // Just above 3 SM is MVFR
    }

    /**
     * Test calculateDensityAltitude
     */
    public function testCalculateDensityAltitude_StandardConditions()
    {
        $weather = createTestWeatherData([
            'temperature' => 15.0,  // 59°F
            'pressure' => 29.92
        ]);
        $airport = createTestAirport(['elevation_ft' => 100]);
        
        $result = calculateDensityAltitude($weather, $airport);
        $this->assertNotNull($result);
        $this->assertIsInt($result);
    }

    public function testCalculateDensityAltitude_HotConditions()
    {
        $weather = createTestWeatherData([
            'temperature' => 35.0,  // 95°F - hot day
            'pressure' => 29.92
        ]);
        $airport = createTestAirport(['elevation_ft' => 100]);
        
        $result = calculateDensityAltitude($weather, $airport);
        $this->assertNotNull($result);
        // Density altitude should be higher than field elevation on hot days
        $this->assertGreaterThan($airport['elevation_ft'], $result);
    }

    public function testCalculateDensityAltitude_MissingTemperature()
    {
        $weather = createTestWeatherData([
            'temperature' => null,
            'pressure' => 29.92
        ]);
        $airport = createTestAirport(['elevation_ft' => 100]);
        
        $result = calculateDensityAltitude($weather, $airport);
        $this->assertNull($result);
    }

    public function testCalculateDensityAltitude_MissingPressure()
    {
        $weather = createTestWeatherData([
            'temperature' => 15.0,
            'pressure' => null
        ]);
        $airport = createTestAirport(['elevation_ft' => 100]);
        
        $result = calculateDensityAltitude($weather, $airport);
        $this->assertNull($result);
    }

    /**
     * Test calculatePressureAltitude
     */
    public function testCalculatePressureAltitude_StandardPressure()
    {
        $weather = createTestWeatherData([
            'pressure' => 29.92
        ]);
        $airport = createTestAirport(['elevation_ft' => 100]);
        
        $result = calculatePressureAltitude($weather, $airport);
        $this->assertEquals(100, $result);  // At standard pressure, equals field elevation
    }

    public function testCalculatePressureAltitude_LowPressure()
    {
        $weather = createTestWeatherData([
            'pressure' => 29.50  // Low pressure
        ]);
        $airport = createTestAirport(['elevation_ft' => 100]);
        
        $result = calculatePressureAltitude($weather, $airport);
        // Pressure altitude should be higher than field elevation
        $this->assertGreaterThan($airport['elevation_ft'], $result);
    }

    public function testCalculatePressureAltitude_HighPressure()
    {
        $weather = createTestWeatherData([
            'pressure' => 30.50  // High pressure
        ]);
        $airport = createTestAirport(['elevation_ft' => 100]);
        
        $result = calculatePressureAltitude($weather, $airport);
        // Pressure altitude should be lower than field elevation
        $this->assertLessThan($airport['elevation_ft'], $result);
    }

    public function testCalculatePressureAltitude_MissingPressure()
    {
        $weather = createTestWeatherData([
            'pressure' => null
        ]);
        $airport = createTestAirport(['elevation_ft' => 100]);
        
        $result = calculatePressureAltitude($weather, $airport);
        $this->assertNull($result);
    }

    /**
     * Test calculateDewpoint and calculateHumidityFromDewpoint (round-trip)
     */
    public function testCalculateDewpoint_RoundTrip()
    {
        $tempC = 20.0;
        $humidity = 70;
        
        // Calculate dewpoint from temp and humidity
        $dewpoint = calculateDewpoint($tempC, $humidity);
        $this->assertNotNull($dewpoint);
        
        // Calculate humidity back from temp and dewpoint
        $calculatedHumidity = calculateHumidityFromDewpoint($tempC, $dewpoint);
        $this->assertNotNull($calculatedHumidity);
        
        // Should be close to original (within 5% due to rounding)
        $this->assertLessThan(5, abs($humidity - $calculatedHumidity));
    }

    public function testCalculateDewpoint_MissingTemperature()
    {
        $result = calculateDewpoint(null, 70);
        $this->assertNull($result);
    }

    public function testCalculateDewpoint_MissingHumidity()
    {
        $result = calculateDewpoint(20.0, null);
        $this->assertNull($result);
    }

    public function testCalculateHumidityFromDewpoint_100PercentHumidity()
    {
        $tempC = 20.0;
        $dewpoint = $tempC;  // Dewpoint equals temperature = 100% humidity
        
        $result = calculateHumidityFromDewpoint($tempC, $dewpoint);
        $this->assertNotNull($result);
        $this->assertEquals(100, $result);
    }

    /**
     * Test gust factor calculation
     */
    public function testGustFactor_NormalGust()
    {
        $windSpeed = 10;
        $gustSpeed = 15;
        $gustFactor = $gustSpeed - $windSpeed;
        $this->assertEquals(5, $gustFactor);
    }

    public function testGustFactor_ExtremeGust()
    {
        $windSpeed = 8;
        $gustSpeed = 25;
        $gustFactor = $gustSpeed - $windSpeed;
        $this->assertEquals(17, $gustFactor);
    }

    public function testGustFactor_CalmWind()
    {
        $windSpeed = 0;
        $gustSpeed = 5;
        $gustFactor = $gustSpeed - $windSpeed;
        $this->assertEquals(5, $gustFactor);
    }
}

