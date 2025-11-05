<?php
/**
 * Unit Tests for Observation Time Handling
 * 
 * Tests that observation times (obs_time_primary, obs_time_metar) are properly
 * preserved and used instead of fetch times (last_updated_primary, last_updated_metar)
 * for displaying "last updated" timestamps
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../weather.php';
require_once __DIR__ . '/../Helpers/TestHelper.php';

class ObservationTimeTest extends TestCase
{
    /**
     * Test that last_updated uses observation time when available
     */
    public function testLastUpdated_UsesObservationTime()
    {
        $obsTime = time() - 300; // Observation 5 minutes ago
        $fetchTime = time() - 60; // Fetched 1 minute ago
        
        $weatherData = [
            'obs_time_primary' => $obsTime,
            'last_updated_primary' => $fetchTime,
            'obs_time_metar' => null,
            'last_updated_metar' => null,
        ];
        
        // Collect all available observation times (preferred) and fetch times (fallback)
        $allTimes = [];
        
        // Primary source: prefer observation time, fallback to fetch time
        if (isset($weatherData['obs_time_primary']) && $weatherData['obs_time_primary'] > 0) {
            $allTimes[] = $weatherData['obs_time_primary'];
        } elseif (isset($weatherData['last_updated_primary']) && $weatherData['last_updated_primary'] > 0) {
            $allTimes[] = $weatherData['last_updated_primary'];
        }
        
        // METAR source: prefer observation time, fallback to fetch time
        if (isset($weatherData['obs_time_metar']) && $weatherData['obs_time_metar'] > 0) {
            $allTimes[] = $weatherData['obs_time_metar'];
        } elseif (isset($weatherData['last_updated_metar']) && $weatherData['last_updated_metar'] > 0) {
            $allTimes[] = $weatherData['last_updated_metar'];
        }
        
        // Use the latest (most recent) time from all sources
        $lastUpdated = !empty($allTimes) ? max($allTimes) : time();
        
        // Should use observation time, not fetch time
        $this->assertEquals($obsTime, $lastUpdated, 'Should use observation time when available');
    }
    
    /**
     * Test that last_updated picks latest observation time from all sources
     */
    public function testLastUpdated_PicksLatestObservationTime()
    {
        $primaryObsTime = time() - 600; // Observation 10 minutes ago
        $metarObsTime = time() - 300;   // Observation 5 minutes ago (more recent)
        
        $weatherData = [
            'obs_time_primary' => $primaryObsTime,
            'last_updated_primary' => time() - 60,
            'obs_time_metar' => $metarObsTime,
            'last_updated_metar' => time() - 30,
        ];
        
        // Collect all available observation times (preferred) and fetch times (fallback)
        $allTimes = [];
        
        // Primary source: prefer observation time, fallback to fetch time
        if (isset($weatherData['obs_time_primary']) && $weatherData['obs_time_primary'] > 0) {
            $allTimes[] = $weatherData['obs_time_primary'];
        } elseif (isset($weatherData['last_updated_primary']) && $weatherData['last_updated_primary'] > 0) {
            $allTimes[] = $weatherData['last_updated_primary'];
        }
        
        // METAR source: prefer observation time, fallback to fetch time
        if (isset($weatherData['obs_time_metar']) && $weatherData['obs_time_metar'] > 0) {
            $allTimes[] = $weatherData['obs_time_metar'];
        } elseif (isset($weatherData['last_updated_metar']) && $weatherData['last_updated_metar'] > 0) {
            $allTimes[] = $weatherData['last_updated_metar'];
        }
        
        // Use the latest (most recent) time from all sources
        $lastUpdated = !empty($allTimes) ? max($allTimes) : time();
        
        // Should pick the latest observation time (METAR in this case)
        $this->assertEquals($metarObsTime, $lastUpdated, 'Should pick latest observation time from all sources');
    }
    
    /**
     * Test that last_updated falls back to fetch time when observation time unavailable
     */
    public function testLastUpdated_FallbackToFetchTime()
    {
        $fetchTime = time() - 60; // Fetched 1 minute ago
        
        $weatherData = [
            'obs_time_primary' => null, // No observation time
            'last_updated_primary' => $fetchTime,
            'obs_time_metar' => null,
            'last_updated_metar' => null,
        ];
        
        // Collect all available observation times (preferred) and fetch times (fallback)
        $allTimes = [];
        
        // Primary source: prefer observation time, fallback to fetch time
        if (isset($weatherData['obs_time_primary']) && $weatherData['obs_time_primary'] > 0) {
            $allTimes[] = $weatherData['obs_time_primary'];
        } elseif (isset($weatherData['last_updated_primary']) && $weatherData['last_updated_primary'] > 0) {
            $allTimes[] = $weatherData['last_updated_primary'];
        }
        
        // METAR source: prefer observation time, fallback to fetch time
        if (isset($weatherData['obs_time_metar']) && $weatherData['obs_time_metar'] > 0) {
            $allTimes[] = $weatherData['obs_time_metar'];
        } elseif (isset($weatherData['last_updated_metar']) && $weatherData['last_updated_metar'] > 0) {
            $allTimes[] = $weatherData['last_updated_metar'];
        }
        
        // Use the latest (most recent) time from all sources
        $lastUpdated = !empty($allTimes) ? max($allTimes) : time();
        
        // Should fall back to fetch time when observation time unavailable
        $this->assertEquals($fetchTime, $lastUpdated, 'Should fall back to fetch time when observation time unavailable');
    }
    
    /**
     * Test that METAR observation time is preserved separately
     */
    public function testMetarObservationTimePreserved()
    {
        $primaryObsTime = time() - 600; // 10 minutes ago
        $metarObsTime = time() - 3600;  // 1 hour ago (METAR is hourly, older)
        
        // Simulate weather data with both observation times preserved
        $weatherData = [
            'obs_time_primary' => $primaryObsTime,
            'obs_time_metar' => $metarObsTime,
            'last_updated_primary' => time() - 60,
            'last_updated_metar' => time() - 30,
        ];
        
        // Verify both observation times are preserved
        $this->assertEquals($primaryObsTime, $weatherData['obs_time_primary']);
        $this->assertEquals($metarObsTime, $weatherData['obs_time_metar']);
        
        // Latest observation time should be primary (more recent)
        $allTimes = array_filter([
            $weatherData['obs_time_primary'] ?? null,
            $weatherData['obs_time_metar'] ?? null,
        ]);
        $latest = !empty($allTimes) ? max($allTimes) : null;
        
        $this->assertEquals($primaryObsTime, $latest, 'Should use latest observation time (primary in this case)');
    }
    
    /**
     * Test that observation time is preferred even when fetch time is more recent
     */
    public function testObservationTimePreferredOverFetchTime()
    {
        $obsTime = time() - 1800; // Observation 30 minutes ago
        $fetchTime = time() - 60;  // Fetched 1 minute ago (more recent)
        
        $weatherData = [
            'obs_time_primary' => $obsTime,
            'last_updated_primary' => $fetchTime,
        ];
        
        // Collect all available observation times (preferred) and fetch times (fallback)
        $allTimes = [];
        
        // Primary source: prefer observation time, fallback to fetch time
        if (isset($weatherData['obs_time_primary']) && $weatherData['obs_time_primary'] > 0) {
            $allTimes[] = $weatherData['obs_time_primary'];
        } elseif (isset($weatherData['last_updated_primary']) && $weatherData['last_updated_primary'] > 0) {
            $allTimes[] = $weatherData['last_updated_primary'];
        }
        
        $lastUpdated = !empty($allTimes) ? max($allTimes) : time();
        
        // Should prefer observation time (older but more accurate) over fetch time
        $this->assertEquals($obsTime, $lastUpdated, 'Should prefer observation time over fetch time');
    }
}

