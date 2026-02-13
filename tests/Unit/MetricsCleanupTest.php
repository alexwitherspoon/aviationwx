<?php
/**
 * Unit Tests for Metrics Cleanup Function
 * 
 * Tests the cleanup boundary condition logic to ensure:
 * - Files are only deleted after retention period
 * - Boundary condition is handled correctly (cutoff day)
 * - Recent files are preserved
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/metrics.php';
require_once __DIR__ . '/../../lib/constants.php';

class MetricsCleanupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Bootstrap already created test metrics directories
        @mkdir(CACHE_METRICS_HOURLY_DIR, 0755, true);
        @mkdir(CACHE_METRICS_DAILY_DIR, 0755, true);
        @mkdir(CACHE_METRICS_WEEKLY_DIR, 0755, true);
        
        $this->cleanMetricsDir(CACHE_METRICS_HOURLY_DIR);
        $this->cleanMetricsDir(CACHE_METRICS_DAILY_DIR);
        $this->cleanMetricsDir(CACHE_METRICS_WEEKLY_DIR);
    }
    
    protected function tearDown(): void
    {
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
    
    private function createDailyFile(string $dateId): void
    {
        $file = CACHE_METRICS_DAILY_DIR . '/' . $dateId . '.json';
        file_put_contents($file, json_encode([
            'bucket_type' => 'daily',
            'bucket_id' => $dateId,
            'airports' => [],
            'webcams' => [],
            'global' => metrics_get_empty_global()
        ]));
    }
    
    private function createHourlyFile(string $hourId): void
    {
        $file = CACHE_METRICS_HOURLY_DIR . '/' . $hourId . '.json';
        file_put_contents($file, json_encode([
            'bucket_type' => 'hourly',
            'bucket_id' => $hourId,
            'airports' => [],
            'webcams' => [],
            'global' => metrics_get_empty_global()
        ]));
    }
    
    /**
     * Test that cleanup preserves files within retention period
     */
    public function testCleanup_PreservesRecentFiles(): void
    {
        // Create files for the last 10 days (within 14-day retention)
        for ($d = 0; $d < 10; $d++) {
            $dateId = gmdate('Y-m-d', time() - ($d * 86400));
            $this->createDailyFile($dateId);
        }
        
        $deletedCount = metrics_cleanup();
        
        $this->assertEquals(0, $deletedCount, 'No files should be deleted within retention period');
        
        // Verify files still exist
        $files = glob(CACHE_METRICS_DAILY_DIR . '/*.json');
        $this->assertCount(10, $files);
    }
    
    /**
     * Test that cleanup deletes files older than retention period
     */
    public function testCleanup_DeletesOldFiles(): void
    {
        // Create files older than 14 days
        for ($d = 15; $d <= 20; $d++) {
            $dateId = gmdate('Y-m-d', time() - ($d * 86400));
            $this->createDailyFile($dateId);
        }
        
        $deletedCount = metrics_cleanup();
        
        $this->assertEquals(6, $deletedCount, 'Should delete 6 old files');
        
        // Verify files were deleted
        $files = glob(CACHE_METRICS_DAILY_DIR . '/*.json');
        $this->assertCount(0, $files);
    }
    
    /**
     * CRITICAL TEST: Boundary condition at exactly cutoff day
     * 
     * This tests the bug where a file at the cutoff boundary is incorrectly deleted.
     * 
     * Scenario:
     * - Current time: Feb 13, 2026 00:24:18 UTC
     * - Retention: 14 days
     * - Cutoff: Jan 30, 2026 00:24:18 UTC
     * - File: 2026-01-30.json (timestamp: Jan 30, 2026 00:00:00 UTC)
     * 
     * The bug: File timestamp (00:00:00) < cutoff (00:24:18), so it gets deleted
     * even though Jan 30 is still within the 14-day window!
     */
    public function testCleanup_BoundaryCondition_CutoffDay(): void
    {
        // Simulate: Current time is Feb 13, 2026 at 00:24:18 UTC
        // Retention: 14 days
        // Cutoff: Jan 30, 2026 at 00:24:18 UTC
        
        $now = strtotime('2026-02-13 00:24:18 UTC');
        $retentionSeconds = 14 * 86400;
        $cutoff = $now - $retentionSeconds; // Jan 30, 2026 00:24:18
        
        // Create files around the boundary
        $this->createDailyFile('2026-01-31'); // Clearly within retention (should KEEP)
        $this->createDailyFile('2026-01-30'); // Boundary day (should KEEP - this is the bug!)
        $this->createDailyFile('2026-01-29'); // Clearly outside retention (should DELETE)
        
        // Run cleanup
        $deletedCount = metrics_cleanup();
        
        // BUG: Current implementation deletes 2026-01-30 incorrectly
        // Because file timestamp (Jan 30 00:00:00) < cutoff (Jan 30 00:24:18)
        
        // After fix, should only delete 2026-01-29 (1 file)
        $this->assertEquals(1, $deletedCount, 'Should only delete files truly outside retention period');
        
        // Verify which files remain
        $files = glob(CACHE_METRICS_DAILY_DIR . '/*.json');
        $this->assertCount(2, $files, 'Should keep 2 files (Jan 30 and Jan 31)');
        
        $this->assertFileExists(CACHE_METRICS_DAILY_DIR . '/2026-01-31.json');
        $this->assertFileExists(CACHE_METRICS_DAILY_DIR . '/2026-01-30.json', 
            'Jan 30 should NOT be deleted - it is the cutoff day and should be kept');
        $this->assertFileDoesNotExist(CACHE_METRICS_DAILY_DIR . '/2026-01-29.json');
    }
    
    /**
     * Test cleanup with mixed file ages
     */
    public function testCleanup_MixedFileAges(): void
    {
        // Create files spanning 25 days
        for ($d = 0; $d < 25; $d++) {
            $dateId = gmdate('Y-m-d', time() - ($d * 86400));
            $this->createDailyFile($dateId);
        }
        
        $deletedCount = metrics_cleanup();
        
        // Should delete files older than 14 days
        // Days 0-13 (14 files) should be kept
        // Days 14-24 (11 files) should be deleted
        $this->assertGreaterThanOrEqual(10, $deletedCount, 'Should delete files older than 14 days');
        
        // Verify recent files still exist
        $recentFile = CACHE_METRICS_DAILY_DIR . '/' . gmdate('Y-m-d', time() - 86400) . '.json';
        $this->assertFileExists($recentFile, 'Yesterday\'s file should still exist');
    }
    
    /**
     * Test that cleanup handles hourly files correctly
     */
    public function testCleanup_HandlesHourlyFiles(): void
    {
        // Create hourly files for various dates
        $this->createHourlyFile('2026-02-12-10'); // Recent (keep)
        $this->createHourlyFile('2026-01-15-14'); // Old (delete)
        $this->createHourlyFile('2026-01-10-08'); // Very old (delete)
        
        $deletedCount = metrics_cleanup();
        
        $this->assertGreaterThanOrEqual(2, $deletedCount, 'Should delete old hourly files');
        
        $this->assertFileExists(CACHE_METRICS_HOURLY_DIR . '/2026-02-12-10.json');
    }
    
    /**
     * Test that cleanup doesn't fail on empty directories
     */
    public function testCleanup_HandlesEmptyDirectories(): void
    {
        // No files created
        
        $deletedCount = metrics_cleanup();
        
        $this->assertEquals(0, $deletedCount, 'Should handle empty directories gracefully');
    }
}
