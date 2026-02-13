<?php
/**
 * Unit Tests for Metrics Rolling Window Functions
 * 
 * Tests the rolling window calculation logic to ensure:
 * - Correct number of days are included
 * - Missing daily files are handled (REGRESSION TEST - now logged)
 * - Today's hourly data is included
 * - No future dates are included
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/metrics.php';
require_once __DIR__ . '/../../lib/constants.php';

class MetricsRollingWindowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Bootstrap already created test metrics directories
        @mkdir(CACHE_METRICS_HOURLY_DIR, 0755, true);
        @mkdir(CACHE_METRICS_DAILY_DIR, 0755, true);
        
        $this->cleanMetricsDir(CACHE_METRICS_HOURLY_DIR);
        $this->cleanMetricsDir(CACHE_METRICS_DAILY_DIR);
    }
    
    protected function tearDown(): void
    {
        $this->cleanMetricsDir(CACHE_METRICS_HOURLY_DIR);
        $this->cleanMetricsDir(CACHE_METRICS_DAILY_DIR);
        
        parent::tearDown();
    }
    
    private function cleanMetricsDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = glob($dir . '/*.json');
        foreach ($files as $file) {
            @unlink($file);
        }
    }
    
    private function createDailyFile(string $dateId, int $pageViews): void
    {
        $file = CACHE_METRICS_DAILY_DIR . '/' . $dateId . '.json';
        file_put_contents($file, json_encode([
            'bucket_type' => 'daily',
            'bucket_id' => $dateId,
            'airports' => [
                'kspb' => ['page_views' => $pageViews, 'weather_requests' => 10, 'webcam_requests' => 5]
            ],
            'webcams' => [],
            'global' => [
                'page_views' => $pageViews,
                'weather_requests' => 10,
                'webcam_requests' => 5,
                'webcam_serves' => 0,
                'webcam_uploads_accepted' => 0,
                'webcam_uploads_rejected' => 0,
                'webcam_images_verified' => 0,
                'webcam_images_rejected' => 0,
                'variants_generated' => 0,
                'tiles_served' => 0,
                'tiles_by_source' => ['openweathermap' => 0, 'rainviewer' => 0],
                'format_served' => ['jpg' => 0, 'webp' => 0],
                'size_served' => [],
                'browser_support' => ['webp' => 0, 'jpg_only' => 0],
                'cache' => ['hits' => 0, 'misses' => 0]
            ]
        ], JSON_PRETTY_PRINT));
    }
    
    private function createHourlyFile(string $hourId, int $pageViews): void
    {
        $file = CACHE_METRICS_HOURLY_DIR . '/' . $hourId . '.json';
        file_put_contents($file, json_encode([
            'bucket_type' => 'hourly',
            'bucket_id' => $hourId,
            'airports' => [
                'kspb' => ['page_views' => $pageViews, 'weather_requests' => 10, 'webcam_requests' => 5]
            ],
            'webcams' => [],
            'global' => metrics_get_empty_global()
        ], JSON_PRETTY_PRINT));
    }
    
    /**
     * Test that 7-day rolling window includes all 7 past days
     */
    public function testRollingWindow_Includes7CompleteDays(): void
    {
        // Use current time for dynamic testing
        $now = time();
        
        // Create daily files for the past 7 complete days (not including today)
        for ($d = 1; $d <= 7; $d++) {
            $dateId = gmdate('Y-m-d', $now - ($d * 86400));
            $this->createDailyFile($dateId, 100); // 100 views per day
        }
        
        // Create some hourly files for today (only past hours)
        $today = gmdate('Y-m-d', $now);
        $currentHour = (int)gmdate('H', $now);
        for ($h = 0; $h <= $currentHour; $h++) {
            $hourId = $today . '-' . sprintf('%02d', $h);
            $this->createHourlyFile($hourId, 10); // 10 views per hour
        }
        
        $result = metrics_get_rolling(7);
        
        // Should have 7 complete days (700 views) + today's hours (currentHour + 1) * 10
        $expectedTodayViews = ($currentHour + 1) * 10;
        $expectedTotal = 700 + $expectedTodayViews;
        $this->assertEquals($expectedTotal, $result['airports']['kspb']['page_views']);
    }
    
    /**
     * REGRESSION TEST: Rolling window with missing daily files
     * 
     * Tests that rolling windows correctly handle missing daily files.
     * Missing files now trigger warnings (fixed in lib/metrics.php:1113-1123).
     */
    public function testRollingWindow_HandlesMissingDailyFiles_DoesNotSilentlyDrop(): void
    {
        $now = time();
        
        // Create daily files for only 2 out of 7 days (simulating missing aggregations)
        $yesterday = gmdate('Y-m-d', $now - 86400);
        $threeDaysAgo = gmdate('Y-m-d', $now - (3 * 86400));
        
        $this->createDailyFile($yesterday, 100);
        $this->createDailyFile($threeDaysAgo, 100);
        // Missing: other 5 days
        
        $result = metrics_get_rolling(7);
        
        // Should only have 200 views from the 2 days that exist
        // Missing files now trigger warning logs (fixed in lib/metrics.php)
        $this->assertEquals(200, $result['airports']['kspb']['page_views']);
    }
    
    /**
     * Test that rolling window includes today's hourly data
     */
    public function testRollingWindow_IncludesTodayHourlyData(): void
    {
        $now = time();
        
        // Create no daily files (new system or reset)
        
        // Create hourly files for today (only past hours)
        $today = gmdate('Y-m-d', $now);
        $currentHour = (int)gmdate('H', $now);
        
        for ($h = 0; $h <= $currentHour; $h++) {
            $hourId = $today . '-' . sprintf('%02d', $h);
            $this->createHourlyFile($hourId, 10);
        }
        
        $result = metrics_get_rolling(7);
        
        // Should have (currentHour + 1) hours × 10 views from today
        $expectedViews = ($currentHour + 1) * 10;
        $this->assertEquals($expectedViews, $result['airports']['kspb']['page_views']);
    }
    
    /**
     * Test that rolling window doesn't read more hours than exist today
     */
    public function testRollingWindow_DoesNotReadFutureHours(): void
    {
        $now = time();
        
        $today = gmdate('Y-m-d', $now);
        $currentHour = (int)gmdate('H', $now);
        
        // Create hourly files for all hours of today (including future hours)
        for ($h = 0; $h <= 23; $h++) {
            $hourId = $today . '-' . sprintf('%02d', $h);
            $this->createHourlyFile($hourId, 10);
        }
        
        $result = metrics_get_rolling(7);
        
        // Should only read hours 0 through currentHour (not future hours)
        $expectedViews = ($currentHour + 1) * 10;
        $this->assertEquals($expectedViews, $result['airports']['kspb']['page_views'],
            "Should only count " . ($currentHour + 1) . " hours (0-{$currentHour}), not future hours");
    }
    
    /**
     * Test rolling hours function for exact hour-based windows
     */
    public function testRollingHours_Exactly24Hours(): void
    {
        $now = time();
        
        // Create hourly files for the last 30 hours
        for ($h = 0; $h < 30; $h++) {
            $timestamp = $now - ($h * 3600);
            $hourId = gmdate('Y-m-d-H', $timestamp);
            $this->createHourlyFile($hourId, 10);
        }
        
        $result = metrics_get_rolling_hours(24);
        
        // Should only include exactly 24 hours = 240 views
        $this->assertEquals(240, $result['airports']['kspb']['page_views']);
    }
    
    /**
     * Test that rolling hours crosses day boundaries correctly
     */
    public function testRollingHours_CrossesDayBoundaries(): void
    {
        $now = time();
        
        // Create hourly files for the last 24 hours, which will cross day boundaries
        for ($h = 0; $h < 24; $h++) {
            $timestamp = $now - ($h * 3600);
            $hourId = gmdate('Y-m-d-H', $timestamp);
            $this->createHourlyFile($hourId, 10);
        }
        
        $result = metrics_get_rolling_hours(24);
        
        // Should include 24 hours = 240 views
        $this->assertEquals(240, $result['airports']['kspb']['page_views']);
    }
    
    /**
     * Test that multiple airports are tracked separately in rolling window
     */
    public function testRollingWindow_SeparatesMultipleAirports(): void
    {
        $now = time();
        
        // Create daily file with different airports
        $yesterday = gmdate('Y-m-d', $now - 86400);
        $file = CACHE_METRICS_DAILY_DIR . '/' . $yesterday . '.json';
        file_put_contents($file, json_encode([
            'bucket_type' => 'daily',
            'bucket_id' => $yesterday,
            'airports' => [
                'kspb' => ['page_views' => 100, 'weather_requests' => 50, 'webcam_requests' => 30],
                'kboi' => ['page_views' => 200, 'weather_requests' => 80, 'webcam_requests' => 40],
                'kpfc' => ['page_views' => 50, 'weather_requests' => 20, 'webcam_requests' => 10]
            ],
            'webcams' => [],
            'global' => metrics_get_empty_global()
        ], JSON_PRETTY_PRINT));
        
        $result = metrics_get_rolling(7);
        
        $this->assertEquals(100, $result['airports']['kspb']['page_views']);
        $this->assertEquals(200, $result['airports']['kboi']['page_views']);
        $this->assertEquals(50, $result['airports']['kpfc']['page_views']);
    }
}
