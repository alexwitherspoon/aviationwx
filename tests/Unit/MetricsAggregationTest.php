<?php
/**
 * Unit Tests for Metrics Aggregation Functions
 * 
 * Tests daily and weekly aggregation logic to ensure:
 * - All hourly files are properly combined into daily
 * - All daily files are properly combined into weekly
 * - Missing files are handled gracefully
 * - Airport and webcam breakdowns are preserved
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/metrics.php';
require_once __DIR__ . '/../../lib/constants.php';

class MetricsAggregationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Bootstrap already created test metrics directories
        // Ensure they exist and are clean for this test
        @mkdir(CACHE_METRICS_HOURLY_DIR, 0755, true);
        @mkdir(CACHE_METRICS_DAILY_DIR, 0755, true);
        @mkdir(CACHE_METRICS_WEEKLY_DIR, 0755, true);
        
        $this->cleanMetricsDir(CACHE_METRICS_HOURLY_DIR);
        $this->cleanMetricsDir(CACHE_METRICS_DAILY_DIR);
        $this->cleanMetricsDir(CACHE_METRICS_WEEKLY_DIR);
    }
    
    protected function tearDown(): void
    {
        // Clean up test files
        $this->cleanMetricsDir(CACHE_METRICS_HOURLY_DIR);
        $this->cleanMetricsDir(CACHE_METRICS_DAILY_DIR);
        $this->cleanMetricsDir(CACHE_METRICS_WEEKLY_DIR);
        
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
    
    private function createHourlyFile(string $dateId, array $data): void
    {
        $file = CACHE_METRICS_HOURLY_DIR . '/' . $dateId . '.json';
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
    }
    
    private function createDailyFile(string $dateId, array $data): void
    {
        $file = CACHE_METRICS_DAILY_DIR . '/' . $dateId . '.json';
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
    }
    
    /**
     * Test that daily aggregation combines all 24 hourly files
     */
    public function testDailyAggregation_CombinesAll24HourlyFiles(): void
    {
        $dateId = '2026-02-10';
        
        // Create 24 hourly files with incrementing view counts
        for ($h = 0; $h < 24; $h++) {
            $hourId = $dateId . '-' . sprintf('%02d', $h);
            $this->createHourlyFile($hourId, [
                'bucket_type' => 'hourly',
                'bucket_id' => $hourId,
                'airports' => [
                    'kspb' => ['page_views' => 10, 'weather_requests' => 5, 'webcam_requests' => 3]
                ],
                'webcams' => [],
                'global' => [
                    'page_views' => 10,
                    'weather_requests' => 5,
                    'webcam_requests' => 3,
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
            ]);
        }
        
        // Run daily aggregation
        $result = metrics_aggregate_daily($dateId);
        
        $this->assertTrue($result, 'Daily aggregation should succeed');
        
        // Verify daily file was created
        $dailyFile = CACHE_METRICS_DAILY_DIR . '/' . $dateId . '.json';
        $this->assertFileExists($dailyFile);
        
        // Verify aggregated data
        $dailyData = json_decode(file_get_contents($dailyFile), true);
        
        $this->assertEquals('daily', $dailyData['bucket_type']);
        $this->assertEquals($dateId, $dailyData['bucket_id']);
        
        // 24 hours × 10 views/hour = 240 total views
        $this->assertEquals(240, $dailyData['airports']['kspb']['page_views']);
        $this->assertEquals(120, $dailyData['airports']['kspb']['weather_requests']);
        $this->assertEquals(72, $dailyData['airports']['kspb']['webcam_requests']);
        $this->assertEquals(240, $dailyData['global']['page_views']);
    }
    
    /**
     * Test that daily aggregation handles missing hourly files gracefully
     */
    public function testDailyAggregation_HandlesMissingHourlyFiles(): void
    {
        $dateId = '2026-02-10';
        
        // Create only 12 hourly files (missing hours 12-23)
        for ($h = 0; $h < 12; $h++) {
            $hourId = $dateId . '-' . sprintf('%02d', $h);
            $this->createHourlyFile($hourId, [
                'bucket_type' => 'hourly',
                'bucket_id' => $hourId,
                'airports' => [
                    'kspb' => ['page_views' => 10, 'weather_requests' => 5, 'webcam_requests' => 2]
                ],
                'webcams' => [],
                'global' => metrics_get_empty_global()
            ]);
        }
        
        // Run daily aggregation
        $result = metrics_aggregate_daily($dateId);
        
        $this->assertTrue($result, 'Daily aggregation should succeed even with missing files');
        
        // Verify aggregated data only includes available hours
        $dailyFile = CACHE_METRICS_DAILY_DIR . '/' . $dateId . '.json';
        $dailyData = json_decode(file_get_contents($dailyFile), true);
        
        // 12 hours × 10 views/hour = 120 total views (not 240)
        $this->assertEquals(120, $dailyData['airports']['kspb']['page_views']);
        $this->assertEquals(60, $dailyData['airports']['kspb']['weather_requests']);
    }
    
    /**
     * Test that daily aggregation preserves multiple airports
     */
    public function testDailyAggregation_PreservesMultipleAirports(): void
    {
        $dateId = '2026-02-10';
        
        // Create hourly files with multiple airports
        for ($h = 0; $h < 3; $h++) {
            $hourId = $dateId . '-' . sprintf('%02d', $h);
            $this->createHourlyFile($hourId, [
                'bucket_type' => 'hourly',
                'bucket_id' => $hourId,
                'airports' => [
                    'kspb' => ['page_views' => 10, 'weather_requests' => 5, 'webcam_requests' => 2],
                    'kboi' => ['page_views' => 20, 'weather_requests' => 8, 'webcam_requests' => 4],
                    'kpfc' => ['page_views' => 5, 'weather_requests' => 2, 'webcam_requests' => 1]
                ],
                'webcams' => [],
                'global' => metrics_get_empty_global()
            ]);
        }
        
        $result = metrics_aggregate_daily($dateId);
        $this->assertTrue($result);
        
        $dailyFile = CACHE_METRICS_DAILY_DIR . '/' . $dateId . '.json';
        $dailyData = json_decode(file_get_contents($dailyFile), true);
        
        // Verify all airports are present with correct totals
        $this->assertArrayHasKey('kspb', $dailyData['airports']);
        $this->assertArrayHasKey('kboi', $dailyData['airports']);
        $this->assertArrayHasKey('kpfc', $dailyData['airports']);
        
        $this->assertEquals(30, $dailyData['airports']['kspb']['page_views']); // 3 × 10
        $this->assertEquals(60, $dailyData['airports']['kboi']['page_views']); // 3 × 20
        $this->assertEquals(15, $dailyData['airports']['kpfc']['page_views']); // 3 × 5
    }
    
    /**
     * Test that weekly aggregation combines 7 daily files
     */
    public function testWeeklyAggregation_Combines7DailyFiles(): void
    {
        $weekId = '2026-W07'; // Week 7 of 2026
        
        // Week 7 of 2026: Feb 9-15 (Monday-Sunday)
        $dates = ['2026-02-09', '2026-02-10', '2026-02-11', '2026-02-12', '2026-02-13', '2026-02-14', '2026-02-15'];
        
        foreach ($dates as $dateId) {
            $this->createDailyFile($dateId, [
                'bucket_type' => 'daily',
                'bucket_id' => $dateId,
                'airports' => [
                    'kspb' => ['page_views' => 100, 'weather_requests' => 50, 'webcam_requests' => 30]
                ],
                'webcams' => [],
                'global' => metrics_get_empty_global()
            ]);
        }
        
        $result = metrics_aggregate_weekly($weekId);
        $this->assertTrue($result, 'Weekly aggregation should succeed');
        
        $weeklyFile = CACHE_METRICS_WEEKLY_DIR . '/' . $weekId . '.json';
        $this->assertFileExists($weeklyFile);
        
        $weeklyData = json_decode(file_get_contents($weeklyFile), true);
        
        $this->assertEquals('weekly', $weeklyData['bucket_type']);
        $this->assertEquals($weekId, $weeklyData['bucket_id']);
        
        // 7 days × 100 views/day = 700 total views
        $this->assertEquals(700, $weeklyData['airports']['kspb']['page_views']);
        $this->assertEquals(350, $weeklyData['airports']['kspb']['weather_requests']);
    }
    
    /**
     * Test that weekly aggregation handles partial weeks
     */
    public function testWeeklyAggregation_HandlesPartialWeeks(): void
    {
        $weekId = '2026-W07';
        
        // Only create 3 days of the week
        $dates = ['2026-02-09', '2026-02-10', '2026-02-11'];
        
        foreach ($dates as $dateId) {
            $this->createDailyFile($dateId, [
                'bucket_type' => 'daily',
                'bucket_id' => $dateId,
                'airports' => [
                    'kspb' => ['page_views' => 100, 'weather_requests' => 50, 'webcam_requests' => 30]
                ],
                'webcams' => [],
                'global' => metrics_get_empty_global()
            ]);
        }
        
        $result = metrics_aggregate_weekly($weekId);
        $this->assertTrue($result, 'Weekly aggregation should handle partial weeks');
        
        $weeklyFile = CACHE_METRICS_WEEKLY_DIR . '/' . $weekId . '.json';
        $weeklyData = json_decode(file_get_contents($weeklyFile), true);
        
        // Only 3 days × 100 views/day = 300 total views
        $this->assertEquals(300, $weeklyData['airports']['kspb']['page_views']);
    }
    
    /**
     * Test that aggregation merges webcam metrics correctly
     */
    public function testDailyAggregation_MergesWebcamMetrics(): void
    {
        $dateId = '2026-02-10';
        
        for ($h = 0; $h < 3; $h++) {
            $hourId = $dateId . '-' . sprintf('%02d', $h);
            $this->createHourlyFile($hourId, [
                'bucket_type' => 'hourly',
                'bucket_id' => $hourId,
                'airports' => [],
                'webcams' => [
                    'kspb_0' => [
                        'requests' => 50,
                        'by_format' => ['jpg' => 30, 'webp' => 20],
                        'by_size' => ['720' => 40, 'original' => 10]
                    ]
                ],
                'global' => metrics_get_empty_global()
            ]);
        }
        
        $result = metrics_aggregate_daily($dateId);
        $this->assertTrue($result);
        
        $dailyFile = CACHE_METRICS_DAILY_DIR . '/' . $dateId . '.json';
        $dailyData = json_decode(file_get_contents($dailyFile), true);
        
        $this->assertArrayHasKey('kspb_0', $dailyData['webcams']);
        $this->assertEquals(150, $dailyData['webcams']['kspb_0']['requests']); // 3 × 50
        $this->assertEquals(90, $dailyData['webcams']['kspb_0']['by_format']['jpg']); // 3 × 30
        $this->assertEquals(60, $dailyData['webcams']['kspb_0']['by_format']['webp']); // 3 × 20
        $this->assertEquals(120, $dailyData['webcams']['kspb_0']['by_size']['720']); // 3 × 40
    }
}
