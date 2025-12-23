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
}

