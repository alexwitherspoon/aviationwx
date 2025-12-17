<?php
/**
 * Unit Tests for JavaScript formatRelativeTime Function
 * 
 * Tests the two-unit precision relative time formatting logic.
 * These tests verify the JavaScript function behavior by testing the logic
 * that should be implemented in the browser.
 * 
 * Note: This is a PHP test file that validates the expected JavaScript behavior.
 * The actual JavaScript function is in pages/airport.php.
 */

use PHPUnit\Framework\TestCase;

class RelativeTimeFormatTest extends TestCase
{
    /**
     * Simulate JavaScript formatRelativeTime function for testing
     * This mirrors the JavaScript implementation to verify expected behavior
     * 
     * @param int $seconds Number of seconds ago
     * @return string Formatted relative time string
     */
    private function formatRelativeTime(int $seconds): string
    {
        // Handle edge cases
        if ($seconds < 0) {
            return '--';
        }
        
        // Less than 1 minute: show seconds only
        if ($seconds < 60) {
            return $seconds . ($seconds === 1 ? ' second' : ' seconds') . ' ago';
        }
        
        // Less than 1 hour: show minutes only (single unit)
        if ($seconds < 3600) {
            $minutes = (int)floor($seconds / 60);
            return $minutes . ($minutes === 1 ? ' minute' : ' minutes') . ' ago';
        }
        
        // Less than 1 day: show hours and minutes
        if ($seconds < 86400) {
            $hours = (int)floor($seconds / 3600);
            $remainingMinutes = (int)floor(($seconds % 3600) / 60);
            
            if ($remainingMinutes === 0) {
                return $hours . ($hours === 1 ? ' hour' : ' hours') . ' ago';
            }
            return $hours . ($hours === 1 ? ' hour' : ' hours') . ' ' .
                   $remainingMinutes . ($remainingMinutes === 1 ? ' minute' : ' minutes') . ' ago';
        }
        
        // 1 day or more: show days and hours
        $days = (int)floor($seconds / 86400);
        $remainingHours = (int)floor(($seconds % 86400) / 3600);
        
        if ($remainingHours === 0) {
            return $days . ($days === 1 ? ' day' : ' days') . ' ago';
        }
        return $days . ($days === 1 ? ' day' : ' days') . ' ' .
               $remainingHours . ($remainingHours === 1 ? ' hour' : ' hours') . ' ago';
    }
    
    public function testFormatRelativeTime_NegativeValue_ReturnsDashDash(): void
    {
        $result = $this->formatRelativeTime(-1);
        $this->assertEquals('--', $result);
    }
    
    public function testFormatRelativeTime_ZeroSeconds_ReturnsZeroSeconds(): void
    {
        $result = $this->formatRelativeTime(0);
        $this->assertEquals('0 seconds ago', $result);
    }
    
    public function testFormatRelativeTime_OneSecond_ReturnsOneSecond(): void
    {
        $result = $this->formatRelativeTime(1);
        $this->assertEquals('1 second ago', $result);
    }
    
    public function testFormatRelativeTime_ThirtySeconds_ReturnsThirtySeconds(): void
    {
        $result = $this->formatRelativeTime(30);
        $this->assertEquals('30 seconds ago', $result);
    }
    
    public function testFormatRelativeTime_ExactlyOneMinute_ReturnsOneMinute(): void
    {
        $result = $this->formatRelativeTime(60);
        $this->assertEquals('1 minute ago', $result);
    }
    
    public function testFormatRelativeTime_OneMinuteThirtySeconds_ReturnsSingleUnit(): void
    {
        $result = $this->formatRelativeTime(90);
        $this->assertEquals('1 minute ago', $result);
    }
    
    public function testFormatRelativeTime_TwoMinutesFiveSeconds_ReturnsSingleUnit(): void
    {
        $result = $this->formatRelativeTime(125);
        $this->assertEquals('2 minutes ago', $result);
    }
    
    public function testFormatRelativeTime_FiftyNineMinutesFiftyNineSeconds_ReturnsSingleUnit(): void
    {
        $result = $this->formatRelativeTime(3599);
        $this->assertEquals('59 minutes ago', $result);
    }
    
    public function testFormatRelativeTime_ExactlyOneHour_ReturnsOneHour(): void
    {
        $result = $this->formatRelativeTime(3600);
        $this->assertEquals('1 hour ago', $result);
    }
    
    public function testFormatRelativeTime_OneHourThreeMinutes_ReturnsTwoUnits(): void
    {
        $result = $this->formatRelativeTime(3780);
        $this->assertEquals('1 hour 3 minutes ago', $result);
    }
    
    public function testFormatRelativeTime_OneHourTwentyThreeMinutes_ReturnsTwoUnits(): void
    {
        $result = $this->formatRelativeTime(4983);
        $this->assertEquals('1 hour 23 minutes ago', $result);
    }
    
    public function testFormatRelativeTime_ExactlyTwoHours_ReturnsTwoHours(): void
    {
        $result = $this->formatRelativeTime(7200);
        $this->assertEquals('2 hours ago', $result);
    }
    
    public function testFormatRelativeTime_TwentyThreeHoursFiftyNineMinutes_ReturnsTwoUnits(): void
    {
        $result = $this->formatRelativeTime(86399);
        $this->assertEquals('23 hours 59 minutes ago', $result);
    }
    
    public function testFormatRelativeTime_ExactlyOneDay_ReturnsOneDay(): void
    {
        $result = $this->formatRelativeTime(86400);
        $this->assertEquals('1 day ago', $result);
    }
    
    public function testFormatRelativeTime_OneDayOneHour_ReturnsTwoUnits(): void
    {
        $result = $this->formatRelativeTime(90000);
        $this->assertEquals('1 day 1 hour ago', $result);
    }
    
    public function testFormatRelativeTime_TwoDaysOneHour_ReturnsTwoUnits(): void
    {
        $result = $this->formatRelativeTime(176400);
        $this->assertEquals('2 days 1 hour ago', $result);
    }
    
    public function testFormatRelativeTime_ExactlyThreeDays_ReturnsThreeDays(): void
    {
        $result = $this->formatRelativeTime(259200);
        $this->assertEquals('3 days ago', $result);
    }
    
    public function testFormatRelativeTime_EightDays_ReturnsEightDays(): void
    {
        $result = $this->formatRelativeTime(691200);
        $this->assertEquals('8 days ago', $result);
    }
    
    public function testFormatRelativeTime_PluralForms_AreCorrect(): void
    {
        // Test singular forms
        $this->assertEquals('1 second ago', $this->formatRelativeTime(1));
        $this->assertEquals('1 minute ago', $this->formatRelativeTime(60));
        $this->assertEquals('1 hour ago', $this->formatRelativeTime(3600));
        $this->assertEquals('1 day ago', $this->formatRelativeTime(86400));
        
        // Test plural forms
        $this->assertEquals('2 seconds ago', $this->formatRelativeTime(2));
        $this->assertEquals('2 minutes ago', $this->formatRelativeTime(120));
        $this->assertEquals('2 hours ago', $this->formatRelativeTime(7200));
        $this->assertEquals('2 days ago', $this->formatRelativeTime(172800));
    }
    
    public function testFormatRelativeTime_TwoUnitPrecision_ShowsBothUnitsWhenOneHourOrMore(): void
    {
        // Verify two-unit precision is working for >= 1 hour
        $result = $this->formatRelativeTime(4983); // 1 hour 23 minutes 3 seconds
        $this->assertStringContainsString('hour', $result);
        $this->assertStringContainsString('minute', $result);
        $this->assertStringContainsString('ago', $result);
        
        // Verify single unit for < 1 hour
        $result = $this->formatRelativeTime(125); // 2 minutes 5 seconds
        $this->assertStringContainsString('minute', $result);
        $this->assertStringNotContainsString('second', $result);
        $this->assertStringContainsString('ago', $result);
    }
}


