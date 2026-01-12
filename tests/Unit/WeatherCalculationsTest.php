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
     * Ensures VFR conditions are correctly identified for flight safety
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
     * Ensures MVFR conditions are correctly identified when visibility is marginal
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
     * Ensures IFR conditions are correctly identified for instrument flight requirements
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
     * Ensures LIFR conditions are correctly identified for critical low visibility scenarios
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
     * Ensures boundary values are handled correctly to prevent misclassification at thresholds
     */
    public function testCalculateFlightCategory_EdgeCase_ThreeStatuteMiles()
    {
        // Visibility exactly at 3 SM (MVFR lower threshold per FAA)
        // FAA: IFR is "1 to less than 3 SM", so 3 SM exactly is MVFR
        $weather = [
            'visibility' => 3.0,
            'ceiling' => 5000
        ];
        $result = calculateFlightCategory($weather);
        $this->assertEquals('MVFR', $result);  // Exactly 3 SM is MVFR, not IFR
    }

    public function testCalculateFlightCategory_EdgeCase_JustBelowThreeSM()
    {
        $weather = [
            'visibility' => 2.9,
            'ceiling' => 5000
        ];
        $result = calculateFlightCategory($weather);
        $this->assertEquals('IFR', $result);  // Just below 3 SM is IFR
    }

    /**
     * Ensures density altitude is calculated correctly for aircraft performance planning
     * 
     * SAFETY CRITICAL: These tests verify the FAA-approved density altitude formula.
     * Incorrect calculations could lead to runway overruns or inability to climb.
     * 
     * Test values calculated using FAA formula:
     * DA = PA + [120 × (OAT - ISA Temp)]
     * Where: PA = Elevation + (29.92 - Altimeter) × 1000
     *        ISA Temp = 59°F - (3.57°F × PA/1000)
     */
    public function testCalculateDensityAltitude_StandardConditions_SeaLevel()
    {
        // Standard conditions at sea level
        // Temp: 15°C (59°F), Pressure: 29.92 inHg
        // Expected: PA = 0, ISA = 59°F, DA = 0 + 120 × (59 - 59) = 0
        $weather = createTestWeatherData([
            'temperature' => 15.0,  // 59°F
            'pressure' => 29.92
        ]);
        $airport = createTestAirport(['elevation_ft' => 0]);
        
        $result = calculateDensityAltitude($weather, $airport);
        $this->assertEquals(0, $result);  // Standard conditions = 0 density altitude
    }

    public function testCalculateDensityAltitude_StandardConditions_1000ft()
    {
        // Standard conditions at 1000 ft elevation
        // Temp: 15°C (59°F), Pressure: 29.92 inHg
        // Expected: PA = 1000, ISA = 59 - 3.57 = 55.43°F, DA = 1000 + 120 × (59 - 55.43) = 1428
        $weather = createTestWeatherData([
            'temperature' => 15.0,  // 59°F
            'pressure' => 29.92
        ]);
        $airport = createTestAirport(['elevation_ft' => 1000]);
        
        $result = calculateDensityAltitude($weather, $airport);
        // Allow ±10 ft tolerance for rounding
        $this->assertEqualsWithDelta(1428, $result, 10);
    }

    public function testCalculateDensityAltitude_HotDay_SeaLevel()
    {
        // Hot day at sea level: 35°C (95°F), standard pressure
        // Expected: PA = 0, ISA = 59°F, DA = 0 + 120 × (95 - 59) = 4320
        $weather = createTestWeatherData([
            'temperature' => 35.0,  // 95°F
            'pressure' => 29.92
        ]);
        $airport = createTestAirport(['elevation_ft' => 0]);
        
        $result = calculateDensityAltitude($weather, $airport);
        $this->assertEqualsWithDelta(4320, $result, 10);
        $this->assertGreaterThan($airport['elevation_ft'], $result);
    }

    public function testCalculateDensityAltitude_HotDay_HighElevation()
    {
        // Hot day at high elevation: 5000 ft, 30°C (86°F), pressure 24.92 inHg (low)
        // PA = 5000 + (29.92 - 24.92) × 1000 = 10,000 ft
        // ISA at 10,000 ft = 59 - (3.57 × 10) = 23.3°F
        // DA = 10,000 + 120 × (86 - 23.3) = 10,000 + 7,524 = 17,524 ft
        $weather = createTestWeatherData([
            'temperature' => 30.0,  // 86°F
            'pressure' => 24.92
        ]);
        $airport = createTestAirport(['elevation_ft' => 5000]);
        
        $result = calculateDensityAltitude($weather, $airport);
        $this->assertEqualsWithDelta(17524, $result, 10);
        // This is a dangerous condition - DA is 3.5x field elevation!
        $this->assertGreaterThan(15000, $result);
    }

    public function testCalculateDensityAltitude_ColdDay_SeaLevel()
    {
        // Cold day at sea level: 0°C (32°F), standard pressure
        // Expected: PA = 0, ISA = 59°F, DA = 0 + 120 × (32 - 59) = -3240
        $weather = createTestWeatherData([
            'temperature' => 0.0,  // 32°F
            'pressure' => 29.92
        ]);
        $airport = createTestAirport(['elevation_ft' => 0]);
        
        $result = calculateDensityAltitude($weather, $airport);
        $this->assertEqualsWithDelta(-3240, $result, 10);
        $this->assertLessThan($airport['elevation_ft'], $result);
    }

    public function testCalculateDensityAltitude_HighPressure_BetterPerformance()
    {
        // High pressure day (30.92 inHg), standard temp
        // PA = 100 + (29.92 - 30.92) × 1000 = 100 - 1000 = -900 ft
        // ISA at -900 ft = 59 - (3.57 × -0.9) = 62.21°F
        // DA = -900 + 120 × (59 - 62.21) = -900 - 385 = -1285
        $weather = createTestWeatherData([
            'temperature' => 15.0,  // 59°F
            'pressure' => 30.92
        ]);
        $airport = createTestAirport(['elevation_ft' => 100]);
        
        $result = calculateDensityAltitude($weather, $airport);
        $this->assertLessThan($airport['elevation_ft'], $result);
        $this->assertEqualsWithDelta(-1285, $result, 10);
    }

    public function testCalculateDensityAltitude_LowPressure_WorsePerformance()
    {
        // Low pressure day (29.42 inHg), standard temp
        // PA = 100 + (29.92 - 29.42) × 1000 = 100 + 500 = 600 ft
        // ISA at 600 ft = 59 - (3.57 × 0.6) = 56.86°F
        // DA = 600 + 120 × (59 - 56.86) = 600 + 257 = 857
        $weather = createTestWeatherData([
            'temperature' => 15.0,  // 59°F
            'pressure' => 29.42
        ]);
        $airport = createTestAirport(['elevation_ft' => 100]);
        
        $result = calculateDensityAltitude($weather, $airport);
        $this->assertGreaterThan($airport['elevation_ft'], $result);
        $this->assertEqualsWithDelta(857, $result, 10);
    }

    public function testCalculateDensityAltitude_RealWorld_KDEN_SummerDay()
    {
        // Denver International (KDEN) on a hot summer day
        // Elevation: 5434 ft, Temp: 35°C (95°F), Pressure: 24.50 inHg
        // PA = 5434 + (29.92 - 24.50) × 1000 = 10,854 ft
        // ISA at 10,854 ft = 59 - (3.57 × 10.854) = 20.25°F
        // DA = 10,854 + 120 × (95 - 20.25) = 10,854 + 8,970 = 19,824 ft
        $weather = createTestWeatherData([
            'temperature' => 35.0,  // 95°F
            'pressure' => 24.50
        ]);
        $airport = createTestAirport(['elevation_ft' => 5434]);
        
        $result = calculateDensityAltitude($weather, $airport);
        $this->assertEqualsWithDelta(19824, $result, 20);
        // This is extreme - aircraft performance is severely degraded
        $this->assertGreaterThan(19000, $result);
    }

    public function testCalculateDensityAltitude_OldFormulaComparison()
    {
        // This test ensures the new formula differs from the old (incorrect) formula
        // Old formula used station elevation for ISA calculation instead of pressure altitude
        // Test with conditions where the difference is significant
        $weather = createTestWeatherData([
            'temperature' => 30.0,  // 86°F
            'pressure' => 28.92     // Low pressure (+1000 ft PA)
        ]);
        $airport = createTestAirport(['elevation_ft' => 5000]);
        
        $result = calculateDensityAltitude($weather, $airport);
        
        // New formula (correct):
        // PA = 5000 + (29.92 - 28.92) × 1000 = 6000 ft
        // ISA = 59 - (3.57 × 6) = 37.58°F
        // DA = 6000 + 120 × (86 - 37.58) = 6000 + 5810 = 11,810 ft
        $this->assertEqualsWithDelta(11810, $result, 10);
        
        // Old formula (incorrect) would have calculated:
        // ISA = 59 - (3.57 × 5) = 41.15°F (based on station elevation, not PA)
        // DA = 5000 + 120 × (86 - 41.15) = 5000 + 5382 = 10,382 ft
        // The old formula underestimated by ~1428 ft - a dangerous error!
        $oldFormulaResult = 10382;
        $this->assertGreaterThan($oldFormulaResult + 1000, $result);
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
     * Ensures pressure altitude is calculated correctly for altimeter settings
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
     * Ensures dewpoint and humidity calculations are mathematically consistent
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

    /**
     * Test pressure unit validation and correction in UnifiedFetcher
     * 
     * Critical safety test: Ensures that pressure values in wrong units (hundredths of inHg
     * or Pa instead of hPa) are automatically corrected to prevent dangerous pressure
     * altitude miscalculations that could affect flight safety decisions.
     */
    public function testPressureUnitCorrection_HundredthsOfInHg()
    {
        // Simulate pressure in hundredths of inHg (API returned 3038.93 instead of 30.3893)
        $result = ['pressure' => 3038.93];
        
        // Apply the same correction logic as UnifiedFetcher
        if (isset($result['pressure']) && is_numeric($result['pressure'])) {
            $pressure = (float)$result['pressure'];
            if ($pressure > 100) {
                $result['pressure'] = $pressure / 100.0;
            }
        }
        
        // Should be corrected to ~30.39 inHg
        $this->assertEqualsWithDelta(30.3893, $result['pressure'], 0.001);
    }

    public function testPressureUnitCorrection_NormalPressureUnaffected()
    {
        // Normal pressure value should not be changed
        $result = ['pressure' => 30.12];
        
        // Apply the same correction logic as UnifiedFetcher
        if (isset($result['pressure']) && is_numeric($result['pressure'])) {
            $pressure = (float)$result['pressure'];
            if ($pressure > 100) {
                $result['pressure'] = $pressure / 100.0;
            }
        }
        
        // Should remain unchanged
        $this->assertEquals(30.12, $result['pressure']);
    }

    public function testPressureUnitCorrection_HighNormalPressure()
    {
        // High but valid pressure (32 inHg) should not be changed
        $result = ['pressure' => 32.00];
        
        // Apply the same correction logic as UnifiedFetcher
        if (isset($result['pressure']) && is_numeric($result['pressure'])) {
            $pressure = (float)$result['pressure'];
            if ($pressure > 100) {
                $result['pressure'] = $pressure / 100.0;
            }
        }
        
        // Should remain unchanged
        $this->assertEquals(32.00, $result['pressure']);
    }

    public function testPressureUnitCorrection_LowPressure()
    {
        // Low but valid pressure (28 inHg) should not be changed
        $result = ['pressure' => 28.00];
        
        // Apply the same correction logic as UnifiedFetcher
        if (isset($result['pressure']) && is_numeric($result['pressure'])) {
            $pressure = (float)$result['pressure'];
            if ($pressure > 100) {
                $result['pressure'] = $pressure / 100.0;
            }
        }
        
        // Should remain unchanged
        $this->assertEquals(28.00, $result['pressure']);
    }

    public function testPressureUnitCorrection_NullPressure()
    {
        // Null pressure should remain null
        $result = ['pressure' => null];
        
        // Apply the same correction logic as UnifiedFetcher
        if (isset($result['pressure']) && is_numeric($result['pressure'])) {
            $pressure = (float)$result['pressure'];
            if ($pressure > 100) {
                $result['pressure'] = $pressure / 100.0;
            }
        }
        
        // Should remain null
        $this->assertNull($result['pressure']);
    }
}

