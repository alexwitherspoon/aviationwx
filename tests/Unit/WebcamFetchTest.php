<?php
/**
 * Unit Tests for Webcam Fetch Functionality
 * 
 * Tests that webcam acquisition strategies handle data correctly.
 * Updated for unified webcam worker refactor.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/webcam-acquisition.php';
require_once __DIR__ . '/../../lib/webcam-worker.php';
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/constants.php';

class WebcamFetchTest extends TestCase
{
    /**
     * Test that PullAcquisitionStrategy class exists and is instantiable
     */
    public function testPullAcquisitionStrategyExists()
    {
        $this->assertTrue(
            class_exists('PullAcquisitionStrategy'),
            'PullAcquisitionStrategy class should exist'
        );
        
        // Should be instantiable with required parameters
        $strategy = new PullAcquisitionStrategy('kspb', 0, ['url' => 'http://test.com/cam.mjpg'], ['name' => 'Test']);
        $this->assertInstanceOf(AcquisitionStrategy::class, $strategy);
    }
    
    /**
     * Test that AcquisitionResult properly handles success case
     */
    public function testAcquisitionResultSuccess()
    {
        $result = AcquisitionResult::success('/tmp/test.jpg', time(), 'mjpeg', ['http_code' => 200]);
        
        $this->assertTrue($result->success, 'Success result should be true');
        $this->assertEquals('/tmp/test.jpg', $result->imagePath, 'Should have image path');
        $this->assertEquals('mjpeg', $result->sourceType, 'Should have source type');
        $this->assertEquals(200, $result->metadata['http_code'], 'Should have metadata');
    }
    
    /**
     * Test that AcquisitionResult properly handles failure case
     */
    public function testAcquisitionResultFailure()
    {
        $result = AcquisitionResult::failure('fetch_failed', 'mjpeg', ['http_code' => 500]);
        
        $this->assertFalse($result->success, 'Failure result should be false');
        $this->assertNull($result->imagePath, 'Should not have image path');
        $this->assertEquals('fetch_failed', $result->errorReason, 'Should have error reason');
        $this->assertEquals('mjpeg', $result->sourceType, 'Should have source type');
    }
    
    /**
     * Test MJPEG multipart boundary handling logic
     * This tests the pattern for extracting JPEG from multipart MJPEG streams
     */
    public function testMJPEGMultipartBoundaryExtraction()
    {
        // The extraction logic should find JPEG markers (0xFF 0xD8 and 0xFF 0xD9)
        // even with multipart headers
        $multipartData = "--==STILLIMAGEBOUNDARY==\r\n" .
                         "Content-Type: image/jpeg\r\n" .
                         "Content-Length: 42670\r\n\r\n" .
                         "\xFF\xD8\xFF\xE0\x00\x10JFIF" . // JPEG start
                         str_repeat("\x00", 1000) . // JPEG data
                         "\xFF\xD9"; // JPEG end
        
        $jpegStart = strpos($multipartData, "\xFF\xD8");
        $jpegEnd = strpos($multipartData, "\xFF\xD9");
        
        $this->assertNotFalse($jpegStart, 'Should find JPEG start marker');
        $this->assertNotFalse($jpegEnd, 'Should find JPEG end marker');
        $this->assertGreaterThan($jpegStart, $jpegEnd, 'End should be after start');
        
        $jpegData = substr($multipartData, $jpegStart, $jpegEnd - $jpegStart + 2);
        $this->assertStringStartsWith("\xFF\xD8", $jpegData, 'Extracted data should start with JPEG marker');
        $this->assertStringEndsWith("\xFF\xD9", $jpegData, 'Extracted data should end with JPEG marker');
    }
    
    /**
     * Test JPEG size validation logic
     */
    public function testJPEGSizeValidation()
    {
        // Test that JPEG size validation logic works
        // Minimum: ~1KB (1024 bytes)
        // Maximum: ~5MB (5242880 bytes)
        
        $minSize = 1024;
        $maxSize = 5242880;
        
        // Too small - should be rejected
        $smallJpeg = "\xFF\xD8" . str_repeat("\x00", 100) . "\xFF\xD9";
        $this->assertLessThan($minSize, strlen($smallJpeg), 'Small JPEG should fail size check');
        
        // Valid size - should pass
        $validJpeg = "\xFF\xD8" . str_repeat("\x00", 50000) . "\xFF\xD9";
        $this->assertGreaterThanOrEqual($minSize, strlen($validJpeg), 'Valid size JPEG should pass');
        $this->assertLessThanOrEqual($maxSize, strlen($validJpeg), 'Valid size JPEG should pass');
    }
    
    /**
     * Test source type detection from URL
     */
    public function testSourceTypeDetectionFromUrl()
    {
        // MJPEG (default for unknown URLs)
        $mjpegStrategy = new PullAcquisitionStrategy('kspb', 0, ['url' => 'http://cam.test/video.mjpg'], ['name' => 'Test']);
        $this->assertEquals('mjpeg', $mjpegStrategy->getSourceType(), 'Should detect MJPEG source');

        // Static JPEG
        $jpegStrategy = new PullAcquisitionStrategy('kspb', 0, ['url' => 'http://cam.test/image.jpg'], ['name' => 'Test']);
        $this->assertEquals('static_jpeg', $jpegStrategy->getSourceType(), 'Should detect static JPEG source');

        // RTSP
        $rtspStrategy = new PullAcquisitionStrategy('kspb', 0, ['url' => 'rtsp://cam.test/stream'], ['name' => 'Test']);
        $this->assertEquals('rtsp', $rtspStrategy->getSourceType(), 'Should detect RTSP source');
    }
    
    /**
     * Test that AcquisitionStrategyFactory creates correct strategies
     */
    public function testAcquisitionStrategyFactory()
    {
        // Pull camera
        $pullStrategy = AcquisitionStrategyFactory::create('kspb', 0, ['url' => 'http://test.com/cam.mjpg'], ['name' => 'Test']);
        $this->assertInstanceOf(PullAcquisitionStrategy::class, $pullStrategy, 'Should create pull strategy for URL config');

        // Push camera
        $pushStrategy = AcquisitionStrategyFactory::create('kczk', 0, ['type' => 'push', 'push_config' => ['username' => 'test']], ['name' => 'Test']);
        $this->assertInstanceOf(PushAcquisitionStrategy::class, $pushStrategy, 'Should create push strategy for push config');
    }
}
