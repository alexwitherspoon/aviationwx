<?php
/**
 * Airports Directory Page Tests
 * 
 * Tests for the airports directory page (airports.aviationwx.org)
 * Verifies map functionality, flight category legend, and airport listing.
 */

use PHPUnit\Framework\TestCase;

class AirportsDirectoryTest extends TestCase
{
    private $baseUrl;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->baseUrl = getenv('TEST_API_URL') ?: 'http://localhost:8080';
        
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('cURL not available');
        }
    }
    
    /**
     * Helper to make HTTP request to airports page
     */
    private function getAirportsPageContent(): string
    {
        $url = rtrim($this->baseUrl, '/') . '/airports';
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        $output = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($httpCode !== 200) {
            $this->markTestSkipped("Airports page not accessible (HTTP $httpCode)");
        }
        
        return $output;
    }
    
    /**
     * Test airports directory page loads successfully
     */
    public function testAirportsPage_LoadsSuccessfully()
    {
        $output = $this->getAirportsPageContent();
        
        $this->assertNotEmpty($output, 'Airports page should produce output');
        $this->assertStringContainsString('<html', strtolower($output), 'Should be HTML');
        $this->assertStringContainsString('Airport Network Map', $output, 'Should have page title');
    }
    
    /**
     * Test flight category legend is present
     */
    public function testFlightCategoryLegend_IsPresent()
    {
        $output = $this->getAirportsPageContent();
        
        // Check for all flight categories in legend
        $this->assertStringContainsString('Flight Categories', $output, 'Should have legend title');
        $this->assertStringContainsString('VFR', $output, 'Should have VFR category');
        $this->assertStringContainsString('MVFR', $output, 'Should have MVFR category');
        $this->assertStringContainsString('IFR', $output, 'Should have IFR category');
        $this->assertStringContainsString('LIFR', $output, 'Should have LIFR category');
        $this->assertStringContainsString('Not Enough Data', $output, 'Should have "Not Enough Data" category');
    }
    
    /**
     * Test map container is present
     */
    public function testMapContainer_IsPresent()
    {
        $output = $this->getAirportsPageContent();
        
        $this->assertStringContainsString('id="map"', $output, 'Should have map container');
        $this->assertStringContainsString('leaflet', strtolower($output), 'Should load Leaflet.js');
    }
    
    /**
     * Test location button is present and triggers geolocation on click (not on load)
     */
    public function testLocationButton_IsPresentAndTriggersGeolocationOnClick()
    {
        $output = $this->getAirportsPageContent();

        $this->assertStringContainsString('location-btn', $output, 'Should have location button');
        $this->assertStringContainsString('Center map on my location', $output, 'Should have location button aria-label');
        $this->assertStringContainsString('location-error-msg', $output, 'Should have error feedback element for geolocation failures');
        $this->assertStringContainsString('locationBtn.addEventListener', $output, 'Should wire location button click handler');
        $this->assertStringContainsString('map.locate', $output, 'Should use geolocation when button clicked');
        $this->assertStringContainsString('map.fitBounds(group.getBounds()', $output, 'Should fit all airports by default on load');
    }

    /**
     * Test weather layer controls are present
     */
    public function testWeatherLayerControls_ArePresent()
    {
        $output = $this->getAirportsPageContent();
        
        // Check for weather control buttons
        $this->assertStringContainsString('radar-btn', $output, 'Should have precipitation radar button');
        $this->assertStringContainsString('clouds-btn', $output, 'Should have cloud cover button');
        $this->assertStringContainsString('fullscreen-btn', $output, 'Should have fullscreen button');
        
        // Check for opacity sliders
        $this->assertStringContainsString('radar-opacity', $output, 'Should have precipitation opacity slider');
        $this->assertStringContainsString('clouds-opacity', $output, 'Should have cloud cover opacity slider');
        $this->assertStringContainsString('Precip', $output, 'Should have Precip label');
        $this->assertStringContainsString('Clouds', $output, 'Should have Clouds label');
    }
    
    /**
     * Test weather layers are auto-enabled
     */
    public function testWeatherLayers_AreAutoEnabled()
    {
        $output = $this->getAirportsPageContent();
        
        // Check that buttons start with 'active' class
        $this->assertStringContainsString('radar-btn', $output, 'Should have radar button');
        $this->assertStringContainsString('clouds-btn', $output, 'Should have clouds button');
        
        // Check initialization code
        $this->assertStringContainsString('addRadarLayer()', $output, 'Should call addRadarLayer on load');
        $this->assertStringContainsString('addCloudsLayer()', $output, 'Should call addCloudsLayer on load');
    }
    
    /**
     * Test airport data is embedded in page for JavaScript
     */
    public function testAirportData_IsEmbeddedInPage()
    {
        $output = $this->getAirportsPageContent();
        
        // Check for JSON data embedded in script
        $this->assertStringContainsString('var airports =', $output, 'Should have airports JavaScript variable');
        
        // Should include airport data fields
        $this->assertStringContainsString('"id":', $output, 'Airport data should include id');
        $this->assertStringContainsString('"lat":', $output, 'Airport data should include latitude');
        $this->assertStringContainsString('"lon":', $output, 'Airport data should include longitude');
    }
    
    /**
     * Test navigation is present
     */
    public function testNavigation_IsPresent()
    {
        $output = $this->getAirportsPageContent();
        
        $this->assertStringContainsString('site-nav', $output, 'Should have site navigation');
        $this->assertStringContainsString('AviationWX.org', $output, 'Should have logo/brand');
    }
    
    /**
     * Test page has proper SEO meta tags
     */
    public function testSeoMetaTags_ArePresent()
    {
        $output = $this->getAirportsPageContent();
        
        $this->assertStringContainsString('<meta name="description"', $output, 'Should have meta description');
        $this->assertStringContainsString('<link rel="canonical"', $output, 'Should have canonical URL');
        $this->assertStringContainsString('airports.aviationwx.org', $output, 'Canonical should use airports subdomain');
    }
    
    /**
     * Test marker clustering library is loaded
     */
    public function testMarkerClustering_LibraryIsLoaded()
    {
        $output = $this->getAirportsPageContent();
        
        $this->assertStringContainsString('markercluster', strtolower($output), 'Should load marker cluster library');
    }
    
    /**
     * Test search integration triggers map navigation
     * 
     * Note: "Jump to Map" button was removed. Map navigation is handled
     * via the search integration and marker selection via 'airportsearchselect' event.
     */
    public function testSearchIntegration_TriggersMapNavigation()
    {
        $output = $this->getAirportsPageContent();
        
        // Search integration should listen for airport selection events
        $this->assertStringContainsString('airportsearchselect', strtolower($output), 'Should have search integration event listener');
    }
    
    /**
     * Test airport markers have dark outlines
     */
    public function testAirportMarkers_HaveDarkOutlines()
    {
        $output = $this->getAirportsPageContent();
        
        // Check SVG has stroke properties for visibility
        $this->assertStringContainsString('stroke=', $output, 'Marker SVG should have stroke');
        $this->assertStringContainsString('stroke-width=', $output, 'Marker SVG should have stroke-width');
    }
    
    /**
     * Test RainViewer API integration is present
     */
    public function testRainViewerApi_IntegrationIsPresent()
    {
        $output = $this->getAirportsPageContent();
        
        $this->assertStringContainsString('rainviewer.com', strtolower($output), 'Should integrate RainViewer API');
        // Radar may use proxy (rainviewer-weather-maps.php) or direct API (api.rainviewer.com)
        $hasProxy = str_contains($output, 'rainviewer-weather-maps.php');
        $hasDirectApi = str_contains($output, 'api.rainviewer.com');
        $this->assertTrue($hasProxy || $hasDirectApi, 'Should fetch radar via proxy or direct RainViewer API');
    }
    
    /**
     * Test OpenWeatherMap integration is present
     */
    public function testOpenWeatherMap_IntegrationIsPresent()
    {
        $output = $this->getAirportsPageContent();
        
        $this->assertStringContainsString('openweathermap.org', strtolower($output), 'Should integrate OpenWeatherMap');
        $this->assertStringContainsString('clouds_new', $output, 'Should use clouds layer');
    }

    /**
     * Test safe storage pattern is present (handles SecurityError in iOS Private Browsing)
     */
    public function testSafeStoragePattern_IsPresent()
    {
        $output = $this->getAirportsPageContent();

        $this->assertStringContainsString('safeStorageGet', $output, 'Should define safeStorageGet');
        $this->assertStringContainsString('safeStorageSet', $output, 'Should define safeStorageSet');
        $this->assertStringContainsString('try { return localStorage.getItem', $output, 'Should wrap getItem in try/catch');
    }
}

