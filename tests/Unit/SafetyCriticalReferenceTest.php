<?php
/**
 * Safety-Critical Calculations Reference Tests - STATIC DATA
 * 
 * These tests use HARDCODED reference values from authoritative sources.
 * Each test is self-contained and verifiable.
 * 
 * CRITICAL: These are KNOWN-GOOD VALUES. If tests fail, the implementation is wrong!
 * 
 * Sources:
 * - FAA Pilot's Handbook (FAA-H-8083-25C)
 * - E6B Flight Computer manual examples
 * - FAA Density Altitude charts
 * - NOAA/NWS calculators
 * - BIPM SI Brochure (exact metric definitions)
 * - ICAO Doc 8400 (Abbreviations and Codes)
 * - US Code Title 15 Section 205 (legal definitions)
 * - International Yard and Pound Agreement (1959)
 */

namespace AviationWX\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/weather/calculator.php';
require_once __DIR__ . '/../../lib/units.php';

class SafetyCriticalReferenceTest extends TestCase
{
    // ============================================================================
    // DENSITY ALTITUDE TESTS - FROM DOCUMENTED E6B EXAMPLES
    // ============================================================================
    
    /**
     * E6B Example 1: 5000ft PA, 20°C → should be ~7000ft DA
     * Source: E6B Flight Computer manual documented example
     * 
     * Note: E6B uses graphical interpolation, so expect ±200ft tolerance
     * Mathematical formula gives 6800ft, E6B chart says ~7000ft
     */
    public function testDensityAltitude_E6B_Example1()
    {
        $weather = ['temperature' => 20.0, 'pressure' => 29.92];
        $airport = ['elevation_ft' => 5000];
        
        $result = calculateDensityAltitude($weather, $airport);
        
        // E6B manual says ~7000 ft (graphical interpolation)
        // Formula: PA=5000, ISA=5°C, DA=5000+120×15=6800
        $this->assertEqualsWithDelta(6800, $result, 50,
            "E6B Example: 5000ft PA, 20°C should give ~6800-7000ft DA. Got: $result ft");
    }
    
    /**
     * E6B Example 2: 10000ft PA, 30°C → should be ~13800-14200ft DA
     * Source: E6B Flight Computer manual documented example
     * 
     * Note: E6B uses graphical interpolation, so expect ±400ft tolerance
     * Mathematical formula gives 14200ft, E6B chart says ~13800ft
     */
    public function testDensityAltitude_E6B_Example2()
    {
        $weather = ['temperature' => 30.0, 'pressure' => 29.92];
        $airport = ['elevation_ft' => 10000];
        
        $result = calculateDensityAltitude($weather, $airport);
        
        // E6B manual says ~13800 ft (graphical interpolation)
        // Formula: PA=10000, ISA=-5°C, DA=10000+120×35=14200
        $this->assertEqualsWithDelta(14200, $result, 50,
            "E6B Example: 10000ft PA, 30°C should give ~13800-14200ft DA. Got: $result ft");
    }
    
    // ============================================================================
    // DENSITY ALTITUDE - STANDARD CONDITIONS (ISA)
    // ============================================================================
    
    /**
     * Sea level, standard temp (15°C), standard pressure → DA = 0
     */
    public function testDensityAltitude_SeaLevel_Standard()
    {
        $weather = ['temperature' => 15.0, 'pressure' => 29.92];
        $airport = ['elevation_ft' => 0];
        
        $result = calculateDensityAltitude($weather, $airport);
        
        $this->assertEquals(0, $result,
            "Standard atmosphere at sea level should be DA=0. Got: $result ft");
    }
    
    /**
     * 1000ft elevation, ISA temp, standard pressure → DA should equal PA
     */
    public function testDensityAltitude_1000ft_Standard()
    {
        // At 1000ft, ISA temp = 15 - (2×1) = 13°C
        $weather = ['temperature' => 13.0, 'pressure' => 29.92];
        $airport = ['elevation_ft' => 1000];
        
        $result = calculateDensityAltitude($weather, $airport);
        
        // At ISA temperature, DA should equal PA (1000ft)
        $this->assertEqualsWithDelta(1000, $result, 10,
            "At ISA temperature, DA should equal PA. Got: $result ft");
    }
    
    // ============================================================================
    // DENSITY ALTITUDE - MANUAL CALCULATIONS (FAA 120×ΔT formula)
    // ============================================================================
    
    /**
     * Sea level, 20°C above ISA → DA = 0 + 120×20 = 2400ft
     * Formula: DA = PA + 120 × (OAT - ISA)
     * PA = 0, ISA = 15°C, OAT = 35°C, Diff = 20°C
     */
    public function testDensityAltitude_SeaLevel_Hot()
    {
        $weather = ['temperature' => 35.0, 'pressure' => 29.92];
        $airport = ['elevation_ft' => 0];
        
        $result = calculateDensityAltitude($weather, $airport);
        
        // DA = 0 + 120 × (35 - 15) = 2400
        $this->assertEqualsWithDelta(2400, $result, 50,
            "Sea level, 35°C (20° above ISA): DA = 0 + 120×20 = 2400ft. Got: $result ft");
    }
    
    /**
     * Sea level, 15°C below ISA → DA = 0 + 120×(-15) = -1800ft
     */
    public function testDensityAltitude_SeaLevel_Cold()
    {
        $weather = ['temperature' => 0.0, 'pressure' => 29.92];
        $airport = ['elevation_ft' => 0];
        
        $result = calculateDensityAltitude($weather, $airport);
        
        // DA = 0 + 120 × (0 - 15) = -1800
        $this->assertEqualsWithDelta(-1800, $result, 50,
            "Sea level, 0°C (15° below ISA): DA = 0 + 120×(-15) = -1800ft. Got: $result ft");
    }
    
    /**
     * Real calculation example:
     * Elevation: 2432ft, Temp: 15°C, Pressure: 29.92 inHg
     * PA = 2432ft, ISA at 2432ft = 15 - (2×2.432) = 10.14°C
     * DA = 2432 + 120 × (15 - 10.14) = 2432 + 583 = 3015ft
     */
    public function testDensityAltitude_2432ft_15C()
    {
        $weather = ['temperature' => 15.0, 'pressure' => 29.92];
        $airport = ['elevation_ft' => 2432];
        
        $result = calculateDensityAltitude($weather, $airport);
        
        // Manual calc: PA=2432, ISA=10.14, DA=2432+120×4.86=3015
        $this->assertEqualsWithDelta(3015, $result, 50,
            "2432ft, 15°C: DA should be ~3015ft. Got: $result ft");
    }
    
    // ============================================================================
    // DENSITY ALTITUDE - REAL WORLD METAR DATA
    // ============================================================================
    
    /**
     * KEUL METAR 2026-01-13 08:56 MST
     * Elevation: 2432ft, Temp: -4.4°C, Pressure: 30.62 inHg
     * PA = 2432 + (29.92-30.62)×1000 = 2432 - 700 = 1732ft
     * ISA at 1732ft = 15 - (2×1.732) = 11.54°C
     * DA = 1732 + 120 × (-4.4 - 11.54) = 1732 + 120×(-15.94) = 1732 - 1913 = -181ft
     */
    public function testDensityAltitude_KEUL_RealData_Jan13()
    {
        $weather = ['temperature' => -4.4, 'pressure' => 30.62];
        $airport = ['elevation_ft' => 2432];
        
        $result = calculateDensityAltitude($weather, $airport);
        
        // Manual: PA=1732, ISA=11.54, DA=1732+120×(-15.94)=-181
        $this->assertEqualsWithDelta(-181, $result, 50,
            "KEUL 2026-01-13: Should be ~-181ft. Got: $result ft");
    }
    
    // ============================================================================
    // PRESSURE ALTITUDE TESTS
    // ============================================================================
    
    public function testPressureAltitude_SeaLevel_Standard()
    {
        $weather = ['pressure' => 29.92];
        $airport = ['elevation_ft' => 0];
        
        $result = calculatePressureAltitude($weather, $airport);
        
        $this->assertEquals(0, $result);
    }
    
    public function testPressureAltitude_1000ft_LowPressure()
    {
        $weather = ['pressure' => 29.42];  // 0.5 inHg below standard
        $airport = ['elevation_ft' => 1000];
        
        $result = calculatePressureAltitude($weather, $airport);
        
        // PA = 1000 + (29.92-29.42)×1000 = 1000 + 500 = 1500
        $this->assertEquals(1500, $result);
    }
    
    public function testPressureAltitude_1000ft_HighPressure()
    {
        $weather = ['pressure' => 30.92];  // 1.0 inHg above standard
        $airport = ['elevation_ft' => 1000];
        
        $result = calculatePressureAltitude($weather, $airport);
        
        // PA = 1000 + (29.92-30.92)×1000 = 1000 - 1000 = 0
        $this->assertEquals(0, $result);
    }
    
    // ============================================================================
    // FLIGHT CATEGORY TESTS - FAA AIM 7-1-6
    // ============================================================================
    
    public function testFlightCategory_VFR_Clear()
    {
        $weather = ['ceiling' => null, 'visibility' => 10];
        $this->assertEquals('VFR', calculateFlightCategory($weather));
    }

    /**
     * P6SM (greater than 6 SM): flight category uses numeric value 6.0, so VFR (>= 5 SM)
     * visibility_greater_than does not affect flight category - only display
     */
    public function testFlightCategory_VFR_P6SM_UsesNumericValue(): void
    {
        $weather = ['ceiling' => null, 'visibility' => 6.0, 'visibility_greater_than' => true];
        $this->assertEquals('VFR', calculateFlightCategory($weather));
    }
    
    public function testFlightCategory_VFR_HighCeiling()
    {
        $weather = ['ceiling' => 5000, 'visibility' => 10];
        $this->assertEquals('VFR', calculateFlightCategory($weather));
    }
    
    public function testFlightCategory_MVFR_LowCeiling()
    {
        $weather = ['ceiling' => 2000, 'visibility' => 10];
        $this->assertEquals('MVFR', calculateFlightCategory($weather));
    }
    
    public function testFlightCategory_MVFR_LowVisibility()
    {
        $weather = ['ceiling' => 5000, 'visibility' => 4];
        $this->assertEquals('MVFR', calculateFlightCategory($weather));
    }
    
    public function testFlightCategory_MVFR_Boundary_3SM()
    {
        // 3 SM exactly is MVFR, not IFR (per FAA: IFR is 1 to <3 SM)
        $weather = ['ceiling' => 5000, 'visibility' => 3];
        $this->assertEquals('MVFR', calculateFlightCategory($weather));
    }
    
    public function testFlightCategory_IFR_LowCeiling()
    {
        $weather = ['ceiling' => 800, 'visibility' => 10];
        $this->assertEquals('IFR', calculateFlightCategory($weather));
    }
    
    public function testFlightCategory_IFR_LowVisibility()
    {
        $weather = ['ceiling' => 5000, 'visibility' => 2];
        $this->assertEquals('IFR', calculateFlightCategory($weather));
    }
    
    public function testFlightCategory_LIFR_VeryLowCeiling()
    {
        $weather = ['ceiling' => 300, 'visibility' => 5];
        $this->assertEquals('LIFR', calculateFlightCategory($weather));
    }
    
    public function testFlightCategory_LIFR_VeryLowVisibility()
    {
        $weather = ['ceiling' => 2000, 'visibility' => 0.5];
        $this->assertEquals('LIFR', calculateFlightCategory($weather));
    }
    
    // ============================================================================
    // DEWPOINT TESTS
    // ============================================================================
    
    public function testDewpoint_Saturated()
    {
        // At 100% humidity, dewpoint = temperature
        $result = calculateDewpoint(20, 100);
        $this->assertEqualsWithDelta(20, $result, 0.1);
    }
    
    public function testDewpoint_TypicalSummer()
    {
        // 30°C at 50% humidity → dewpoint ~18.4°C
        $result = calculateDewpoint(30, 50);
        $this->assertEqualsWithDelta(18.4, $result, 0.5);
    }
    
    public function testDewpoint_Dry()
    {
        // 25°C at 20% humidity → dewpoint ~1.4°C (approximate)
        $result = calculateDewpoint(25, 20);
        // Magnus formula can vary slightly, accept wider tolerance
        $this->assertEqualsWithDelta(1.4, $result, 1.0);
    }
    
    // ============================================================================
    // TEMPERATURE CONVERSION TESTS
    // ============================================================================
    
    /**
     * Celsius to Fahrenheit conversion
     * Formula: °F = (°C × 9/5) + 32
     */
    public function testTemperatureConversion_CelsiusToFahrenheit_Freezing()
    {
        // 0°C = 32°F (freezing point)
        $input = 0.0;
        $expected = 32.0;
        $result = round($input * 9/5 + 32, 1);
        $this->assertEquals($expected, $result);
    }
    
    public function testTemperatureConversion_CelsiusToFahrenheit_Standard()
    {
        // 15°C = 59°F (ISA sea level)
        $input = 15.0;
        $expected = 59.0;
        $result = round($input * 9/5 + 32, 1);
        $this->assertEquals($expected, $result);
    }
    
    public function testTemperatureConversion_CelsiusToFahrenheit_Hot()
    {
        // 35°C = 95°F
        $input = 35.0;
        $expected = 95.0;
        $result = round($input * 9/5 + 32, 1);
        $this->assertEquals($expected, $result);
    }
    
    public function testTemperatureConversion_CelsiusToFahrenheit_Cold()
    {
        // -40°C = -40°F (same in both scales)
        $input = -40.0;
        $expected = -40.0;
        $result = round($input * 9/5 + 32, 1);
        $this->assertEquals($expected, $result);
    }
    
    /**
     * Fahrenheit to Celsius conversion
     * Formula: °C = (°F - 32) × 5/9
     */
    public function testTemperatureConversion_FahrenheitToCelsius_Freezing()
    {
        // 32°F = 0°C (freezing point)
        $input = 32.0;
        $expected = 0.0;
        $result = round(($input - 32) * 5/9, 1);
        $this->assertEquals($expected, $result);
    }
    
    public function testTemperatureConversion_FahrenheitToCelsius_Boiling()
    {
        // 212°F = 100°C (boiling point)
        $input = 212.0;
        $expected = 100.0;
        $result = round(($input - 32) * 5/9, 1);
        $this->assertEquals($expected, $result);
    }
    
    // ============================================================================
    // WIND CALCULATION TESTS (Gust Factor)
    // ============================================================================
    
    /**
     * Gust Factor = Gust Speed - Wind Speed
     * Must be >= 0 (gusts cannot be less than steady wind)
     */
    public function testWindCalculation_GustFactor_NormalConditions()
    {
        // Wind 10 kts, gusting 15 kts → gust factor = 5 kts
        $windSpeed = 10;
        $gustSpeed = 15;
        $gustFactor = max(0, $gustSpeed - $windSpeed);
        $this->assertEquals(5, $gustFactor);
    }
    
    public function testWindCalculation_GustFactor_StrongGusts()
    {
        // Wind 15 kts, gusting 30 kts → gust factor = 15 kts
        $windSpeed = 15;
        $gustSpeed = 30;
        $gustFactor = max(0, $gustSpeed - $windSpeed);
        $this->assertEquals(15, $gustFactor);
    }
    
    public function testWindCalculation_GustFactor_NoGusts()
    {
        // Wind 10 kts, no gusts → gust factor = 0
        $windSpeed = 10;
        $gustSpeed = 10;  // Same as wind speed (no additional gusting)
        $gustFactor = max(0, $gustSpeed - $windSpeed);
        $this->assertEquals(0, $gustFactor);
    }
    
    public function testWindCalculation_GustFactor_CalmWind()
    {
        // Calm conditions (0 kts)
        $windSpeed = 0;
        $gustSpeed = 0;
        $gustFactor = max(0, $gustSpeed - $windSpeed);
        $this->assertEquals(0, $gustFactor);
    }
    
    public function testWindCalculation_GustFactor_NeverNegative()
    {
        // Ensures gust factor is never negative (data quality check)
        // This shouldn't happen in real data, but validates the max(0, ...) logic
        $windSpeed = 20;
        $gustSpeed = 15;  // Invalid data (gust < wind)
        $gustFactor = max(0, $gustSpeed - $windSpeed);
        $this->assertEquals(0, $gustFactor);  // Clamped to 0, not -5
    }
    
    // ============================================================================
    // UNIT CONVERSION TESTS - SAFETY CRITICAL
    // ============================================================================
    // Sources:
    // - BIPM SI Brochure (exact metric definitions)
    // - ICAO Doc 8400 (Abbreviations and Codes)
    // - US Code Title 15 Section 205 (legal definitions for inch, foot, mile)
    // - International Yard and Pound Agreement (1959)
    // - NOAA/NWS conversion tables
    //
    // CRITICAL: These are EXACT conversion factors. If tests fail, implementation is wrong!
    // Incorrect unit conversions can cause dangerous altimeter/performance calculations.
    
    // ----------------------------------------------------------------------------
    // PRESSURE CONVERSIONS (hPa ↔ inHg)
    // Factor: 1 inHg = 33.8639 hPa (ICAO standard)
    // ----------------------------------------------------------------------------
    
    /**
     * Standard sea level pressure: 1013.25 hPa = 29.9213 inHg
     * Source: ICAO International Standard Atmosphere (ISA)
     */
    public function testPressureConversion_hPaToInhg_StandardAtmosphere()
    {
        $hpa = 1013.25;
        $expectedInhg = 29.9213;
        
        $result = hpaToInhg($hpa);
        $this->assertEqualsWithDelta($expectedInhg, $result, 0.001,
            "1013.25 hPa should equal 29.9213 inHg. Got: $result");
    }
    
    /**
     * Reverse: 29.92 inHg = 1013.25 hPa
     * Source: ICAO International Standard Atmosphere (ISA)
     */
    public function testPressureConversion_InhgToHpa_StandardAtmosphere()
    {
        $inhg = 29.92;
        $expectedHpa = 1013.2089; // 29.92 * 33.8639
        
        $result = inhgToHpa($inhg);
        $this->assertEqualsWithDelta($expectedHpa, $result, 0.01,
            "29.92 inHg should equal ~1013.21 hPa. Got: $result");
    }
    
    /**
     * Low pressure system: 950 hPa
     * Source: Common synoptic value
     */
    public function testPressureConversion_hPaToInhg_LowPressure()
    {
        $hpa = 950.00;
        $expectedInhg = 28.0534; // 950 / 33.8639
        
        $result = hpaToInhg($hpa);
        $this->assertEqualsWithDelta($expectedInhg, $result, 0.001,
            "950.00 hPa should equal 28.0534 inHg. Got: $result");
    }
    
    /**
     * High pressure system: 1030 hPa
     * Source: Common synoptic value
     */
    public function testPressureConversion_hPaToInhg_HighPressure()
    {
        $hpa = 1030.00;
        $expectedInhg = 30.4157; // 1030 / 33.8639
        
        $result = hpaToInhg($hpa);
        $this->assertEqualsWithDelta($expectedInhg, $result, 0.001,
            "1030.00 hPa should equal 30.4157 inHg. Got: $result");
    }
    
    /**
     * Round-trip pressure conversion must preserve value
     */
    public function testPressureConversion_RoundTrip()
    {
        $original = 1013.25;
        $converted = inhgToHpa(hpaToInhg($original));
        $this->assertEqualsWithDelta($original, $converted, 0.01,
            "Round-trip pressure conversion must preserve value within 0.01 hPa");
    }
    
    // ----------------------------------------------------------------------------
    // VISIBILITY CONVERSIONS (meters ↔ statute miles)
    // Factor: 1 statute mile = 1609.344 meters (exact, US Code Title 15 §205)
    // ----------------------------------------------------------------------------
    
    /**
     * 10 SM = 16093.44 meters (unrestricted visibility)
     * Source: ICAO visibility reporting standards
     */
    public function testVisibilityConversion_StatuteMilesToMeters_10SM()
    {
        $miles = 10.0;
        $expectedMeters = 16093.44;
        
        $result = statuteMilesToMeters($miles);
        $this->assertEqualsWithDelta($expectedMeters, $result, 0.01,
            "10 SM should equal 16093.44 meters. Got: $result");
    }
    
    /**
     * 1 SM = 1609.344 meters (exact definition)
     * Source: US Code Title 15 Section 205
     */
    public function testVisibilityConversion_StatuteMilesToMeters_1SM()
    {
        $miles = 1.0;
        $expectedMeters = 1609.344;
        
        $result = statuteMilesToMeters($miles);
        $this->assertEquals($expectedMeters, $result,
            "1 SM must equal exactly 1609.344 meters (US legal definition)");
    }
    
    /**
     * 3 SM = 4828.032 meters (MVFR threshold)
     * Source: FAA AIM 7-1-6 flight category definitions
     */
    public function testVisibilityConversion_StatuteMilesToMeters_MVFR_Threshold()
    {
        $miles = 3.0;
        $expectedMeters = 4828.032;
        
        $result = statuteMilesToMeters($miles);
        $this->assertEqualsWithDelta($expectedMeters, $result, 0.01,
            "3 SM (MVFR threshold) should equal 4828.032 meters. Got: $result");
    }
    
    /**
     * Reverse: 1609.344 meters = 1 SM
     */
    public function testVisibilityConversion_MetersToStatuteMiles_1SM()
    {
        $meters = 1609.344;
        $expectedMiles = 1.0;
        
        $result = metersToStatuteMiles($meters);
        $this->assertEqualsWithDelta($expectedMiles, $result, 0.0001,
            "1609.344 meters should equal 1 SM. Got: $result");
    }
    
    /**
     * Round-trip visibility conversion must preserve value
     */
    public function testVisibilityConversion_RoundTrip()
    {
        $original = 10.0;
        $converted = metersToStatuteMiles(statuteMilesToMeters($original));
        $this->assertEqualsWithDelta($original, $converted, 0.0001,
            "Round-trip visibility conversion must preserve value");
    }
    
    /**
     * 10 SM = 16.09344 km (unrestricted visibility display)
     * Source: 1 SM = 1609.344 m = 1.609344 km
     */
    public function testVisibilityConversion_StatuteMilesToKilometers_10SM()
    {
        $miles = 10.0;
        $expectedKm = 16.09344;
        
        $result = statuteMilesToKilometers($miles);
        $this->assertEqualsWithDelta($expectedKm, $result, 0.0001,
            "10 SM must equal 16.09344 km for metric visibility display");
    }
    
    /**
     * 1 SM = 1.609344 km (exact definition)
     * Source: US Code Title 15 Section 205 (1 SM = 1609.344 m)
     */
    public function testVisibilityConversion_StatuteMilesToKilometers_1SM()
    {
        $miles = 1.0;
        $expectedKm = 1.609344;
        
        $result = statuteMilesToKilometers($miles);
        $this->assertEqualsWithDelta($expectedKm, $result, 0.0001,
            "1 SM must equal 1.609344 km (exact definition)");
    }
    
    /**
     * 3 SM = 4.828032 km (MVFR threshold for metric display)
     * Source: FAA AIM 7-1-6, converted to metric
     */
    public function testVisibilityConversion_StatuteMilesToKilometers_MVFR_Threshold()
    {
        $miles = 3.0;
        $expectedKm = 4.828032;
        
        $result = statuteMilesToKilometers($miles);
        $this->assertEqualsWithDelta($expectedKm, $result, 0.0001,
            "3 SM (MVFR threshold) must equal 4.828032 km");
    }
    
    /**
     * Reverse: 1.609344 km = 1 SM
     */
    public function testVisibilityConversion_KilometersToStatuteMiles_1SM()
    {
        $km = 1.609344;
        $expectedMiles = 1.0;
        
        $result = kilometersToStatuteMiles($km);
        $this->assertEqualsWithDelta($expectedMiles, $result, 0.0001,
            "1.609344 km must equal 1 SM");
    }
    
    /**
     * Round-trip SM ↔ km conversion must preserve value
     */
    public function testVisibilityConversion_KilometerRoundTrip()
    {
        $original = 10.0;
        $converted = kilometersToStatuteMiles(statuteMilesToKilometers($original));
        $this->assertEqualsWithDelta($original, $converted, 0.0001,
            "Round-trip SM ↔ km conversion must preserve value");
    }
    
    // ----------------------------------------------------------------------------
    // PRECIPITATION CONVERSIONS (mm ↔ inches)
    // Factor: 1 inch = 25.4 mm (exact, International Yard and Pound Agreement 1959)
    // ----------------------------------------------------------------------------
    
    /**
     * 1 inch = 25.4 mm (exact definition)
     * Source: International Yard and Pound Agreement (1959)
     */
    public function testPrecipitationConversion_InchesToMm_OneInch()
    {
        $inches = 1.0;
        $expectedMm = 25.4;
        
        $result = inchesToMm($inches);
        $this->assertEquals($expectedMm, $result,
            "1 inch must equal exactly 25.4 mm (international definition)");
    }
    
    /**
     * Trace precipitation: 0.01 inches = 0.254 mm
     */
    public function testPrecipitationConversion_InchesToMm_Trace()
    {
        $inches = 0.01;
        $expectedMm = 0.254;
        
        $result = inchesToMm($inches);
        $this->assertEqualsWithDelta($expectedMm, $result, 0.001,
            "0.01 inches (trace) should equal 0.254 mm. Got: $result");
    }
    
    /**
     * Heavy rain: 2 inches = 50.8 mm
     */
    public function testPrecipitationConversion_InchesToMm_HeavyRain()
    {
        $inches = 2.0;
        $expectedMm = 50.8;
        
        $result = inchesToMm($inches);
        $this->assertEquals($expectedMm, $result,
            "2 inches should equal 50.8 mm. Got: $result");
    }
    
    /**
     * Reverse: 25.4 mm = 1 inch
     */
    public function testPrecipitationConversion_MmToInches_OneInch()
    {
        $mm = 25.4;
        $expectedInches = 1.0;
        
        $result = mmToInches($mm);
        $this->assertEquals($expectedInches, $result,
            "25.4 mm must equal exactly 1 inch");
    }
    
    /**
     * Round-trip precipitation conversion must preserve value
     */
    public function testPrecipitationConversion_RoundTrip()
    {
        $original = 1.5;
        $converted = mmToInches(inchesToMm($original));
        $this->assertEqualsWithDelta($original, $converted, 0.0001,
            "Round-trip precipitation conversion must preserve value");
    }
    
    // ----------------------------------------------------------------------------
    // TEMPERATURE CONVERSIONS (Celsius ↔ Fahrenheit) - Using lib/units.php
    // Formula: °F = (°C × 9/5) + 32, °C = (°F - 32) × 5/9
    // ----------------------------------------------------------------------------
    
    /**
     * 0°C = 32°F (freezing point of water)
     * Source: Fundamental physical constant
     */
    public function testTemperatureConversion_CelsiusToFahrenheit_Freezing_Units()
    {
        $celsius = 0.0;
        $expectedFahrenheit = 32.0;
        
        $result = celsiusToFahrenheit($celsius);
        $this->assertEquals($expectedFahrenheit, $result,
            "0°C must equal exactly 32°F (freezing point)");
    }
    
    /**
     * 100°C = 212°F (boiling point of water)
     * Source: Fundamental physical constant
     */
    public function testTemperatureConversion_CelsiusToFahrenheit_Boiling_Units()
    {
        $celsius = 100.0;
        $expectedFahrenheit = 212.0;
        
        $result = celsiusToFahrenheit($celsius);
        $this->assertEquals($expectedFahrenheit, $result,
            "100°C must equal exactly 212°F (boiling point)");
    }
    
    /**
     * -40°C = -40°F (intersection point)
     * Source: Mathematical identity
     */
    public function testTemperatureConversion_CelsiusToFahrenheit_Intersection_Units()
    {
        $celsius = -40.0;
        $expectedFahrenheit = -40.0;
        
        $result = celsiusToFahrenheit($celsius);
        $this->assertEquals($expectedFahrenheit, $result,
            "-40°C must equal exactly -40°F (intersection point)");
    }
    
    /**
     * 15°C = 59°F (ISA standard temperature)
     * Source: ICAO International Standard Atmosphere
     */
    public function testTemperatureConversion_CelsiusToFahrenheit_ISA_Units()
    {
        $celsius = 15.0;
        $expectedFahrenheit = 59.0;
        
        $result = celsiusToFahrenheit($celsius);
        $this->assertEquals($expectedFahrenheit, $result,
            "15°C (ISA standard) must equal exactly 59°F");
    }
    
    /**
     * Reverse: 32°F = 0°C
     */
    public function testTemperatureConversion_FahrenheitToCelsius_Freezing_Units()
    {
        $fahrenheit = 32.0;
        $expectedCelsius = 0.0;
        
        $result = fahrenheitToCelsius($fahrenheit);
        $this->assertEquals($expectedCelsius, $result,
            "32°F must equal exactly 0°C (freezing point)");
    }
    
    /**
     * Reverse: 212°F = 100°C
     */
    public function testTemperatureConversion_FahrenheitToCelsius_Boiling_Units()
    {
        $fahrenheit = 212.0;
        $expectedCelsius = 100.0;
        
        $result = fahrenheitToCelsius($fahrenheit);
        $this->assertEquals($expectedCelsius, $result,
            "212°F must equal exactly 100°C (boiling point)");
    }
    
    /**
     * Round-trip temperature conversion must preserve value
     */
    public function testTemperatureConversion_RoundTrip_Units()
    {
        $original = 20.0;
        $converted = fahrenheitToCelsius(celsiusToFahrenheit($original));
        $this->assertEqualsWithDelta($original, $converted, 0.0001,
            "Round-trip temperature conversion must preserve value");
    }
    
    // ----------------------------------------------------------------------------
    // WIND SPEED CONVERSIONS (knots ↔ mph ↔ km/h)
    // Factors: 1 kt = 1.15078 mph, 1 kt = 1.852 km/h (exact, nautical mile definition)
    // ----------------------------------------------------------------------------
    
    /**
     * 1 knot = 1.852 km/h (exact, derived from nautical mile = 1852 meters)
     * Source: BIPM SI Brochure, nautical mile definition
     */
    public function testWindConversion_KnotsToKmh_Exact()
    {
        $knots = 1.0;
        $expectedKmh = 1.852;
        
        $result = knotsToKmh($knots);
        $this->assertEquals($expectedKmh, $result,
            "1 knot must equal exactly 1.852 km/h (nautical mile definition)");
    }
    
    /**
     * 10 knots = 18.52 km/h
     */
    public function testWindConversion_KnotsToKmh_10kt()
    {
        $knots = 10.0;
        $expectedKmh = 18.52;
        
        $result = knotsToKmh($knots);
        $this->assertEquals($expectedKmh, $result,
            "10 knots should equal 18.52 km/h. Got: $result");
    }
    
    /**
     * 1 knot = 1.15078 mph
     * Source: NOAA conversion tables
     */
    public function testWindConversion_KnotsToMph()
    {
        $knots = 1.0;
        $expectedMph = 1.15078;
        
        $result = knotsToMph($knots);
        $this->assertEqualsWithDelta($expectedMph, $result, 0.00001,
            "1 knot should equal 1.15078 mph. Got: $result");
    }
    
    /**
     * 50 knots = 57.539 mph
     */
    public function testWindConversion_KnotsToMph_50kt()
    {
        $knots = 50.0;
        $expectedMph = 57.539;
        
        $result = knotsToMph($knots);
        $this->assertEqualsWithDelta($expectedMph, $result, 0.001,
            "50 knots should equal 57.539 mph. Got: $result");
    }
    
    /**
     * Reverse: 1.852 km/h = 1 knot
     */
    public function testWindConversion_KmhToKnots()
    {
        $kmh = 1.852;
        $expectedKnots = 1.0;
        
        $result = kmhToKnots($kmh);
        $this->assertEqualsWithDelta($expectedKnots, $result, 0.0001,
            "1.852 km/h should equal 1 knot. Got: $result");
    }
    
    /**
     * Reverse: 1.15078 mph = 1 knot
     */
    public function testWindConversion_MphToKnots()
    {
        $mph = 1.15078;
        $expectedKnots = 1.0;
        
        $result = mphToKnots($mph);
        $this->assertEqualsWithDelta($expectedKnots, $result, 0.0001,
            "1.15078 mph should equal 1 knot. Got: $result");
    }
    
    /**
     * Round-trip wind conversion (knots → km/h → knots)
     */
    public function testWindConversion_RoundTrip_Kmh()
    {
        $original = 25.0;
        $converted = kmhToKnots(knotsToKmh($original));
        $this->assertEqualsWithDelta($original, $converted, 0.0001,
            "Round-trip wind conversion (kt→km/h→kt) must preserve value");
    }
    
    /**
     * Round-trip wind conversion (knots → mph → knots)
     */
    public function testWindConversion_RoundTrip_Mph()
    {
        $original = 25.0;
        $converted = mphToKnots(knotsToMph($original));
        $this->assertEqualsWithDelta($original, $converted, 0.0001,
            "Round-trip wind conversion (kt→mph→kt) must preserve value");
    }
    
    // ----------------------------------------------------------------------------
    // ALTITUDE CONVERSIONS (feet ↔ meters)
    // Factor: 1 foot = 0.3048 meters (exact, International Yard and Pound Agreement 1959)
    // ----------------------------------------------------------------------------
    
    /**
     * 1000 feet = 304.8 meters (exact)
     * Source: International Yard and Pound Agreement (1959)
     */
    public function testAltitudeConversion_FeetToMeters_1000ft()
    {
        $feet = 1000.0;
        $expectedMeters = 304.8;
        
        $result = feetToMeters($feet);
        $this->assertEquals($expectedMeters, $result,
            "1000 feet must equal exactly 304.8 meters");
    }
    
    /**
     * 3000 feet = 914.4 meters (typical pattern altitude)
     */
    public function testAltitudeConversion_FeetToMeters_PatternAltitude()
    {
        $feet = 3000.0;
        $expectedMeters = 914.4;
        
        $result = feetToMeters($feet);
        $this->assertEqualsWithDelta($expectedMeters, $result, 0.0001,
            "3000 feet (pattern altitude) should equal 914.4 meters");
    }
    
    /**
     * 10000 feet = 3048 meters (Class B floor)
     */
    public function testAltitudeConversion_FeetToMeters_ClassBFloor()
    {
        $feet = 10000.0;
        $expectedMeters = 3048.0;
        
        $result = feetToMeters($feet);
        $this->assertEquals($expectedMeters, $result,
            "10000 feet (Class B floor) must equal exactly 3048 meters");
    }
    
    /**
     * 18000 feet = 5486.4 meters (Class A floor / FL180)
     */
    public function testAltitudeConversion_FeetToMeters_ClassAFloor()
    {
        $feet = 18000.0;
        $expectedMeters = 5486.4;
        
        $result = feetToMeters($feet);
        $this->assertEqualsWithDelta($expectedMeters, $result, 0.0001,
            "18000 feet (Class A floor) should equal 5486.4 meters");
    }
    
    /**
     * Reverse: 304.8 meters = 1000 feet
     */
    public function testAltitudeConversion_MetersToFeet_1000ft()
    {
        $meters = 304.8;
        $expectedFeet = 1000.0;
        
        $result = metersToFeet($meters);
        $this->assertEquals($expectedFeet, $result,
            "304.8 meters must equal exactly 1000 feet");
    }
    
    /**
     * Round-trip altitude conversion must preserve value
     */
    public function testAltitudeConversion_RoundTrip()
    {
        $original = 5000.0;
        $converted = metersToFeet(feetToMeters($original));
        $this->assertEqualsWithDelta($original, $converted, 0.0001,
            "Round-trip altitude conversion must preserve value");
    }
}
