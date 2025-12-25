<?php

require_once __DIR__ . '/../../lib/webcam-stagger.php';

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for webcam stagger offset calculation
 * 
 * Tests the PHP logic for calculating random stagger offsets used in server-side
 * webcam refresh scheduling (cron jobs, background refresh).
 */
class WebcamStaggerTest extends TestCase
{
    /**
     * Test stagger offset is within expected range (20-30% of interval)
     */
    public function testStaggerOffsetWithinRange()
    {
        $baseInterval = 60;
        $minExpected = (int)floor($baseInterval * 0.20); // 12 seconds
        $maxExpected = (int)floor($baseInterval * 0.30); // 18 seconds
        
        // Run multiple times to ensure randomness
        for ($i = 0; $i < 20; $i++) {
            $offset = calculateWebcamStaggerOffset($baseInterval);
            
            $this->assertGreaterThanOrEqual($minExpected, $offset, 'Offset should be >= 20% of interval');
            $this->assertLessThanOrEqual($maxExpected, $offset, 'Offset should be <= 30% of interval');
        }
    }
    
    /**
     * Test stagger offset with different intervals
     */
    public function testStaggerOffsetWithDifferentIntervals()
    {
        $testCases = [
            ['interval' => 30, 'min' => 6, 'max' => 9],
            ['interval' => 60, 'min' => 12, 'max' => 18],
            ['interval' => 120, 'min' => 24, 'max' => 36],
            ['interval' => 300, 'min' => 60, 'max' => 90],
        ];
        
        foreach ($testCases as $testCase) {
            $offset = calculateWebcamStaggerOffset($testCase['interval']);
            
            $this->assertGreaterThanOrEqual($testCase['min'], $offset, 
                "Offset for {$testCase['interval']}s interval should be >= {$testCase['min']}s");
            $this->assertLessThanOrEqual($testCase['max'], $offset, 
                "Offset for {$testCase['interval']}s interval should be <= {$testCase['max']}s");
        }
    }
    
    /**
     * Test stagger offset returns integer
     */
    public function testStaggerOffsetIsInteger()
    {
        $offset = calculateWebcamStaggerOffset(60);
        
        $this->assertIsInt($offset, 'Offset should be an integer');
        $this->assertGreaterThanOrEqual(0, $offset, 'Offset should be non-negative');
    }
    
    /**
     * Test stagger offset randomness (multiple calls produce different values)
     */
    public function testStaggerOffsetRandomness()
    {
        $baseInterval = 60;
        $offsets = [];
        
        // Generate multiple offsets
        for ($i = 0; $i < 50; $i++) {
            $offsets[] = calculateWebcamStaggerOffset($baseInterval);
        }
        
        // Should have some variation (not all the same)
        $uniqueOffsets = array_unique($offsets);
        $this->assertGreaterThan(1, count($uniqueOffsets), 
            'Multiple calls should produce different offset values');
        
        // All values should still be within range
        $minExpected = (int)floor($baseInterval * 0.20);
        $maxExpected = (int)floor($baseInterval * 0.30);
        foreach ($offsets as $offset) {
            $this->assertGreaterThanOrEqual($minExpected, $offset);
            $this->assertLessThanOrEqual($maxExpected, $offset);
        }
    }
    
    /**
     * Test stagger offset with minimum interval (60 seconds)
     */
    public function testStaggerOffsetWithMinimumInterval()
    {
        $offset = calculateWebcamStaggerOffset(60);
        
        // For 60s: 20% = 12s, 30% = 18s
        $this->assertGreaterThanOrEqual(12, $offset, 'Offset for 60s should be >= 12s');
        $this->assertLessThanOrEqual(18, $offset, 'Offset for 60s should be <= 18s');
    }
    
    /**
     * Test stagger offset consistently stays within 20-30% range
     * 
     * Verifies the stagger calculation produces values in the expected range
     * across many iterations for statistical confidence.
     */
    public function testStaggerOffsetConsistentlyWithinRange()
    {
        $baseInterval = 60;
        
        $minExpected = (int)floor($baseInterval * 0.20); // 12
        $maxExpected = (int)floor($baseInterval * 0.30); // 18
        
        // Generate multiple offsets and verify they're all within expected range
        for ($i = 0; $i < 100; $i++) {
            $offset = calculateWebcamStaggerOffset($baseInterval);
            
            // Range: floor(60 * 0.20) to floor(60 * 0.30) = 12 to 18
            $this->assertGreaterThanOrEqual($minExpected, $offset, 
                'Offset should be >= 20% of interval');
            $this->assertLessThanOrEqual($maxExpected, $offset, 
                'Offset should be <= 30% of interval');
        }
    }
    
    /**
     * Test stagger offset with very large interval
     */
    public function testStaggerOffsetWithLargeInterval()
    {
        $baseInterval = 900; // 15 minutes
        $offset = calculateWebcamStaggerOffset($baseInterval);
        
        // For 900s: 20% = 180s, 30% = 270s
        $minExpected = (int)floor($baseInterval * 0.20);
        $maxExpected = (int)floor($baseInterval * 0.30);
        
        $this->assertGreaterThanOrEqual($minExpected, $offset);
        $this->assertLessThanOrEqual($maxExpected, $offset);
    }
}


