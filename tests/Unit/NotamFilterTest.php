<?php
/**
 * NOTAM Filter Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/notam/filter.php';

class NotamFilterTest extends TestCase {
    
    public function testIsAerodromeClosure() {
        $airport = [
            'icao' => 'KDFW',
            'name' => 'Dallas/Fort Worth International'
        ];
        
        $notam = [
            'code' => 'QMRLC',
            'text' => 'DFW RWY 13L/31R CLSD TO ACFT WINGSPAN MORE THAN 214FT',
            'location' => 'KDFW'
        ];
        
        $this->assertTrue(isAerodromeClosure($notam, $airport));
    }
    
    public function testIsAerodromeClosureWithFormerly() {
        $airport = [
            'faa' => '4OR9',
            'name' => 'Country Squire Airpark',
            'formerly' => ['S48']
        ];
        
        $notam = [
            'code' => 'QMRLC',
            'text' => 'AD AP CLSD',
            'location' => 'S48',
            'airport_name' => 'COUNTRY SQUIRE AIRPARK'
        ];
        
        $this->assertTrue(isAerodromeClosure($notam, $airport));
    }
    
    public function testIsTfr() {
        $notam = [
            'text' => 'TEMPORARY FLIGHT RESTRICTIONS. BEALE AFB, CA.'
        ];
        
        $this->assertTrue(isTfr($notam));
    }
    
    // ==========================================================================
    // NOTAM Cancellation Detection Tests
    // ==========================================================================
    
    public function testIsNotamCancellation_TypeC() {
        $notam = [
            'type' => 'C',
            'text' => 'BOI RWY 10R/28L CLSD'
        ];
        
        $this->assertTrue(isNotamCancellation($notam));
    }
    
    public function testIsNotamCancellation_NotamcInText() {
        $notam = [
            'type' => 'N',
            'text' => 'A0261/26 NOTAMC A0248/26 BOI RWY 10R/28L CLSD CANCELED'
        ];
        
        $this->assertTrue(isNotamCancellation($notam));
    }
    
    public function testIsNotamCancellation_CanceledAtEnd() {
        $notam = [
            'type' => 'N',
            'text' => 'BOI RWY 10R/28L CLSD CANCELED'
        ];
        
        $this->assertTrue(isNotamCancellation($notam));
    }
    
    public function testIsNotamCancellation_CancelledSpelling() {
        $notam = [
            'type' => 'N',
            'text' => 'BOI RWY 10L/28R CLSD CANCELLED'
        ];
        
        $this->assertTrue(isNotamCancellation($notam));
    }
    
    public function testIsNotamCancellation_NotACancellation() {
        $notam = [
            'type' => 'N',
            'text' => 'BOI RWY 10R/28L CLSD'
        ];
        
        $this->assertFalse(isNotamCancellation($notam));
    }
    
    public function testIsAerodromeClosure_ExcludesCancellation() {
        // Actual NOTAM from the bug - this is a cancellation and should NOT be shown
        $airport = [
            'icao' => 'KBOI',
            'name' => 'Boise Air Terminal'
        ];
        
        $notam = [
            'type' => 'C',
            'code' => 'QMRXX',
            'text' => 'A0261/26 NOTAMC A0248/26 BOI RWY 10R/28L CLSD CANCELED',
            'location' => 'KBOI'
        ];
        
        $this->assertFalse(isAerodromeClosure($notam, $airport));
    }
    
    public function testIsTfr_ExcludesCancellation() {
        $notam = [
            'type' => 'C',
            'text' => 'TEMPORARY FLIGHT RESTRICTIONS CANCELED'
        ];
        
        $this->assertFalse(isTfr($notam));
    }
    
    public function testDetermineNotamStatusActive() {
        $notam = [
            'start_time_utc' => date('Y-m-d\TH:i:s\Z', time() - 3600), // 1 hour ago
            'end_time_utc' => date('Y-m-d\TH:i:s\Z', time() + 3600) // 1 hour from now
        ];
        
        $status = determineNotamStatus($notam);
        $this->assertEquals('active', $status);
    }
    
    public function testDetermineNotamStatusUpcomingToday() {
        $notam = [
            'start_time_utc' => date('Y-m-d\TH:i:s\Z', time() + 3600), // 1 hour from now
            'end_time_utc' => date('Y-m-d\TH:i:s\Z', time() + 7200) // 2 hours from now
        ];
        
        $status = determineNotamStatus($notam);
        $this->assertEquals('upcoming_today', $status);
    }
    
    public function testDetermineNotamStatusExpired() {
        $notam = [
            'start_time_utc' => date('Y-m-d\TH:i:s\Z', time() - 7200), // 2 hours ago
            'end_time_utc' => date('Y-m-d\TH:i:s\Z', time() - 3600) // 1 hour ago
        ];
        
        $status = determineNotamStatus($notam);
        $this->assertEquals('expired', $status);
    }
    
    // ==========================================================================
    // TFR Geographic Filtering Tests
    // ==========================================================================
    
    /**
     * Test parsing TFR coordinates from NOTAM text
     * Format: DDMMSSN/DDDMMSSW (e.g., 413900N1122300W)
     */
    public function testParseTfrCoordinates_ValidFormat() {
        // Ogden, UT TFR coordinates: 41°39'00"N, 112°23'00"W
        $text = 'WITHIN AN AREA DEFINED AS 5NM RADIUS OF 413900N1122300W';
        $coords = parseTfrCoordinates($text);
        
        $this->assertNotNull($coords);
        $this->assertEqualsWithDelta(41.65, $coords['lat'], 0.01);
        $this->assertEqualsWithDelta(-112.383, $coords['lon'], 0.01);
    }
    
    public function testParseTfrCoordinates_SouthernHemisphere() {
        // Sydney, Australia area: 33°52'00"S, 151°12'00"E
        $text = 'AREA DEFINED AS 10NM RADIUS OF 335200S1511200E';
        $coords = parseTfrCoordinates($text);
        
        $this->assertNotNull($coords);
        $this->assertEqualsWithDelta(-33.867, $coords['lat'], 0.01);
        $this->assertEqualsWithDelta(151.2, $coords['lon'], 0.01);
    }
    
    public function testParseTfrCoordinates_NoCoordinates() {
        $text = 'TEMPORARY FLIGHT RESTRICTIONS NEAR BOISE AIRPORT';
        $coords = parseTfrCoordinates($text);
        
        $this->assertNull($coords);
    }
    
    public function testParseTfrCoordinates_RealWorldTfr() {
        // Real TFR text from the bug report
        $text = 'ZLC UT..AIRSPACE OGDEN, UT..TEMPORARY FLIGHT RESTRICTIONS WITHIN AN AREA DEFINED AS 5NM RADIUS OF 413900N1122300W (OGD319029) STATIC GROUND BASED ROCKET ENGINE TEST.';
        $coords = parseTfrCoordinates($text);
        
        $this->assertNotNull($coords);
        $this->assertEqualsWithDelta(41.65, $coords['lat'], 0.01);
        $this->assertEqualsWithDelta(-112.383, $coords['lon'], 0.01);
    }
    
    /**
     * Test parsing TFR radius from NOTAM text
     * Returns radius in nautical miles (standard aviation unit)
     */
    public function testParseTfrRadiusNm_StandardFormat() {
        $text = 'WITHIN AN AREA DEFINED AS 5NM RADIUS OF 413900N1122300W';
        $radiusNm = parseTfrRadiusNm($text);
        
        $this->assertNotNull($radiusNm);
        $this->assertEquals(5.0, $radiusNm);
    }
    
    public function testParseTfrRadiusNm_WithSpace() {
        $text = 'AREA DEFINED AS 10 NM RADIUS';
        $radiusNm = parseTfrRadiusNm($text);
        
        $this->assertNotNull($radiusNm);
        $this->assertEquals(10.0, $radiusNm);
    }
    
    public function testParseTfrRadiusNm_NauticalMiles() {
        $text = '3 NAUTICAL MILE RADIUS';
        $radiusNm = parseTfrRadiusNm($text);
        
        $this->assertNotNull($radiusNm);
        $this->assertEquals(3.0, $radiusNm);
    }
    
    public function testParseTfrRadiusNm_RadiusOf() {
        $text = 'RADIUS OF 7NM';
        $radiusNm = parseTfrRadiusNm($text);
        
        $this->assertNotNull($radiusNm);
        $this->assertEquals(7.0, $radiusNm);
    }
    
    public function testParseTfrRadiusNm_WithinNm() {
        $text = 'RESTRICTED AREA WITHIN 15NM OF AIRPORT';
        $radiusNm = parseTfrRadiusNm($text);
        
        $this->assertNotNull($radiusNm);
        $this->assertEquals(15.0, $radiusNm);
    }
    
    public function testParseTfrRadiusNm_NoRadius() {
        $text = 'TEMPORARY FLIGHT RESTRICTIONS IN EFFECT';
        $radiusNm = parseTfrRadiusNm($text);
        
        $this->assertNull($radiusNm);
    }
    
    public function testParseTfrRadiusNm_DecimalRadius() {
        $text = '2.5NM RADIUS';
        $radiusNm = parseTfrRadiusNm($text);
        
        $this->assertNotNull($radiusNm);
        $this->assertEquals(2.5, $radiusNm);
    }
    
    /**
     * Test haversine distance calculation in nautical miles
     */
    public function testCalculateDistanceNm_SamePoint() {
        $distance = calculateDistanceNm(45.0, -122.0, 45.0, -122.0);
        $this->assertEquals(0.0, $distance);
    }
    
    public function testCalculateDistanceNm_KnownDistance() {
        // Boise (KBOI) to Ogden, UT - approximately 180 NM
        $boiseLat = 43.5644;
        $boiseLon = -116.2228;
        $ogdenLat = 41.65;
        $ogdenLon = -112.383;
        
        $distanceNm = calculateDistanceNm($boiseLat, $boiseLon, $ogdenLat, $ogdenLon);
        
        // Should be approximately 170-200 NM
        $this->assertGreaterThan(150, $distanceNm);
        $this->assertLessThan(220, $distanceNm);
    }
    
    public function testCalculateDistanceNm_ShortDistance() {
        // Two points about 5 NM apart (roughly 0.083 degrees at mid-latitudes)
        // 1 degree latitude = 60 NM
        $lat1 = 45.0;
        $lon1 = -122.0;
        $lat2 = 45.083;  // ~5 NM north
        $lon2 = -122.0;
        
        $distanceNm = calculateDistanceNm($lat1, $lon1, $lat2, $lon2);
        
        // Should be close to 5 NM
        $this->assertGreaterThan(4.5, $distanceNm);
        $this->assertLessThan(5.5, $distanceNm);
    }
    
    /**
     * Test TFR relevance to airport - the main filtering function
     */
    public function testIsTfrRelevantToAirport_MentionsAirportName() {
        $tfr = [
            'text' => 'TEMPORARY FLIGHT RESTRICTIONS NEAR BOISE AIRPORT'
        ];
        $airport = [
            'name' => 'Boise',
            'lat' => 43.5644,
            'lon' => -116.2228
        ];
        
        $this->assertTrue(isTfrRelevantToAirport($tfr, $airport));
    }
    
    public function testIsTfrRelevantToAirport_MentionsIcao() {
        $tfr = [
            'text' => 'TFR AROUND KBOI EFFECTIVE IMMEDIATELY'
        ];
        $airport = [
            'icao' => 'KBOI',
            'name' => 'Boise Air Terminal',
            'lat' => 43.5644,
            'lon' => -116.2228
        ];
        
        $this->assertTrue(isTfrRelevantToAirport($tfr, $airport));
    }
    
    public function testIsTfrRelevantToAirport_LocationFieldMatches() {
        // Test that the NOTAM location field is used when it matches airport identifier
        $tfr = [
            'text' => 'TEMPORARY FLIGHT RESTRICTIONS IN EFFECT',
            'location' => 'KBOI'
        ];
        $airport = [
            'icao' => 'KBOI',
            'name' => 'Boise Air Terminal',
            'lat' => 43.5644,
            'lon' => -116.2228
        ];
        
        $this->assertTrue(isTfrRelevantToAirport($tfr, $airport));
    }
    
    public function testIsTfrRelevantToAirport_AirportNameFieldMatches() {
        // Test that the NOTAM airport_name field is used
        $tfr = [
            'text' => 'TEMPORARY FLIGHT RESTRICTIONS IN EFFECT',
            'airport_name' => 'BOISE AIR TERMINAL'
        ];
        $airport = [
            'icao' => 'KBOI',
            'name' => 'Boise Air Terminal',
            'lat' => 43.5644,
            'lon' => -116.2228
        ];
        
        $this->assertTrue(isTfrRelevantToAirport($tfr, $airport));
    }
    
    public function testIsTfrRelevantToAirport_DistantTfrNotRelevant() {
        // This is the actual bug case: Ogden TFR showing on Boise airport
        $tfr = [
            'text' => 'ZLC UT..AIRSPACE OGDEN, UT..TEMPORARY FLIGHT RESTRICTIONS WITHIN AN AREA DEFINED AS 5NM RADIUS OF 413900N1122300W (OGD319029) STATIC GROUND BASED ROCKET ENGINE TEST.'
        ];
        $airport = [
            'icao' => 'KBOI',
            'name' => 'Boise Air Terminal',
            'lat' => 43.5644,
            'lon' => -116.2228
        ];
        
        // Ogden is ~180 NM from Boise, well outside the 5 NM TFR radius + 10 NM buffer
        $this->assertFalse(isTfrRelevantToAirport($tfr, $airport));
    }
    
    public function testIsTfrRelevantToAirport_NearbyTfrIsRelevant() {
        // TFR 8 NM from airport with 5 NM radius - airport is just outside TFR
        // but within relevance buffer (5 NM radius + 10 NM buffer = 15 NM threshold)
        $tfr = [
            'text' => 'TEMPORARY FLIGHT RESTRICTIONS WITHIN 5NM RADIUS OF 450500N1220000W'
        ];
        $airport = [
            'name' => 'Test Airport',
            // About 8 NM away from TFR center (45°05'N, 122°00'W)
            // 0.133 degrees ≈ 8 NM at this latitude (1 degree = 60 NM)
            'lat' => 45.217,
            'lon' => -122.0
        ];
        
        $this->assertTrue(isTfrRelevantToAirport($tfr, $airport));
    }
    
    public function testIsTfrRelevantToAirport_AirportInsideTfr() {
        // Airport directly under TFR
        $tfr = [
            'text' => 'TEMPORARY FLIGHT RESTRICTIONS WITHIN 10NM RADIUS OF 450000N1220000W'
        ];
        $airport = [
            'name' => 'Test Airport',
            'lat' => 45.0,
            'lon' => -122.0
        ];
        
        $this->assertTrue(isTfrRelevantToAirport($tfr, $airport));
    }
    
    public function testIsTfrRelevantToAirport_NoCoordinatesInTfr() {
        // TFR without parseable coordinates should return false (conservative)
        $tfr = [
            'text' => 'TEMPORARY FLIGHT RESTRICTIONS IN EFFECT FOR SPECIAL EVENT'
        ];
        $airport = [
            'name' => 'Test Airport',
            'lat' => 45.0,
            'lon' => -122.0
        ];
        
        $this->assertFalse(isTfrRelevantToAirport($tfr, $airport));
    }
    
    public function testIsTfrRelevantToAirport_NoAirportCoordinates() {
        // Airport config missing coordinates - should return false
        $tfr = [
            'text' => 'TEMPORARY FLIGHT RESTRICTIONS WITHIN 5NM RADIUS OF 450000N1220000W'
        ];
        $airport = [
            'name' => 'Test Airport'
            // No lat/lon
        ];
        
        $this->assertFalse(isTfrRelevantToAirport($tfr, $airport));
    }
    
    public function testIsTfrRelevantToAirport_CaldwellIdaho() {
        // Another test case from the bug report: Caldwell, ID (KEUL)
        $tfr = [
            'text' => 'ZLC UT..AIRSPACE OGDEN, UT..TEMPORARY FLIGHT RESTRICTIONS WITHIN AN AREA DEFINED AS 5NM RADIUS OF 413900N1122300W'
        ];
        $airport = [
            'icao' => 'KEUL',
            'name' => 'Caldwell Industrial Airport',
            'lat' => 43.6422,
            'lon' => -116.6358
        ];
        
        // Caldwell is ~200 NM from Ogden, should not show this TFR
        $this->assertFalse(isTfrRelevantToAirport($tfr, $airport));
    }
    
    public function testIsTfrRelevantToAirport_LargeTfrRadius() {
        // Large TFR (30 NM radius) - tests that we use parsed radius, not default
        $tfr = [
            'text' => 'TEMPORARY FLIGHT RESTRICTIONS WITHIN 30NM RADIUS OF 450000N1220000W'
        ];
        $airport = [
            'name' => 'Test Airport',
            // About 35 NM from TFR center - outside 30 NM but within 30+10=40 NM buffer
            // 0.583 degrees ≈ 35 NM at this latitude (1 degree = 60 NM)
            'lat' => 45.583,
            'lon' => -122.0
        ];
        
        $this->assertTrue(isTfrRelevantToAirport($tfr, $airport));
    }
    
    /**
     * Test that constants are properly defined
     */
    public function testTfrConstantsAreDefined() {
        $this->assertTrue(defined('TFR_DEFAULT_RADIUS_NM'), 'TFR_DEFAULT_RADIUS_NM should be defined');
        $this->assertTrue(defined('TFR_RELEVANCE_BUFFER_NM'), 'TFR_RELEVANCE_BUFFER_NM should be defined');
        $this->assertTrue(defined('TFR_RADIUS_MIN_NM'), 'TFR_RADIUS_MIN_NM should be defined');
        $this->assertTrue(defined('TFR_RADIUS_MAX_NM'), 'TFR_RADIUS_MAX_NM should be defined');
        
        // Verify expected values
        $this->assertEquals(30, TFR_DEFAULT_RADIUS_NM);
        $this->assertEquals(10, TFR_RELEVANCE_BUFFER_NM);
        $this->assertEquals(0.5, TFR_RADIUS_MIN_NM);
        $this->assertEquals(100, TFR_RADIUS_MAX_NM);
    }
    
    // ==========================================================================
    // Word Boundary Matching Tests
    // ==========================================================================
    
    /**
     * Test isWordMatch prevents substring false positives
     */
    public function testIsWordMatch_ExactMatch() {
        $this->assertTrue(isWordMatch('BOISE AIRPORT', 'BOISE'));
        $this->assertTrue(isWordMatch('THE BOISE AREA', 'BOISE'));
    }
    
    public function testIsWordMatch_PreventsFalsePositives() {
        // "FIELD" should NOT match "SPRINGFIELD"
        $this->assertFalse(isWordMatch('SPRINGFIELD AIRPORT', 'FIELD'));
        
        // "BOI" should NOT match "BOISE" (partial identifier)
        $this->assertFalse(isWordMatch('BOISE AIRPORT', 'BOI'));
    }
    
    public function testIsWordMatch_CaseInsensitiveInput() {
        // Function expects uppercase input (caller should uppercase)
        $this->assertTrue(isWordMatch('BOISE AIRPORT', 'BOISE'));
        $this->assertTrue(isWordMatch('TFR NEAR KBOI AIRPORT', 'KBOI'));
    }
    
    public function testIsWordMatch_EmptyInput() {
        $this->assertFalse(isWordMatch('', 'BOISE'));
        $this->assertFalse(isWordMatch('BOISE AIRPORT', ''));
        $this->assertFalse(isWordMatch('', ''));
    }
    
    public function testIsWordMatch_SpecialCharacterBoundaries() {
        // Word boundaries work with punctuation
        $this->assertTrue(isWordMatch('TFR NEAR KBOI, EFFECTIVE', 'KBOI'));
        $this->assertTrue(isWordMatch('AREA: BOISE', 'BOISE'));
        $this->assertTrue(isWordMatch('(KBOI) AIRPORT', 'KBOI'));
    }
    
    // ==========================================================================
    // Timezone-Aware Status Tests
    // ==========================================================================
    
    /**
     * Test determineNotamStatus with airport timezone
     */
    public function testDetermineNotamStatus_WithAirportTimezone() {
        // Create a NOTAM starting 2 hours from now
        $notam = [
            'start_time_utc' => date('Y-m-d\TH:i:s\Z', time() + 7200),
            'end_time_utc' => date('Y-m-d\TH:i:s\Z', time() + 10800)
        ];
        
        // With an airport timezone specified
        $airport = [
            'timezone' => 'America/Los_Angeles'
        ];
        
        $status = determineNotamStatus($notam, $airport);
        
        // Should be either upcoming_today or upcoming_future depending on local time
        $this->assertContains($status, ['upcoming_today', 'upcoming_future']);
    }
    
    public function testDetermineNotamStatus_WithoutAirport() {
        // Test backward compatibility - no airport parameter
        $notam = [
            'start_time_utc' => date('Y-m-d\TH:i:s\Z', time() - 3600),
            'end_time_utc' => date('Y-m-d\TH:i:s\Z', time() + 3600)
        ];
        
        $status = determineNotamStatus($notam);
        $this->assertEquals('active', $status);
    }
    
    public function testDetermineNotamStatus_InvalidTimezone() {
        // Invalid timezone should fall back to server time gracefully
        $notam = [
            'start_time_utc' => date('Y-m-d\TH:i:s\Z', time() - 3600),
            'end_time_utc' => date('Y-m-d\TH:i:s\Z', time() + 3600)
        ];
        
        $airport = [
            'timezone' => 'Invalid/Timezone'
        ];
        
        // Should not throw, should return valid status
        $status = determineNotamStatus($notam, $airport);
        $this->assertEquals('active', $status);
    }
    
    public function testDetermineNotamStatus_UnknownStatus() {
        // Missing start time should return 'unknown'
        $notam = [
            'start_time_utc' => '',
            'end_time_utc' => date('Y-m-d\TH:i:s\Z', time() + 3600)
        ];
        
        $status = determineNotamStatus($notam);
        $this->assertEquals('unknown', $status);
    }
}

