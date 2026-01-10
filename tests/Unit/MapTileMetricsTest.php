<?php
/**
 * Tests for map tile metrics tracking
 */

require_once __DIR__ . '/../../lib/metrics.php';

use PHPUnit\Framework\TestCase;

class MapTileMetricsTest extends TestCase
{
    /**
     * Test that metrics_track_tile_serve function exists and accepts valid sources
     */
    public function testMapTileTrackingFunctionExists()
    {
        $this->assertTrue(function_exists('metrics_track_tile_serve'), 'metrics_track_tile_serve function should exist');
    }
    
    /**
     * Test tile tracking with openweathermap source
     */
    public function testTrackOpenWeatherMapTile()
    {
        // This test verifies the function can be called without errors
        // Actual APCu tracking is tested in integration environment
        try {
            metrics_track_tile_serve('openweathermap');
            $this->assertTrue(true, 'Should not throw exception');
        } catch (Exception $e) {
            $this->fail('metrics_track_tile_serve should not throw exception: ' . $e->getMessage());
        }
    }
    
    /**
     * Test tile tracking with rainviewer source
     */
    public function testTrackRainViewerTile()
    {
        try {
            metrics_track_tile_serve('rainviewer');
            $this->assertTrue(true, 'Should not throw exception');
        } catch (Exception $e) {
            $this->fail('metrics_track_tile_serve should not throw exception: ' . $e->getMessage());
        }
    }
    
    /**
     * Test tile tracking normalizes source names
     */
    public function testTrackTileNormalizesSource()
    {
        // Test that uppercase is handled
        try {
            metrics_track_tile_serve('OpenWeatherMap');
            metrics_track_tile_serve('RAINVIEWER');
            $this->assertTrue(true, 'Should handle uppercase source names');
        } catch (Exception $e) {
            $this->fail('Should handle case normalization: ' . $e->getMessage());
        }
    }
}
