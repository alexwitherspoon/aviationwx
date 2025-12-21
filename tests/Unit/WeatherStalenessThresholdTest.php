<?php

require_once __DIR__ . '/../../lib/weather/staleness.php';
require_once __DIR__ . '/../../lib/constants.php';

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for weather staleness threshold calculations
 * 
 * Tests the logic for calculating warning and error thresholds for weather data staleness.
 * This logic is used in both PHP (server-side) and JavaScript (client-side) and must match exactly.
 */
class WeatherStalenessThresholdTest extends TestCase
{
    /**
     * Test METAR-only source thresholds (hour-based)
     */
    public function testMetarOnlyThresholds()
    {
        $thresholds = calculateWeatherStalenessThresholds(true, 60);
        
        // METAR thresholds should be hour-based, not dependent on refresh interval
        $expectedWarning = WEATHER_STALENESS_WARNING_HOURS_METAR * 3600;
        $expectedError = WEATHER_STALENESS_ERROR_HOURS_METAR * 3600;
        
        $this->assertEquals($expectedWarning, $thresholds['warning'], 'METAR warning threshold should be based on hours');
        $this->assertEquals($expectedError, $thresholds['error'], 'METAR error threshold should be based on hours');
        
        // Verify thresholds are reasonable
        $this->assertGreaterThan(0, $thresholds['warning'], 'Warning threshold should be positive');
        $this->assertGreaterThan(0, $thresholds['error'], 'Error threshold should be positive');
        $this->assertLessThan($thresholds['error'], $thresholds['warning'], 'Warning threshold should be less than error threshold');
    }
    
    /**
     * Test primary source thresholds (multiplier-based)
     */
    public function testPrimarySourceThresholds()
    {
        $refreshInterval = 60;
        $thresholds = calculateWeatherStalenessThresholds(false, $refreshInterval);
        
        // Primary source thresholds should be multiplier-based
        $expectedWarning = $refreshInterval * WEATHER_STALENESS_WARNING_MULTIPLIER;
        $expectedError = $refreshInterval * WEATHER_STALENESS_ERROR_MULTIPLIER;
        
        $this->assertEquals($expectedWarning, $thresholds['warning'], 'Primary source warning threshold should be multiplier-based');
        $this->assertEquals($expectedError, $thresholds['error'], 'Primary source error threshold should be multiplier-based');
        
        // Verify thresholds are reasonable
        $this->assertGreaterThan(0, $thresholds['warning'], 'Warning threshold should be positive');
        $this->assertGreaterThan(0, $thresholds['error'], 'Error threshold should be positive');
        $this->assertLessThan($thresholds['error'], $thresholds['warning'], 'Warning threshold should be less than error threshold');
    }
    
    /**
     * Test primary source thresholds with different refresh intervals
     */
    public function testPrimarySourceThresholdsWithDifferentIntervals()
    {
        // Test with 30 second refresh
        $thresholds30 = calculateWeatherStalenessThresholds(false, 30);
        $this->assertEquals(30 * WEATHER_STALENESS_WARNING_MULTIPLIER, $thresholds30['warning']);
        $this->assertEquals(30 * WEATHER_STALENESS_ERROR_MULTIPLIER, $thresholds30['error']);
        
        // Test with 120 second refresh
        $thresholds120 = calculateWeatherStalenessThresholds(false, 120);
        $this->assertEquals(120 * WEATHER_STALENESS_WARNING_MULTIPLIER, $thresholds120['warning']);
        $this->assertEquals(120 * WEATHER_STALENESS_ERROR_MULTIPLIER, $thresholds120['error']);
        
        // Verify 120 second thresholds are larger than 30 second thresholds
        $this->assertGreaterThan($thresholds30['warning'], $thresholds120['warning']);
        $this->assertGreaterThan($thresholds30['error'], $thresholds120['error']);
    }
    
    /**
     * Test that METAR thresholds are independent of refresh interval
     */
    public function testMetarThresholdsIndependentOfRefreshInterval()
    {
        $thresholds60 = calculateWeatherStalenessThresholds(true, 60);
        $thresholds120 = calculateWeatherStalenessThresholds(true, 120);
        $thresholds30 = calculateWeatherStalenessThresholds(true, 30);
        
        // METAR thresholds should be the same regardless of refresh interval
        $this->assertEquals($thresholds60['warning'], $thresholds120['warning'], 'METAR warning threshold should be independent of refresh interval');
        $this->assertEquals($thresholds60['error'], $thresholds120['error'], 'METAR error threshold should be independent of refresh interval');
        $this->assertEquals($thresholds60['warning'], $thresholds30['warning'], 'METAR warning threshold should be independent of refresh interval');
        $this->assertEquals($thresholds60['error'], $thresholds30['error'], 'METAR error threshold should be independent of refresh interval');
    }
    
    /**
     * Test minimum refresh interval handling
     */
    public function testMinimumRefreshInterval()
    {
        // Test with 0 (should default to 1)
        $thresholds0 = calculateWeatherStalenessThresholds(false, 0);
        $this->assertGreaterThan(0, $thresholds0['warning'], 'Should handle 0 refresh interval');
        $this->assertGreaterThan(0, $thresholds0['error'], 'Should handle 0 refresh interval');
        
        // Test with negative (should default to 1)
        $thresholdsNeg = calculateWeatherStalenessThresholds(false, -10);
        $this->assertGreaterThan(0, $thresholdsNeg['warning'], 'Should handle negative refresh interval');
        $this->assertGreaterThan(0, $thresholdsNeg['error'], 'Should handle negative refresh interval');
        
        // Test with 1 (minimum valid)
        $thresholds1 = calculateWeatherStalenessThresholds(false, 1);
        $this->assertEquals(1 * WEATHER_STALENESS_WARNING_MULTIPLIER, $thresholds1['warning']);
        $this->assertEquals(1 * WEATHER_STALENESS_ERROR_MULTIPLIER, $thresholds1['error']);
    }
    
    /**
     * Test that thresholds match JavaScript constants
     * 
     * This ensures the PHP function matches the JavaScript logic exactly.
     */
    public function testThresholdsMatchJavaScriptLogic()
    {
        // Test METAR-only (JavaScript uses WEATHER_STALENESS_WARNING_HOURS_METAR * SECONDS_PER_HOUR)
        $metarThresholds = calculateWeatherStalenessThresholds(true, 60);
        $expectedMetarWarning = WEATHER_STALENESS_WARNING_HOURS_METAR * 3600;
        $expectedMetarError = WEATHER_STALENESS_ERROR_HOURS_METAR * 3600;
        $this->assertEquals($expectedMetarWarning, $metarThresholds['warning'], 'METAR warning should match JavaScript logic');
        $this->assertEquals($expectedMetarError, $metarThresholds['error'], 'METAR error should match JavaScript logic');
        
        // Test primary source (JavaScript uses refreshSeconds * WEATHER_STALENESS_WARNING_MULTIPLIER)
        $primaryThresholds = calculateWeatherStalenessThresholds(false, 60);
        $expectedPrimaryWarning = 60 * WEATHER_STALENESS_WARNING_MULTIPLIER;
        $expectedPrimaryError = 60 * WEATHER_STALENESS_ERROR_MULTIPLIER;
        $this->assertEquals($expectedPrimaryWarning, $primaryThresholds['warning'], 'Primary warning should match JavaScript logic');
        $this->assertEquals($expectedPrimaryError, $primaryThresholds['error'], 'Primary error should match JavaScript logic');
    }
}

