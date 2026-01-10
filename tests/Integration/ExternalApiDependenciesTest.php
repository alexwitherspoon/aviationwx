<?php
/**
 * External API Dependencies Test
 * 
 * Tests upstream API dependencies to detect breaking changes early.
 * These tests verify that external services we rely on are still accessible
 * and returning data in the expected format.
 * 
 * Skipped in test mode to avoid external network calls during CI.
 */

use PHPUnit\Framework\TestCase;

class ExternalApiDependenciesTest extends TestCase
{
    /**
     * Test RainViewer API is accessible and returns expected data
     */
    public function testRainViewerApi_IsAccessibleAndReturnsExpectedData()
    {
        if (isTestMode()) {
            $this->markTestSkipped('Skipping external API test in test mode');
        }
        
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('cURL not available');
        }
        
        $url = 'https://api.rainviewer.com/public/weather-maps.json';
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        $this->assertEquals(
            200,
            $httpCode,
            "RainViewer API should return 200 OK (got: $httpCode, error: $error)"
        );
        
        $this->assertNotEmpty($response, 'RainViewer API should return data');
        
        $data = json_decode($response, true);
        $this->assertNotNull($data, 'RainViewer response should be valid JSON');
        $this->assertIsArray($data, 'RainViewer response should be an array');
        
        // Check for expected structure
        $this->assertArrayHasKey('radar', $data, 'Response should have radar key');
        $this->assertArrayHasKey('past', $data['radar'], 'Radar should have past frames');
        $this->assertIsArray($data['radar']['past'], 'Past frames should be an array');
        $this->assertNotEmpty($data['radar']['past'], 'Should have at least one past radar frame');
        
        // Check first frame has expected fields
        $firstFrame = $data['radar']['past'][0];
        $this->assertArrayHasKey('time', $firstFrame, 'Frame should have time field');
        $this->assertIsInt($firstFrame['time'], 'Time should be an integer (timestamp)');
    }
    
    /**
     * Test OpenWeatherMap clouds tile layer is accessible
     */
    public function testOpenWeatherMapCloudsTiles_AreAccessible()
    {
        if (isTestMode()) {
            $this->markTestSkipped('Skipping external API test in test mode');
        }
        
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('cURL not available');
        }
        
        // Test a sample tile (zoom 0, x 0, y 0 - world view)
        $url = 'https://tile.openweathermap.org/map/clouds_new/0/0/0.png?appid=439d4b804bc8187953eb36d2a8c26a02';
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_NOBODY, true); // HEAD request only
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_error($ch);
        curl_close($ch);
        
        $this->assertEquals(
            200,
            $httpCode,
            "OpenWeatherMap tiles should return 200 OK (got: $httpCode, error: $error)"
        );
        
        $this->assertStringContainsString(
            'image/',
            $contentType,
            "OpenWeatherMap tiles should return image content type (got: $contentType)"
        );
    }
    
    /**
     * Test aviationweather.gov METAR API is accessible
     */
    public function testAviationWeatherGov_MetarApiIsAccessible()
    {
        if (isTestMode()) {
            $this->markTestSkipped('Skipping external API test in test mode');
        }
        
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('cURL not available');
        }
        
        // Use a well-known airport (KJFK - JFK International)
        $url = 'https://aviationweather.gov/api/data/metar?ids=KJFK&format=json&taf=false&hours=0';
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'User-Agent: AviationWX/1.0 (Test Suite)',
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_error($ch);
        curl_close($ch);
        
        $this->assertEquals(
            200,
            $httpCode,
            "aviationweather.gov METAR API should return 200 OK (got: $httpCode, error: $error)"
        );
        
        $this->assertStringContainsString(
            'application/json',
            $contentType,
            "METAR API should return JSON (got: $contentType)"
        );
        
        $this->assertNotEmpty($response, 'METAR API should return data');
        
        $data = json_decode($response, true);
        $this->assertNotNull($data, 'METAR response should be valid JSON');
        $this->assertIsArray($data, 'METAR response should be an array');
        $this->assertNotEmpty($data, 'Should have at least one METAR record');
        
        // Check first METAR has expected fields
        $firstMetar = $data[0];
        $this->assertArrayHasKey('icaoId', $firstMetar, 'METAR should have icaoId field');
        $this->assertArrayHasKey('rawOb', $firstMetar, 'METAR should have rawOb field');
        $this->assertArrayHasKey('obsTime', $firstMetar, 'METAR should have obsTime field');
        
        $this->assertEquals('KJFK', $firstMetar['icaoId'], 'Should return data for requested station');
    }
    
    /**
     * Test Leaflet.js CDN is accessible
     */
    public function testLeafletJsCdn_IsAccessible()
    {
        if (isTestMode()) {
            $this->markTestSkipped('Skipping external CDN test in test mode');
        }
        
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('cURL not available');
        }
        
        $url = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_NOBODY, true); // HEAD request only
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        $this->assertEquals(
            200,
            $httpCode,
            "Leaflet.js CDN should return 200 OK (got: $httpCode, error: $error)"
        );
    }
    
    /**
     * Test Leaflet MarkerCluster plugin CDN is accessible
     */
    public function testLeafletMarkerClusterCdn_IsAccessible()
    {
        if (isTestMode()) {
            $this->markTestSkipped('Skipping external CDN test in test mode');
        }
        
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('cURL not available');
        }
        
        $url = 'https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js';
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_NOBODY, true); // HEAD request only
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        $this->assertEquals(
            200,
            $httpCode,
            "Leaflet MarkerCluster CDN should return 200 OK (got: $httpCode, error: $error)"
        );
    }
    
    /**
     * Test RainViewer tiles are accessible (actual tile request)
     */
    public function testRainViewerTiles_AreAccessible()
    {
        if (isTestMode()) {
            $this->markTestSkipped('Skipping external API test in test mode');
        }
        
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('cURL not available');
        }
        
        // First get a valid timestamp
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.rainviewer.com/public/weather-maps.json');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        curl_close($ch);
        
        $data = json_decode($response, true);
        if (!$data || !isset($data['radar']['past'][0]['time'])) {
            $this->markTestSkipped('Could not fetch radar timestamp');
        }
        
        $timestamp = $data['radar']['past'][0]['time'];
        
        // Now test tile access (low zoom, world view)
        $tileUrl = "https://tilecache.rainviewer.com/v2/radar/{$timestamp}/256/0/0/0/6/1_1.png";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $tileUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_NOBODY, true); // HEAD request only
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        
        $this->assertEquals(
            200,
            $httpCode,
            "RainViewer tiles should return 200 OK (got: $httpCode)"
        );
        
        $this->assertStringContainsString(
            'image/',
            $contentType,
            "RainViewer tiles should return image content type (got: $contentType)"
        );
    }
}
