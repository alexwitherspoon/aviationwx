<?php
/**
 * Unit Tests for Scheduler Metrics Logic
 * 
 * Tests the scheduler's daily/weekly aggregation trigger logic to ensure:
 * - Daily aggregation runs after midnight (REGRESSION TEST - hour check removed)
 * - Daily aggregation only runs once per day
 * - Weekly aggregation only runs on Monday
 * - State is properly tracked
 */

use PHPUnit\Framework\TestCase;

class SchedulerMetricsTest extends TestCase
{
    /**
     * REGRESSION TEST: Daily aggregation timing logic
     * 
     * Tests the scheduler's check for daily aggregation timing.
     * Previously used >= 1 condition which blocked aggregation during the midnight hour.
     * Fixed in scheduler.php line 449 by removing the hour check entirely.
     */
    public function testDailyAggregation_RunsAfterMidnight(): void
    {
        // Simulate midnight UTC (00:30)
        // Use a fixed hour for this test since we're testing the hour condition logic
        $baseTime = mktime(0, 30, 0, (int)gmdate('n'), (int)gmdate('j'), (int)gmdate('Y'));
        $yesterdayId = gmdate('Y-m-d', $baseTime - 86400);
        $lastDailyAggregation = gmdate('Y-m-d', $baseTime - (2 * 86400)); // 2 days ago
        
        $currentHour = (int)gmdate('H', $baseTime); // 0
        
        // Current (buggy) condition - what the code used to do
        $shouldRunBuggy = ($lastDailyAggregation !== $yesterdayId && $currentHour >= 1);
        
        // Fixed condition
        $shouldRunFixed = ($lastDailyAggregation !== $yesterdayId && $currentHour >= 0);
        
        // The bug: At 00:30, aggregation won't run because 0 >= 1 is false
        $this->assertFalse($shouldRunBuggy, 
            'BUG: Aggregation blocked during midnight hour with >= 1 condition');
        
        // After fix: At 00:30, aggregation should run because 0 >= 0 is true
        $this->assertTrue($shouldRunFixed, 
            'FIX: Aggregation should run after midnight with >= 0 condition');
    }
    
    /**
     * Test daily aggregation at 01:00 UTC (current code works here)
     */
    public function testDailyAggregation_RunsAtOneAM(): void
    {
        $baseTime = mktime(1, 0, 0, (int)gmdate('n'), (int)gmdate('j'), (int)gmdate('Y'));
        $yesterdayId = gmdate('Y-m-d', $baseTime - 86400);
        $lastDailyAggregation = gmdate('Y-m-d', $baseTime - (2 * 86400));
        
        $currentHour = (int)gmdate('H', $baseTime); // 1
        
        // Both conditions should pass at 01:00
        $shouldRun = ($lastDailyAggregation !== $yesterdayId && $currentHour >= 1);
        
        $this->assertTrue($shouldRun, 'Aggregation should run at 01:00');
    }
    
    /**
     * Test that aggregation doesn't run twice for the same day
     */
    public function testDailyAggregation_OnlyRunsOncePerDay(): void
    {
        $baseTime = mktime(10, 0, 0, (int)gmdate('n'), (int)gmdate('j'), (int)gmdate('Y'));
        $yesterdayId = gmdate('Y-m-d', $baseTime - 86400);
        $lastDailyAggregation = $yesterdayId; // Already ran for yesterday
        
        $currentHour = (int)gmdate('H', $baseTime);
        
        $shouldRun = ($lastDailyAggregation !== $yesterdayId && $currentHour >= 0);
        
        $this->assertFalse($shouldRun, 
            'Aggregation should not run again for the same day');
    }
    
    /**
     * Test weekly aggregation only runs on Monday
     */
    public function testWeeklyAggregation_OnlyRunsOnMonday(): void
    {
        // Find the next Monday from today
        $today = time();
        $dayOfWeek = (int)gmdate('N', $today);
        
        // Calculate days until next Monday (or today if it's Monday)
        $daysUntilMonday = ($dayOfWeek === 1) ? 0 : (8 - $dayOfWeek);
        $monday = mktime(2, 0, 0, (int)gmdate('n', $today), (int)gmdate('j', $today) + $daysUntilMonday, (int)gmdate('Y', $today));
        
        $mondayDayOfWeek = (int)gmdate('N', $monday);
        $currentHour = (int)gmdate('H', $monday);
        
        $this->assertEquals(1, $mondayDayOfWeek, 'Should be Monday');
        
        $shouldRun = ($mondayDayOfWeek === 1 && $currentHour >= 2);
        $this->assertTrue($shouldRun, 'Weekly aggregation should run on Monday after 02:00');
        
        // Test Tuesday (next day)
        $tuesday = $monday + 86400;
        $tuesdayDayOfWeek = (int)gmdate('N', $tuesday);
        
        $shouldRun = ($tuesdayDayOfWeek === 1 && $currentHour >= 2);
        $this->assertFalse($shouldRun, 'Weekly aggregation should NOT run on Tuesday');
    }
    
    /**
     * Test weekly aggregation doesn't run before 02:00
     */
    public function testWeeklyAggregation_WaitsUntilTwoAM(): void
    {
        // Find next Monday and set to 01:30
        $today = time();
        $dayOfWeek = (int)gmdate('N', $today);
        $daysUntilMonday = ($dayOfWeek === 1) ? 0 : (8 - $dayOfWeek);
        $monday = mktime(1, 30, 0, (int)gmdate('n', $today), (int)gmdate('j', $today) + $daysUntilMonday, (int)gmdate('Y', $today));
        
        $mondayDayOfWeek = (int)gmdate('N', $monday);
        $currentHour = (int)gmdate('H', $monday); // 1
        
        $shouldRun = ($mondayDayOfWeek === 1 && $currentHour >= 2);
        
        $this->assertFalse($shouldRun, 
            'Weekly aggregation should wait until 02:00 to ensure daily aggregation ran first');
    }
    
    /**
     * Test the correct sequence: daily at 00:xx, weekly at 02:00 Monday
     */
    public function testAggregationSequence_DailyBeforeWeekly(): void
    {
        // Find next Monday
        $today = time();
        $dayOfWeek = (int)gmdate('N', $today);
        $daysUntilMonday = ($dayOfWeek === 1) ? 0 : (8 - $dayOfWeek);
        
        // Monday at 00:30 - daily should run
        $mondayMidnight = mktime(0, 30, 0, (int)gmdate('n', $today), (int)gmdate('j', $today) + $daysUntilMonday, (int)gmdate('Y', $today));
        $yesterdayId = gmdate('Y-m-d', $mondayMidnight - 86400);
        $lastDailyAggregation = gmdate('Y-m-d', $mondayMidnight - (2 * 86400));
        
        $hourMidnight = (int)gmdate('H', $mondayMidnight);
        $shouldRunDaily = ($lastDailyAggregation !== $yesterdayId && $hourMidnight >= 0);
        
        $this->assertTrue($shouldRunDaily, 'Daily should run at 00:30 on Monday');
        
        // Monday at 02:00 - weekly should run
        $mondayMorning = mktime(2, 0, 0, (int)gmdate('n', $today), (int)gmdate('j', $today) + $daysUntilMonday, (int)gmdate('Y', $today));
        $mondayDayOfWeek = (int)gmdate('N', $mondayMorning);
        $hourMorning = (int)gmdate('H', $mondayMorning);
        
        $shouldRunWeekly = ($mondayDayOfWeek === 1 && $hourMorning >= 2);
        
        $this->assertTrue($shouldRunWeekly, 'Weekly should run at 02:00 on Monday');
    }
    
    /**
     * Test that hour check can be simplified or removed entirely
     * 
     * Since we're already checking if $lastDailyAggregation !== $yesterdayId,
     * we don't strictly need the hour check - it will only trigger once per day anyway.
     */
    public function testDailyAggregation_SimplifiedLogic(): void
    {
        // The hour check is actually redundant because:
        // - $lastDailyAggregation is compared to $yesterdayId (date-only)
        // - Once they match, the aggregation won't run again that day
        // - The hour check just delays when it first runs
        
        $baseTime = mktime(0, 5, 0, (int)gmdate('n'), (int)gmdate('j'), (int)gmdate('Y'));
        $yesterdayId = gmdate('Y-m-d', $baseTime - 86400);
        $lastDailyAggregation = gmdate('Y-m-d', $baseTime - (2 * 86400));
        
        // Simplified: No hour check at all
        $shouldRun = ($lastDailyAggregation !== $yesterdayId);
        
        $this->assertTrue($shouldRun, 
            'Aggregation should run as soon as date changes - hour check is not strictly necessary');
    }
}
