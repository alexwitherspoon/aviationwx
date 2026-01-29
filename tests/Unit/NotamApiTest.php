<?php
/**
 * NOTAM API Tests
 * 
 * Tests for serve-time NOTAM validation and failclosed behavior.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/notam/filter.php';
require_once __DIR__ . '/../../lib/constants.php';

class NotamApiTest extends TestCase {
    
    // ==========================================================================
    // revalidateNotamStatus Tests
    // ==========================================================================
    
    /**
     * Test active NOTAM remains active
     */
    public function testRevalidateNotamStatus_ActiveNotam() {
        $notam = [
            'start_time_utc' => date('Y-m-d\TH:i:s\Z', time() - 3600), // Started 1 hour ago
            'end_time_utc' => date('Y-m-d\TH:i:s\Z', time() + 3600),   // Ends in 1 hour
            'status' => 'active'
        ];
        
        $status = revalidateNotamStatus($notam);
        $this->assertEquals('active', $status);
    }
    
    /**
     * Test expired NOTAM is detected at serve time
     */
    public function testRevalidateNotamStatus_ExpiredNotam() {
        $notam = [
            'start_time_utc' => date('Y-m-d\TH:i:s\Z', time() - 7200), // Started 2 hours ago
            'end_time_utc' => date('Y-m-d\TH:i:s\Z', time() - 3600),   // Ended 1 hour ago
            'status' => 'active' // Was active when cached
        ];
        
        $status = revalidateNotamStatus($notam);
        $this->assertEquals('expired', $status);
    }
    
    /**
     * Test NOTAM that expired between cache and serve time
     */
    public function testRevalidateNotamStatus_JustExpired() {
        $notam = [
            'start_time_utc' => date('Y-m-d\TH:i:s\Z', time() - 3600), // Started 1 hour ago
            'end_time_utc' => date('Y-m-d\TH:i:s\Z', time() - 60),     // Ended 1 minute ago
            'status' => 'active' // Was active when cached
        ];
        
        $status = revalidateNotamStatus($notam);
        $this->assertEquals('expired', $status);
    }
    
    /**
     * Test upcoming NOTAM today
     */
    public function testRevalidateNotamStatus_UpcomingToday() {
        $notam = [
            'start_time_utc' => date('Y-m-d\TH:i:s\Z', time() + 3600), // Starts in 1 hour
            'end_time_utc' => date('Y-m-d\TH:i:s\Z', time() + 7200),   // Ends in 2 hours
            'status' => 'upcoming_today'
        ];
        
        $status = revalidateNotamStatus($notam);
        $this->assertEquals('upcoming_today', $status);
    }
    
    /**
     * Test upcoming NOTAM becomes active
     */
    public function testRevalidateNotamStatus_UpcomingBecomesActive() {
        $notam = [
            'start_time_utc' => date('Y-m-d\TH:i:s\Z', time() - 60),   // Started 1 minute ago
            'end_time_utc' => date('Y-m-d\TH:i:s\Z', time() + 3600),   // Ends in 1 hour
            'status' => 'upcoming_today' // Was upcoming when cached
        ];
        
        $status = revalidateNotamStatus($notam);
        $this->assertEquals('active', $status);
    }
    
    /**
     * Test permanent NOTAM (no end time) remains active
     */
    public function testRevalidateNotamStatus_PermanentNotam() {
        $notam = [
            'start_time_utc' => date('Y-m-d\TH:i:s\Z', time() - 3600), // Started 1 hour ago
            'end_time_utc' => null, // Permanent - no end time
            'status' => 'active'
        ];
        
        $status = revalidateNotamStatus($notam);
        $this->assertEquals('active', $status);
    }
    
    /**
     * Test NOTAM with empty end time (permanent)
     */
    public function testRevalidateNotamStatus_EmptyEndTime() {
        $notam = [
            'start_time_utc' => date('Y-m-d\TH:i:s\Z', time() - 3600),
            'end_time_utc' => '',
            'status' => 'active'
        ];
        
        $status = revalidateNotamStatus($notam);
        $this->assertEquals('active', $status);
    }
    
    /**
     * Test NOTAM with missing start time preserves original status
     */
    public function testRevalidateNotamStatus_MissingStartTime() {
        $notam = [
            'start_time_utc' => '',
            'end_time_utc' => date('Y-m-d\TH:i:s\Z', time() + 3600),
            'status' => 'active'
        ];
        
        $status = revalidateNotamStatus($notam);
        $this->assertEquals('active', $status);
    }
    
    /**
     * Test NOTAM with invalid start time preserves original status
     */
    public function testRevalidateNotamStatus_InvalidStartTime() {
        $notam = [
            'start_time_utc' => 'not-a-valid-date',
            'end_time_utc' => date('Y-m-d\TH:i:s\Z', time() + 3600),
            'status' => 'unknown'
        ];
        
        $status = revalidateNotamStatus($notam);
        $this->assertEquals('unknown', $status);
    }
    
    /**
     * Test far future NOTAM is upcoming_future
     */
    public function testRevalidateNotamStatus_FarFuture() {
        $notam = [
            'start_time_utc' => date('Y-m-d\TH:i:s\Z', time() + 86400 * 7), // 7 days from now
            'end_time_utc' => date('Y-m-d\TH:i:s\Z', time() + 86400 * 8),
            'status' => 'upcoming_future'
        ];
        
        $status = revalidateNotamStatus($notam);
        $this->assertEquals('upcoming_future', $status);
    }
    
    // ==========================================================================
    // Staleness Threshold Tests
    // ==========================================================================
    
    /**
     * Test NOTAM staleness constants are properly defined
     */
    public function testNotamStalenessConstantsAreDefined() {
        $this->assertTrue(defined('DEFAULT_NOTAM_STALE_WARNING_SECONDS'));
        $this->assertTrue(defined('DEFAULT_NOTAM_STALE_ERROR_SECONDS'));
        $this->assertTrue(defined('DEFAULT_NOTAM_STALE_FAILCLOSED_SECONDS'));
        
        // Verify reasonable values
        $this->assertEquals(900, DEFAULT_NOTAM_STALE_WARNING_SECONDS);   // 15 minutes
        $this->assertEquals(1800, DEFAULT_NOTAM_STALE_ERROR_SECONDS);    // 30 minutes
        $this->assertEquals(3600, DEFAULT_NOTAM_STALE_FAILCLOSED_SECONDS); // 1 hour
        
        // Verify ordering: warning < error < failclosed
        $this->assertLessThan(
            DEFAULT_NOTAM_STALE_ERROR_SECONDS,
            DEFAULT_NOTAM_STALE_WARNING_SECONDS,
            'Warning threshold should be less than error threshold'
        );
        $this->assertLessThan(
            DEFAULT_NOTAM_STALE_FAILCLOSED_SECONDS,
            DEFAULT_NOTAM_STALE_ERROR_SECONDS,
            'Error threshold should be less than failclosed threshold'
        );
    }
}
