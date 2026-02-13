<?php
/**
 * Unit Tests for ProcessingPipeline
 * Tests image validation, variant generation, and atomic promotion
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/webcam-acquisition.php';
require_once __DIR__ . '/../../lib/webcam-pipeline.php';
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../../lib/cache-paths.php';

class WebcamPipelineTest extends TestCase
{
    private string $testImagePath;
    private string $testDir;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create a test directory
        $this->testDir = sys_get_temp_dir() . '/aviationwx_pipeline_test_' . uniqid();
        @mkdir($this->testDir, 0755, true);
        
        // Create a minimal valid JPEG image (1x1 red pixel)
        $this->testImagePath = $this->testDir . '/test_image.jpg';
        $this->createMinimalJpeg($this->testImagePath);
    }

    protected function tearDown(): void
    {
        // Cleanup test files
        if (file_exists($this->testImagePath)) {
            @unlink($this->testImagePath);
        }
        if (is_dir($this->testDir)) {
            // Clean up any files in the directory
            $files = glob($this->testDir . '/*');
            foreach ($files as $file) {
                @unlink($file);
            }
            @rmdir($this->testDir);
        }
        parent::tearDown();
    }

    /**
     * Create a minimal valid JPEG file for testing
     */
    private function createMinimalJpeg(string $path): void
    {
        // Create a small 10x10 image using GD
        if (function_exists('imagecreatetruecolor')) {
            $img = imagecreatetruecolor(10, 10);
            $red = imagecolorallocate($img, 255, 128, 64); // Not uniform color
            $blue = imagecolorallocate($img, 64, 128, 255);
            
            // Create a simple pattern to avoid uniform color detection
            for ($y = 0; $y < 10; $y++) {
                for ($x = 0; $x < 10; $x++) {
                    imagesetpixel($img, $x, $y, ($x + $y) % 2 ? $red : $blue);
                }
            }
            
            imagejpeg($img, $path, 85);
        } else {
            // Fallback: write minimal JPEG header
            file_put_contents($path, "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9");
        }
    }

    /**
     * Test pipeline fails when file doesn't exist
     */
    public function testPipelineFailsWhenStagingFileMissing()
    {
        // Create a pipeline factory instance
        $pipeline = ProcessingPipelineFactory::create('kspb', 0, [], ['name' => 'Test Airport']);
        
        // Process with a non-existent file
        $result = $pipeline->process('/nonexistent/path.jpg', time(), 'mjpeg');
        
        $this->assertFalse($result->success, 'Pipeline should fail when staging file is missing');
    }

    /**
     * Test PipelineResult stores metadata correctly
     */
    public function testPipelineResultMetadata()
    {
        $metadata = [
            'source_type' => 'mjpeg',
            'processing_time_ms' => 145.5
        ];
        $variants = ['original' => ['jpg' => '/cache/test.jpg']];
        
        $result = PipelineResult::success('/cache/test.jpg', $variants, time(), $metadata);
        
        $this->assertEquals('mjpeg', $result->metadata['source_type'], 'Metadata should include source_type');
        $this->assertEquals(145.5, $result->metadata['processing_time_ms'], 'Metadata should include processing time');
    }

    /**
     * Test ProcessingPipelineFactory creates correctly
     */
    public function testPipelineFactoryCreation()
    {
        $airportConfig = ['name' => 'Test Airport', 'timezone' => 'America/Los_Angeles'];
        $camConfig = ['url' => 'http://example.com/cam.mjpg', 'refresh_seconds' => 60];
        
        $pipeline = ProcessingPipelineFactory::create('kspb', 0, $camConfig, $airportConfig);
        
        // If we get here without exceptions, the factory works
        $this->assertInstanceOf(ProcessingPipeline::class, $pipeline, 'Factory should create ProcessingPipeline');
    }

    /**
     * Test that PipelineResult failure includes error details
     */
    public function testPipelineResultFailureDetails()
    {
        $metadata = [
            'confidence' => 0.95,
            'reasons' => ['blue_iris_error_detected', 'uniform_color']
        ];
        
        $result = PipelineResult::failure('error_frame', $metadata);
        
        $this->assertFalse($result->success, 'Should be a failure result');
        $this->assertEquals('error_frame', $result->errorReason, 'Should have correct error reason');
        $this->assertEquals(0.95, $result->metadata['confidence'], 'Should have confidence in metadata');
        $this->assertContains('blue_iris_error_detected', $result->metadata['reasons'], 'Should have reasons in metadata');
    }

    /**
     * Test PipelineResult success fields
     */
    public function testPipelineResultSuccessFields()
    {
        $timestamp = time();
        $variants = [
            'original' => ['jpg' => '/cache/original.jpg', 'webp' => '/cache/original.webp'],
            '720' => ['jpg' => '/cache/720.jpg']
        ];
        $metadata = ['source' => 'mjpeg'];
        
        $result = PipelineResult::success('/cache/original.jpg', $variants, $timestamp, $metadata);
        
        $this->assertTrue($result->success, 'Success should be true');
        $this->assertEquals('/cache/original.jpg', $result->originalPath, 'Original path should be stored');
        $this->assertEquals($timestamp, $result->timestamp, 'Timestamp should be stored');
        $this->assertNull($result->errorReason, 'Error reason should be null on success');
        $this->assertEquals($variants, $result->variants, 'Variants should be stored');
        $this->assertEquals('mjpeg', $result->metadata['source'], 'Metadata should be stored');
    }

    /**
     * Test PipelineResult getVariantCount
     */
    public function testPipelineResultVariantCount()
    {
        $variants = [
            'original' => ['jpg' => '/a.jpg', 'webp' => '/a.webp'],
            '720' => ['jpg' => '/b.jpg', 'webp' => '/b.webp'],
            '360' => ['jpg' => '/c.jpg']
        ];
        
        $result = PipelineResult::success('/cache/test.jpg', $variants, time());
        
        $this->assertEquals(5, $result->getVariantCount(), 'Should count all variant files');
    }

    /**
     * Test PipelineResult getPromotedFormats
     */
    public function testPipelineResultPromotedFormats()
    {
        $variants = [
            'original' => ['jpg' => '/a.jpg', 'webp' => '/a.webp'],
            '720' => ['jpg' => '/b.jpg']
        ];
        
        $result = PipelineResult::success('/cache/test.jpg', $variants, time());
        $formats = $result->getPromotedFormats();
        
        $this->assertContains('jpg', $formats, 'Should include jpg');
        $this->assertContains('webp', $formats, 'Should include webp');
        $this->assertCount(2, $formats, 'Should have 2 unique formats');
    }

    /**
     * Test PipelineResult failure has empty variants
     */
    public function testPipelineResultFailureEmptyVariants()
    {
        $result = PipelineResult::failure('test_error');
        
        $this->assertEmpty($result->variants, 'Failure result should have empty variants');
        $this->assertEquals(0, $result->getVariantCount(), 'Failure should have 0 variants');
        $this->assertEmpty($result->getPromotedFormats(), 'Failure should have no promoted formats');
    }
}
