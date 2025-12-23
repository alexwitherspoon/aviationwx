<?php
/**
 * Unit Tests for Weather Utility Functions
 * 
 * Tests utility functions for weather-related operations, including sentinel value helpers
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/weather/utils.php';
require_once __DIR__ . '/../../lib/constants.php';

class WeatherUtilsTest extends TestCase
{
    /**
     * Test isUnlimitedVisibility with sentinel value
     */
    public function testIsUnlimitedVisibility_WithSentinelValue_ReturnsTrue()
    {
        $result = isUnlimitedVisibility(UNLIMITED_VISIBILITY_SM);
        $this->assertTrue($result, 'Sentinel value should return true');
    }
    
    /**
     * Test isUnlimitedVisibility with normal value
     */
    public function testIsUnlimitedVisibility_WithNormalValue_ReturnsFalse()
    {
        $result = isUnlimitedVisibility(10.0);
        $this->assertFalse($result, 'Normal visibility value should return false');
    }
    
    /**
     * Test isUnlimitedVisibility with null
     */
    public function testIsUnlimitedVisibility_WithNull_ReturnsFalse()
    {
        $result = isUnlimitedVisibility(null);
        $this->assertFalse($result, 'Null should return false (null = failed, not unlimited)');
    }
    
    /**
     * Test isUnlimitedVisibility with zero
     */
    public function testIsUnlimitedVisibility_WithZero_ReturnsFalse()
    {
        $result = isUnlimitedVisibility(0.0);
        $this->assertFalse($result, 'Zero should return false');
    }
    
    /**
     * Test isUnlimitedCeiling with sentinel value
     */
    public function testIsUnlimitedCeiling_WithSentinelValue_ReturnsTrue()
    {
        $result = isUnlimitedCeiling(UNLIMITED_CEILING_FT);
        $this->assertTrue($result, 'Sentinel value should return true');
    }
    
    /**
     * Test isUnlimitedCeiling with normal value
     */
    public function testIsUnlimitedCeiling_WithNormalValue_ReturnsFalse()
    {
        $result = isUnlimitedCeiling(5000);
        $this->assertFalse($result, 'Normal ceiling value should return false');
    }
    
    /**
     * Test isUnlimitedCeiling with null
     */
    public function testIsUnlimitedCeiling_WithNull_ReturnsFalse()
    {
        $result = isUnlimitedCeiling(null);
        $this->assertFalse($result, 'Null should return false (null = failed, not unlimited)');
    }
    
    /**
     * Test isUnlimitedCeiling with zero
     */
    public function testIsUnlimitedCeiling_WithZero_ReturnsFalse()
    {
        $result = isUnlimitedCeiling(0);
        $this->assertFalse($result, 'Zero should return false');
    }
    
    /**
     * Test that sentinel values are distinct from normal values
     */
    public function testSentinelValues_AreDistinctFromNormalValues()
    {
        // Test visibility
        $normalVisibility = 10.0;
        $this->assertNotEquals(UNLIMITED_VISIBILITY_SM, $normalVisibility, 'Sentinel visibility should be distinct from normal values');
        $this->assertTrue(isUnlimitedVisibility(UNLIMITED_VISIBILITY_SM), 'Sentinel should be recognized as unlimited');
        $this->assertFalse(isUnlimitedVisibility($normalVisibility), 'Normal value should not be recognized as unlimited');
        
        // Test ceiling
        $normalCeiling = 5000;
        $this->assertNotEquals(UNLIMITED_CEILING_FT, $normalCeiling, 'Sentinel ceiling should be distinct from normal values');
        $this->assertTrue(isUnlimitedCeiling(UNLIMITED_CEILING_FT), 'Sentinel should be recognized as unlimited');
        $this->assertFalse(isUnlimitedCeiling($normalCeiling), 'Normal value should not be recognized as unlimited');
    }
}

