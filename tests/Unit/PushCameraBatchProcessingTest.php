<?php
/**
 * Unit Tests for Push Camera Batch Processing
 * 
 * Tests the batch processing logic for push cameras:
 * - File ordering (newest first, then oldest-to-newest)
 * - Extended timeout for large backlogs
 * - Batch limit enforcement
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../../lib/cache-paths.php';
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/webcam-acquisition.php';

class PushCameraBatchProcessingTest extends TestCase
{
    private string $testDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testDir = sys_get_temp_dir() . '/push_batch_test_' . uniqid();
        @mkdir($this->testDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->testDir)) {
            $this->recursiveDelete($this->testDir);
        }
        parent::tearDown();
    }

    private function recursiveDelete(string $dir): void
    {
        $files = glob($dir . '/*');
        foreach ($files as $file) {
            if (is_dir($file)) {
                $this->recursiveDelete($file);
            } else {
                @unlink($file);
            }
        }
        @rmdir($dir);
    }

    /**
     * Create a test JPEG file with specific mtime
     */
    private function createTestFile(string $filename, int $mtime): string
    {
        $path = $this->testDir . '/' . $filename;
        
        // Create minimal JPEG
        $img = imagecreatetruecolor(100, 100);
        imagejpeg($img, $path, 90);
        imagedestroy($img);
        
        // Set specific mtime
        touch($path, $mtime);
        
        return $path;
    }

    // ========================================
    // TEST GROUP 1: Constants
    // ========================================

    /**
     * Test that batch processing constants are defined
     */
    public function testBatchProcessingConstantsDefined()
    {
        $this->assertTrue(defined('PUSH_BATCH_LIMIT'), 'PUSH_BATCH_LIMIT should be defined');
        $this->assertTrue(defined('PUSH_EXTENDED_TIMEOUT_THRESHOLD'), 'PUSH_EXTENDED_TIMEOUT_THRESHOLD should be defined');
        $this->assertTrue(defined('PUSH_EXTENDED_TIMEOUT_SECONDS'), 'PUSH_EXTENDED_TIMEOUT_SECONDS should be defined');
        
        $this->assertEquals(30, PUSH_BATCH_LIMIT, 'PUSH_BATCH_LIMIT should be 30');
        $this->assertEquals(10, PUSH_EXTENDED_TIMEOUT_THRESHOLD, 'PUSH_EXTENDED_TIMEOUT_THRESHOLD should be 10');
        $this->assertEquals(300, PUSH_EXTENDED_TIMEOUT_SECONDS, 'PUSH_EXTENDED_TIMEOUT_SECONDS should be 300 (5 minutes)');
    }

    // ========================================
    // TEST GROUP 2: File Ordering Logic
    // ========================================

    /**
     * Test that getOrderedFiles returns newest file first
     * 
     * This is critical for pilot safety - pilots must see current conditions.
     */
    public function testGetOrderedFiles_NewestFileFirst()
    {
        // Create strategy with test directory as upload dir
        $strategy = $this->createStrategyWithUploadDir($this->testDir);
        
        $now = time();
        
        // Create files with different mtimes (oldest to newest)
        $this->createTestFile('oldest.jpg', $now - 600);  // 10 min ago
        $this->createTestFile('middle.jpg', $now - 300);  // 5 min ago  
        $this->createTestFile('newest.jpg', $now - 10);   // 10 sec ago
        
        $result = $strategy->getOrderedFiles(10);
        $files = $result['files'];
        
        $this->assertCount(3, $files, 'Should return all 3 files');
        $this->assertStringEndsWith('newest.jpg', $files[0], 'First file should be newest');
    }

    /**
     * Test that after newest, files are ordered oldest-to-newest
     * 
     * This ensures backlog files are processed before they age out.
     */
    public function testGetOrderedFiles_ThenOldestToNewest()
    {
        $strategy = $this->createStrategyWithUploadDir($this->testDir);
        
        $now = time();
        
        // Create 5 files with different mtimes
        $this->createTestFile('file_a.jpg', $now - 500);  // oldest
        $this->createTestFile('file_b.jpg', $now - 400);
        $this->createTestFile('file_c.jpg', $now - 300);
        $this->createTestFile('file_d.jpg', $now - 200);
        $this->createTestFile('file_e.jpg', $now - 10);   // newest
        
        $result = $strategy->getOrderedFiles(10);
        $files = $result['files'];
        
        $this->assertCount(5, $files, 'Should return all 5 files');
        
        // First should be newest
        $this->assertStringEndsWith('file_e.jpg', $files[0], 'First file should be newest (file_e)');
        
        // Rest should be oldest to newest
        $this->assertStringEndsWith('file_a.jpg', $files[1], 'Second file should be oldest (file_a)');
        $this->assertStringEndsWith('file_b.jpg', $files[2], 'Third file should be file_b');
        $this->assertStringEndsWith('file_c.jpg', $files[3], 'Fourth file should be file_c');
        $this->assertStringEndsWith('file_d.jpg', $files[4], 'Fifth file should be file_d');
    }

    /**
     * Test that batch limit is respected
     */
    public function testGetOrderedFiles_RespectsLimit()
    {
        $strategy = $this->createStrategyWithUploadDir($this->testDir);
        
        $now = time();
        
        // Create 10 files
        for ($i = 0; $i < 10; $i++) {
            $this->createTestFile("file_{$i}.jpg", $now - (100 * $i) - 10);
        }
        
        // Request only 5
        $result = $strategy->getOrderedFiles(5);
        $files = $result['files'];
        
        $this->assertCount(5, $files, 'Should respect the limit of 5');
        $this->assertEquals(10, $result['total_pending'], 'Should report total pending as 10');
    }

    /**
     * Test that files too new are skipped (still being written)
     */
    public function testGetOrderedFiles_SkipsFilesTooNew()
    {
        $strategy = $this->createStrategyWithUploadDir($this->testDir);
        
        $now = time();
        
        // Create a file that's too new (< 3 seconds old)
        $this->createTestFile('too_new.jpg', $now);
        
        // Create a valid file
        $this->createTestFile('valid.jpg', $now - 60);
        
        $result = $strategy->getOrderedFiles(10);
        $files = $result['files'];
        
        $this->assertCount(1, $files, 'Should only return the valid file');
        $this->assertStringEndsWith('valid.jpg', $files[0], 'Should be the valid file');
    }

    /**
     * Test that files too old are deleted
     */
    public function testGetOrderedFiles_DeletesFilesTooOld()
    {
        $strategy = $this->createStrategyWithUploadDir($this->testDir);
        
        $now = time();
        
        // Create a file that's too old (> 30 min default)
        $tooOldPath = $this->createTestFile('too_old.jpg', $now - 2000);
        
        // Create a valid file
        $this->createTestFile('valid.jpg', $now - 60);
        
        $result = $strategy->getOrderedFiles(10);
        
        $this->assertFileDoesNotExist($tooOldPath, 'File too old should be deleted');
        $this->assertCount(1, $result['files'], 'Should only return the valid file');
    }

    /**
     * Test empty directory returns empty result
     */
    public function testGetOrderedFiles_EmptyDirectory()
    {
        $strategy = $this->createStrategyWithUploadDir($this->testDir);
        
        $result = $strategy->getOrderedFiles(10);
        
        $this->assertEmpty($result['files'], 'Should return empty array for empty directory');
        $this->assertEquals(0, $result['total_pending'], 'Total pending should be 0');
    }

    // ========================================
    // TEST GROUP 3: Batch Processing Scenario
    // ========================================

    /**
     * Test realistic backlog scenario with 60 files
     * 
     * Simulates the user's actual use case.
     */
    public function testGetOrderedFiles_LargeBacklogScenario()
    {
        $strategy = $this->createStrategyWithUploadDir($this->testDir);
        
        $now = time();
        
        // Create 60 files (simulating backlog), oldest is 25 min ago
        for ($i = 0; $i < 60; $i++) {
            // Files from 25 min ago to 10 sec ago
            $age = 10 + ($i * 25); // 10s, 35s, 60s, ... ~25min
            $this->createTestFile("backlog_{$i}.jpg", $now - $age);
        }
        
        // Get first batch (limit 30)
        $result = $strategy->getOrderedFiles(30);
        $files = $result['files'];
        
        $this->assertCount(30, $files, 'Should return 30 files (batch limit)');
        $this->assertEquals(60, $result['total_pending'], 'Should report 60 total pending');
        
        // First file should be newest (backlog_0, 10s old)
        $this->assertStringEndsWith('backlog_0.jpg', $files[0], 
            'First file should be newest for pilot safety');
        
        // Second file should be oldest remaining (backlog_59)
        $this->assertStringEndsWith('backlog_59.jpg', $files[1],
            'Second file should be oldest to prevent aging out');
    }

    // ========================================
    // TEST GROUP 4: acquireFile method
    // ========================================

    /**
     * Test acquireFile returns skip for missing file
     */
    public function testAcquireFile_SkipsForMissingFile()
    {
        $strategy = $this->createStrategyWithUploadDir($this->testDir);
        
        $result = $strategy->acquireFile('/nonexistent/file.jpg');
        
        $this->assertFalse($result->success, 'Should not succeed for missing file');
        $this->assertTrue($result->isSkip(), 'Should be a skip, not a failure');
        $this->assertEquals('file_missing', $result->getSkipReason(), 'Reason should be file_missing');
    }

    /**
     * Test acquireFile checks file age immediately (fail fast)
     */
    public function testAcquireFile_ChecksFileAgeFirst()
    {
        $strategy = $this->createStrategyWithUploadDir($this->testDir);
        
        $now = time();
        
        // Create a file that's too old (> 30 min)
        $tooOldPath = $this->createTestFile('too_old.jpg', $now - 2000);
        
        $result = $strategy->acquireFile($tooOldPath);
        
        $this->assertFalse($result->success, 'Should not succeed for old file');
        $this->assertTrue($result->isSkip(), 'Should be a skip');
        $this->assertEquals('file_too_old', $result->getSkipReason(), 'Reason should be file_too_old');
        $this->assertFileDoesNotExist($tooOldPath, 'Old file should be deleted');
    }

    /**
     * Test acquireFile skips files that are too new
     */
    public function testAcquireFile_SkipsFilesTooNew()
    {
        $strategy = $this->createStrategyWithUploadDir($this->testDir);
        
        // Create a file that's too new (< 3 seconds)
        $tooNewPath = $this->createTestFile('too_new.jpg', time());
        
        $result = $strategy->acquireFile($tooNewPath);
        
        $this->assertFalse($result->success, 'Should not succeed for new file');
        $this->assertTrue($result->isSkip(), 'Should be a skip');
        $this->assertEquals('file_too_new', $result->getSkipReason(), 'Reason should be file_too_new');
    }

    // ========================================
    // Helper Methods
    // ========================================

    /**
     * Create a PushAcquisitionStrategy with a custom upload directory
     * 
     * Uses reflection to set the private uploadDir property.
     */
    private function createStrategyWithUploadDir(string $uploadDir): PushAcquisitionStrategy
    {
        $strategy = new PushAcquisitionStrategy(
            'test',
            0,
            ['push_config' => ['username' => 'testuser']],
            ['name' => 'Test Airport', 'timezone' => 'UTC']
        );
        
        // Use reflection to set the upload directory
        $reflection = new ReflectionClass($strategy);
        $property = $reflection->getProperty('uploadDir');
        $property->setAccessible(true);
        $property->setValue($strategy, $uploadDir);
        
        return $strategy;
    }
}
