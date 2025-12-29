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
        $this->assertStringEndsWith('kspb_0.jpg', $jpg);
        
        $webp = getCacheFile('kspb', 1, 'webp');
        $this->assertStringEndsWith('kspb_1.webp', $webp);
        
        $avif = getCacheFile('ksea', 2, 'avif');
        $this->assertStringEndsWith('ksea_2.avif', $avif);
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
        $this->assertEquals('image/avif', getMimeTypeForFormat('avif'));
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
            'webp' => ['exists' => true, 'mtime' => $oldMtime - 5, 'valid' => true], // Within tolerance
            'avif' => ['exists' => true, 'mtime' => $oldMtime + 5, 'valid' => true] // Within tolerance
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
            'webp' => ['exists' => true, 'mtime' => $recentMtime, 'valid' => true],
            'avif' => ['exists' => true, 'mtime' => $recentMtime, 'valid' => true]
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
            'webp' => ['exists' => true, 'mtime' => time() - 30, 'valid' => true], // Recent (different cycle)
            'avif' => ['exists' => true, 'mtime' => $oldMtime, 'valid' => true]
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
        $jpgFile = getCacheFile($this->testAirportId, $this->testCamIndex, 'jpg', 'primary');
        $webpFile = getCacheFile($this->testAirportId, $this->testCamIndex, 'webp', 'primary');
        $avifFile = getCacheFile($this->testAirportId, $this->testCamIndex, 'avif', 'primary');
        
        // Ensure directories exist
        $cacheDir = dirname(dirname($jpgFile));
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        
        // Create valid test files
        file_put_contents($jpgFile, "\xFF\xD8\xFF\xD9");
        file_put_contents($webpFile, 'RIFF' . pack('V', 0) . 'WEBP');
        // AVIF requires more complex structure, so we'll just check that it's detected
        file_put_contents($avifFile, str_repeat("\x00", 100)); // Invalid but exists
        
        $result = getFormatStatus($this->testAirportId, $this->testCamIndex);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('jpg', $result);
        $this->assertArrayHasKey('webp', $result);
        $this->assertArrayHasKey('avif', $result);
        
        $this->assertTrue($result['jpg']['exists']);
        $this->assertTrue($result['jpg']['valid']);
        $this->assertTrue($result['webp']['exists']);
        $this->assertTrue($result['webp']['valid']);
        $this->assertTrue($result['avif']['exists']);
        $this->assertFalse($result['avif']['valid']); // Invalid AVIF
        
        // Clean up
        @unlink($jpgFile);
        @unlink($webpFile);
        @unlink($avifFile);
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
        $this->assertFalse($result['avif']['exists']);
        $this->assertFalse($result['jpg']['valid']);
        $this->assertFalse($result['webp']['valid']);
        $this->assertFalse($result['avif']['valid']);
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
            'webp' => ['exists' => false, 'mtime' => 0, 'valid' => false, 'size' => 0],
            'avif' => ['exists' => false, 'mtime' => 0, 'valid' => false, 'size' => 0]
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
            'webp' => ['exists' => true, 'mtime' => $recentMtime, 'valid' => true, 'size' => 500],
            'avif' => ['exists' => false, 'mtime' => 0, 'valid' => false, 'size' => 0]
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
            'webp' => ['exists' => false, 'mtime' => 0, 'valid' => false, 'size' => 0],
            'avif' => ['exists' => false, 'mtime' => 0, 'valid' => false, 'size' => 0]
        ];
        
        $result = isFormatGenerating('webp', $formatStatus, $oldMtime, $refreshInterval, 'kspb', 0);
        $this->assertFalse($result, 'Old cycle should not be generating');
    }
    
    /**
     * Test findMostEfficientFormat() - All formats available
     */
    public function testFindMostEfficientFormat_AllFormatsAvailable(): void
    {
        // This test requires mocking getEnabledWebcamFormats()
        // For now, we'll test the logic assuming all formats are enabled
        $this->markTestIncomplete('Requires config mocking - test manually with airports.json');
    }
    
    /**
     * Test findMostEfficientFormat() - Only JPEG available
     */
    public function testFindMostEfficientFormat_OnlyJpegAvailable(): void
    {
        $formatStatus = [
            'jpg' => ['exists' => true, 'mtime' => time(), 'valid' => true, 'size' => 1000],
            'webp' => ['exists' => false, 'mtime' => 0, 'valid' => false, 'size' => 0],
            'avif' => ['exists' => false, 'mtime' => 0, 'valid' => false, 'size' => 0]
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
            'webp' => ['exists' => false, 'mtime' => 0, 'valid' => false, 'size' => 0],
            'avif' => ['exists' => false, 'mtime' => 0, 'valid' => false, 'size' => 0]
        ];
        
        $result = findMostEfficientFormat($formatStatus, 'kspb', 0);
        
        $this->assertNull($result, 'No formats available should return null');
    }
}

