<?php
/**
 * Unit Tests for Webcam API Helper Functions
 * 
 * Tests helper functions in api/webcam.php:
 * - getCacheFile()
 * - buildWebcamUrl()
 * - getRefreshIntervalForCamera()
 * - getMimeTypeForFormat()
 * - isFromCurrentRefreshCycle()
 * - areAllFormatsFromSameCycle()
 * - getFormatStatus()
 * - isFormatGenerating()
 * - findMostEfficientFormat()
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../api/webcam.php';

class WebcamApiHelperTest extends TestCase
{
    private $testImageDir;
    private $testAirportId = 'kspb';
    private $testCamIndex = 0;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->testImageDir = sys_get_temp_dir() . '/webcam_api_helper_test_' . uniqid();
        @mkdir($this->testImageDir, 0755, true);
    }
    
    protected function tearDown(): void
    {
        // Clean up test images
        if (is_dir($this->testImageDir)) {
            $files = glob($this->testImageDir . '/*');
            foreach ($files as $file) {
                @unlink($file);
            }
            @rmdir($this->testImageDir);
        }
        parent::tearDown();
    }
    
    /**
     * Create a minimal JPEG file
     */
    private function createTestJpeg($filename): string
    {
        $path = $this->testImageDir . '/' . $filename;
        file_put_contents($path, "\xFF\xD8\xFF\xD9");
        return $path;
    }
    
    /**
     * Create a minimal WebP file
     */
    private function createTestWebp($filename): string
    {
        $path = $this->testImageDir . '/' . $filename;
        // Minimal WebP: RIFF header + WEBP
        file_put_contents($path, 'RIFF' . pack('V', 0) . 'WEBP');
        return $path;
    }
    
    /**
     * Test getCacheFile() - Valid paths
     */
    public function testGetCacheFile_ValidPaths(): void
    {
        $jpg = getCacheFile('kspb', 0, 'jpg');
        // getCacheFile returns symlink path or resolved timestamp-based file path
        $this->assertStringContainsString('kspb', $jpg);
        $this->assertStringContainsString('.jpg', $jpg);
        
        $webp = getCacheFile('kspb', 1, 'webp');
        // getCacheFile returns symlink path or resolved timestamp-based file path
        $this->assertStringContainsString('kspb', $webp);
        $this->assertStringContainsString('.webp', $webp);
    }
    
    /**
     * Test buildWebcamUrl() - Basic URL construction
     */
    public function testBuildWebcamUrl_BasicConstruction(): void
    {
        // Mock $_SERVER for URL building
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['HTTPS'] = 'off';
        
        $url = buildWebcamUrl('kspb', 0, 'webp', 1234567890);
        
        $this->assertStringContainsString('http://localhost', $url);
        $this->assertStringContainsString('webcam.php', $url);
        $this->assertStringContainsString('id=kspb', $url);
        $this->assertStringContainsString('cam=0', $url);
        $this->assertStringContainsString('fmt=webp', $url);
        $this->assertStringContainsString('v=', $url); // Hash parameter
        
        // Clean up
        unset($_SERVER['HTTP_HOST'], $_SERVER['HTTPS']);
    }
    
    /**
     * Test buildWebcamUrl() - HTTPS
     */
    public function testBuildWebcamUrl_Https(): void
    {
        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['HTTPS'] = 'on';
        
        $url = buildWebcamUrl('kspb', 0, 'jpg', 1234567890);
        
        $this->assertStringStartsWith('https://', $url);
        
        // Clean up
        unset($_SERVER['HTTP_HOST'], $_SERVER['HTTPS']);
    }
    
    /**
     * Test getMimeTypeForFormat() - All formats
     */
    public function testGetMimeTypeForFormat_AllFormats(): void
    {
        $this->assertEquals('image/jpeg', getMimeTypeForFormat('jpg'));
        $this->assertEquals('image/jpeg', getMimeTypeForFormat('jpeg'));
        $this->assertEquals('image/webp', getMimeTypeForFormat('webp'));
        $this->assertEquals('image/jpeg', getMimeTypeForFormat('unknown')); // Default
    }
    
    /**
     * Test isFromCurrentRefreshCycle() - Current cycle
     */
    public function testIsFromCurrentRefreshCycle_CurrentCycle(): void
    {
        $refreshInterval = 60;
        $recentMtime = time() - 30; // 30 seconds ago (within 60s interval)
        
        $result = isFromCurrentRefreshCycle($recentMtime, $refreshInterval);
        $this->assertTrue($result, 'Image from 30s ago should be in current 60s cycle');
    }
    
    /**
     * Test isFromCurrentRefreshCycle() - Stale cycle
     */
    public function testIsFromCurrentRefreshCycle_StaleCycle(): void
    {
        $refreshInterval = 60;
        $oldMtime = time() - 120; // 120 seconds ago (outside 60s interval)
        
        $result = isFromCurrentRefreshCycle($oldMtime, $refreshInterval);
        $this->assertFalse($result, 'Image from 120s ago should be stale for 60s cycle');
    }
    
    /**
     * Test isFromCurrentRefreshCycle() - Boundary case
     */
    public function testIsFromCurrentRefreshCycle_BoundaryCase(): void
    {
        $refreshInterval = 60;
        $boundaryMtime = time() - 60; // Exactly at boundary
        
        $result = isFromCurrentRefreshCycle($boundaryMtime, $refreshInterval);
        // Should be false (cacheAge < refreshInterval, so 60 < 60 is false)
        $this->assertFalse($result, 'Image exactly at boundary should be stale');
    }
    
    /**
     * Test areAllFormatsFromSameCycle() - All from same stale cycle
     */
    public function testAreAllFormatsFromSameCycle_SameStaleCycle(): void
    {
        $refreshInterval = 60;
        $oldMtime = time() - 120; // 120 seconds ago (stale)
        
        $formatStatus = [
            'jpg' => ['exists' => true, 'mtime' => $oldMtime, 'valid' => true],
            'webp' => ['exists' => true, 'mtime' => $oldMtime - 5, 'valid' => true] // Within tolerance
        ];
        
        $result = areAllFormatsFromSameCycle($formatStatus, $oldMtime, $refreshInterval);
        $this->assertTrue($result, 'All formats from same stale cycle should return true');
    }
    
    /**
     * Test areAllFormatsFromSameCycle() - Current cycle (should return false)
     */
    public function testAreAllFormatsFromSameCycle_CurrentCycle(): void
    {
        $refreshInterval = 60;
        $recentMtime = time() - 30; // 30 seconds ago (current cycle)
        
        $formatStatus = [
            'jpg' => ['exists' => true, 'mtime' => $recentMtime, 'valid' => true],
            'webp' => ['exists' => true, 'mtime' => $recentMtime, 'valid' => true]
        ];
        
        $result = areAllFormatsFromSameCycle($formatStatus, $recentMtime, $refreshInterval);
        $this->assertFalse($result, 'Current cycle should return false');
    }
    
    /**
     * Test areAllFormatsFromSameCycle() - Different cycles
     */
    public function testAreAllFormatsFromSameCycle_DifferentCycles(): void
    {
        $refreshInterval = 60;
        $oldMtime = time() - 120; // 120 seconds ago (stale)
        
        $formatStatus = [
            'jpg' => ['exists' => true, 'mtime' => $oldMtime, 'valid' => true],
            'webp' => ['exists' => true, 'mtime' => time() - 30, 'valid' => true] // Recent (different cycle)
        ];
        
        $result = areAllFormatsFromSameCycle($formatStatus, $oldMtime, $refreshInterval);
        $this->assertFalse($result, 'Different cycles should return false');
    }
    
    /**
     * Test getFormatStatus() - All formats exist
     */
    public function testGetFormatStatus_AllFormatsExist(): void
    {
        // Create test files in cache directory using getCacheFile
        require_once __DIR__ . '/../../lib/webcam-format-generation.php';
        $jpgFile = getCacheFile($this->testAirportId, $this->testCamIndex, 'jpg', 'original');
        $webpFile = getCacheFile($this->testAirportId, $this->testCamIndex, 'webp', 'original');
        
        // Ensure directories exist (need the direct parent dir of the files)
        $cacheDir = dirname($jpgFile);
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        
        // Create valid test files
        file_put_contents($jpgFile, "\xFF\xD8\xFF\xD9");
        file_put_contents($webpFile, 'RIFF' . pack('V', 0) . 'WEBP');
        
        $result = getFormatStatus($this->testAirportId, $this->testCamIndex);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('jpg', $result);
        $this->assertArrayHasKey('webp', $result);
        
        $this->assertTrue($result['jpg']['exists']);
        $this->assertTrue($result['jpg']['valid']);
        $this->assertTrue($result['webp']['exists']);
        $this->assertTrue($result['webp']['valid']);
        
        // Clean up
        @unlink($jpgFile);
        @unlink($webpFile);
    }
    
    /**
     * Test getFormatStatus() - No formats exist
     */
    public function testGetFormatStatus_NoFormatsExist(): void
    {
        // Use non-existent airport/cam
        $result = getFormatStatus('nonexistent', 999);
        
        $this->assertIsArray($result);
        $this->assertFalse($result['jpg']['exists']);
        $this->assertFalse($result['webp']['exists']);
        $this->assertFalse($result['jpg']['valid']);
        $this->assertFalse($result['webp']['valid']);
    }
    
    /**
     * Test isFormatGenerating() - Format is generating (missing)
     */
    public function testIsFormatGenerating_FormatMissing(): void
    {
        $refreshInterval = 60;
        $recentMtime = time() - 30; // Current cycle
        
        $formatStatus = [
            'jpg' => ['exists' => true, 'mtime' => $recentMtime, 'valid' => true, 'size' => 1000],
            'webp' => ['exists' => false, 'mtime' => 0, 'valid' => false, 'size' => 0]
        ];
        
        $result = isFormatGenerating('webp', $formatStatus, $recentMtime, $refreshInterval, 'kspb', 0);
        $this->assertTrue($result, 'Missing format in current cycle should be generating');
    }
    
    /**
     * Test isFormatGenerating() - Format is ready
     */
    public function testIsFormatGenerating_FormatReady(): void
    {
        $refreshInterval = 60;
        $recentMtime = time() - 30; // Current cycle
        
        $formatStatus = [
            'jpg' => ['exists' => true, 'mtime' => $recentMtime, 'valid' => true, 'size' => 1000],
            'webp' => ['exists' => true, 'mtime' => $recentMtime, 'valid' => true, 'size' => 500]
        ];
        
        $result = isFormatGenerating('webp', $formatStatus, $recentMtime, $refreshInterval, 'kspb', 0);
        $this->assertFalse($result, 'Ready format should not be generating');
    }
    
    /**
     * Test isFormatGenerating() - Old cycle (not generating)
     */
    public function testIsFormatGenerating_OldCycle(): void
    {
        $refreshInterval = 60;
        $oldMtime = time() - 120; // Stale cycle
        
        $formatStatus = [
            'jpg' => ['exists' => true, 'mtime' => $oldMtime, 'valid' => true, 'size' => 1000],
            'webp' => ['exists' => false, 'mtime' => 0, 'valid' => false, 'size' => 0]
        ];
        
        $result = isFormatGenerating('webp', $formatStatus, $oldMtime, $refreshInterval, 'kspb', 0);
        $this->assertFalse($result, 'Old cycle should not be generating');
    }
    
    /**
     * Test findMostEfficientFormat() - All formats available
     */
    public function testFindMostEfficientFormat_AllFormatsAvailable(): void
    {
        $fixture = __DIR__ . '/../Fixtures/airports.json.webp-test';
        if (!file_exists($fixture)) {
            $this->markTestSkipped('WebP fixture not found');
        }
        $originalPath = getenv('CONFIG_PATH');
        putenv('CONFIG_PATH=' . $fixture);
        clearConfigCache();
        try {
            $formatStatus = [
                'jpg' => ['exists' => true, 'mtime' => time(), 'valid' => true, 'size' => 1000],
                'webp' => ['exists' => true, 'mtime' => time(), 'valid' => true, 'size' => 600]
            ];
            $result = findMostEfficientFormat($formatStatus, 'kspb', 0);
            $this->assertNotNull($result);
            $this->assertEquals('image/webp', $result['type']);
            $this->assertStringEndsWith('.webp', $result['file']);
        } finally {
            putenv($originalPath !== false ? 'CONFIG_PATH=' . $originalPath : 'CONFIG_PATH');
            clearConfigCache();
        }
    }
    
    /**
     * Test findMostEfficientFormat() - Only JPEG available
     */
    public function testFindMostEfficientFormat_OnlyJpegAvailable(): void
    {
        $formatStatus = [
            'jpg' => ['exists' => true, 'mtime' => time(), 'valid' => true, 'size' => 1000],
            'webp' => ['exists' => false, 'mtime' => 0, 'valid' => false, 'size' => 0]
        ];
        
        $result = findMostEfficientFormat($formatStatus, 'kspb', 0);
        
        $this->assertNotNull($result);
        $this->assertEquals('image/jpeg', $result['type']);
        $this->assertStringEndsWith('.jpg', $result['file']);
    }
    
    /**
     * Test findMostEfficientFormat() - No formats available
     */
    public function testFindMostEfficientFormat_NoFormatsAvailable(): void
    {
        $formatStatus = [
            'jpg' => ['exists' => false, 'mtime' => 0, 'valid' => false, 'size' => 0],
            'webp' => ['exists' => false, 'mtime' => 0, 'valid' => false, 'size' => 0]
        ];
        
        $result = findMostEfficientFormat($formatStatus, 'kspb', 0);
        
        $this->assertNull($result, 'No formats available should return null');
    }
}

