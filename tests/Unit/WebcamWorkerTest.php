<?php
/**
 * Unit Tests for WebcamWorker and AcquisitionStrategy
 * Tests unified webcam processing for both push and pull cameras
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/webcam-acquisition.php';
require_once __DIR__ . '/../../lib/webcam-worker.php';
require_once __DIR__ . '/../../lib/webcam-pipeline.php';
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../../lib/cache-paths.php';

class WebcamWorkerTest extends TestCase
{
    /**
     * Test AcquisitionResult static factory methods
     */
    public function testAcquisitionResultFactories()
    {
        // Test success factory
        $success = AcquisitionResult::success('/tmp/test.jpg', time(), 'mjpeg', ['http_code' => 200]);
        $this->assertTrue($success->success, 'Success result should have success=true');
        $this->assertEquals('/tmp/test.jpg', $success->imagePath, 'Should have correct image path');
        $this->assertEquals('mjpeg', $success->sourceType, 'Should have correct source type');
        $this->assertNull($success->errorReason, 'Success should not have error reason');
        $this->assertEquals(200, $success->metadata['http_code'], 'Should have metadata');

        // Test failure factory
        $failure = AcquisitionResult::failure('timeout', 'rtsp', ['ffmpeg_exit_code' => 124]);
        $this->assertFalse($failure->success, 'Failure result should have success=false');
        $this->assertNull($failure->imagePath, 'Failure should not have image path');
        $this->assertEquals('timeout', $failure->errorReason, 'Should have error reason');
        $this->assertEquals('rtsp', $failure->sourceType, 'Should have source type');

        // Test skip factory
        $skip = AcquisitionResult::skip('fresh_cache', 'pull', ['age' => 30]);
        $this->assertFalse($skip->success, 'Skip result should have success=false');
        $this->assertEquals('skip:fresh_cache', $skip->errorReason, 'Should have skip prefix in error reason');
    }

    /**
     * Test PipelineResult static factory methods
     */
    public function testPipelineResultFactories()
    {
        // Test success factory
        $variants = ['original' => ['jpg' => '/cache/test_original.jpg']];
        $success = PipelineResult::success('/cache/test.jpg', $variants, time(), ['source' => 'mjpeg']);
        $this->assertTrue($success->success, 'Success result should have success=true');
        $this->assertNotNull($success->originalPath, 'Should have original path');
        $this->assertNull($success->errorReason, 'Success should not have error reason');
        $this->assertArrayHasKey('original', $success->variants, 'Should have variants');

        // Test failure factory
        $failure = PipelineResult::failure('error_frame', ['confidence' => 0.95]);
        $this->assertFalse($failure->success, 'Failure result should have success=false');
        $this->assertNull($failure->originalPath, 'Failure should not have original path');
        $this->assertEquals('error_frame', $failure->errorReason, 'Should have error reason');
    }

    /**
     * Test WorkerResult static factory methods
     */
    public function testWorkerResultFactories()
    {
        // Test success factory
        $success = WorkerResult::success(['source_type' => 'mjpeg']);
        $this->assertTrue($success->isSuccess(), 'Success result should return true from isSuccess()');
        $this->assertEquals(WorkerResult::SUCCESS, $success->exitCode, 'Should have SUCCESS exit code');
        $this->assertNull($success->reason, 'Success should not have reason');

        // Test failure factory
        $failure = WorkerResult::failure('acquisition_failed', ['http_code' => 500]);
        $this->assertFalse($failure->isSuccess(), 'Failure result should return false from isSuccess()');
        $this->assertEquals(WorkerResult::FAILURE, $failure->exitCode, 'Should have FAILURE exit code');
        $this->assertEquals('acquisition_failed', $failure->reason, 'Should have reason');

        // Test skip factory
        $skipped = WorkerResult::skip('circuit_breaker_open', ['failures' => 5]);
        $this->assertFalse($skipped->isSuccess(), 'Skipped result should return false from isSuccess()');
        $this->assertTrue($skipped->isSkip(), 'Skipped result should return true from isSkip()');
        $this->assertEquals(WorkerResult::SKIP, $skipped->exitCode, 'Should have SKIP exit code');
        $this->assertEquals('circuit_breaker_open', $skipped->reason, 'Should have skip reason');
    }

    /**
     * Test PipelineResult helper methods
     */
    public function testPipelineResultHelperMethods()
    {
        $variants = [
            'original' => ['jpg' => '/cache/original.jpg', 'webp' => '/cache/original.webp'],
            '720' => ['jpg' => '/cache/720.jpg', 'webp' => '/cache/720.webp'],
            '360' => ['jpg' => '/cache/360.jpg']
        ];
        
        $result = PipelineResult::success('/cache/original.jpg', $variants, time());
        
        // Test getVariantCount
        $this->assertEquals(5, $result->getVariantCount(), 'Should count all variants');
        
        // Test getPromotedFormats
        $formats = $result->getPromotedFormats();
        $this->assertContains('jpg', $formats, 'Should include jpg format');
        $this->assertContains('webp', $formats, 'Should include webp format');
    }

    /**
     * Test AcquisitionStrategy interface implementations
     */
    public function testAcquisitionStrategyInterface()
    {
        // PullAcquisitionStrategy should implement AcquisitionStrategy
        $pullStrategy = new PullAcquisitionStrategy('kspb', 0, ['url' => 'http://test.com/cam.mjpg'], ['name' => 'Test']);
        $this->assertInstanceOf(AcquisitionStrategy::class, $pullStrategy, 'PullAcquisitionStrategy should implement AcquisitionStrategy');
        // Pull strategy returns detected source type (mjpeg, rtsp, static, etc.) not just "pull"
        $this->assertEquals('mjpeg', $pullStrategy->getSourceType(), 'Pull strategy should detect source type from URL');

        // PushAcquisitionStrategy should implement AcquisitionStrategy
        $pushStrategy = new PushAcquisitionStrategy('kczk', 0, ['push_config' => ['username' => 'test']], ['name' => 'Test']);
        $this->assertInstanceOf(AcquisitionStrategy::class, $pushStrategy, 'PushAcquisitionStrategy should implement AcquisitionStrategy');
        $this->assertEquals('push', $pushStrategy->getSourceType(), 'Push strategy source type should be "push"');
    }

    /**
     * Test PullAcquisitionStrategy detects source types correctly
     */
    public function testPullStrategySourceTypeDetection()
    {
        // MJPEG (default)
        $mjpegStrategy = new PullAcquisitionStrategy('kspb', 0, ['url' => 'http://cam.test/video.mjpg'], ['name' => 'Test']);
        $this->assertEquals('mjpeg', $mjpegStrategy->getSourceType(), 'Should detect MJPEG source');

        // Static JPEG
        $jpegStrategy = new PullAcquisitionStrategy('kspb', 0, ['url' => 'http://cam.test/image.jpg'], ['name' => 'Test']);
        $this->assertEquals('static_jpeg', $jpegStrategy->getSourceType(), 'Should detect static JPEG source');

        // RTSP
        $rtspStrategy = new PullAcquisitionStrategy('kspb', 0, ['url' => 'rtsp://cam.test/stream'], ['name' => 'Test']);
        $this->assertEquals('rtsp', $rtspStrategy->getSourceType(), 'Should detect RTSP source');

        // Explicit type overrides URL detection
        $explicitStrategy = new PullAcquisitionStrategy('kspb', 0, ['url' => 'http://cam.test/cam.jpg', 'type' => 'mjpeg'], ['name' => 'Test']);
        $this->assertEquals('mjpeg', $explicitStrategy->getSourceType(), 'Explicit type should override URL detection');
    }

    /**
     * Test PushAcquisitionStrategy returns failure for missing username
     */
    public function testPushStrategyFailsWithoutUsername()
    {
        $strategy = new PushAcquisitionStrategy('kspb', 0, ['push_config' => []], ['name' => 'Test Airport']);
        $result = $strategy->acquire();

        $this->assertFalse($result->success, 'Should fail without username');
        $this->assertEquals('no_username_configured', $result->errorReason, 'Error reason should indicate missing username');
    }

    /**
     * Test WorkerResult exit codes are correct
     */
    public function testWorkerResultExitCodes()
    {
        $this->assertEquals(0, WorkerResult::SUCCESS, 'SUCCESS should be 0');
        $this->assertEquals(1, WorkerResult::FAILURE, 'FAILURE should be 1');
        $this->assertEquals(2, WorkerResult::SKIP, 'SKIP should be 2');
    }

    /**
     * Test AcquisitionResult properly stores all fields
     */
    public function testAcquisitionResultFields()
    {
        $timestamp = time();
        $metadata = [
            'http_code' => 200,
            'fetch_duration_ms' => 145.5,
            'content_length' => 102400
        ];
        
        $result = AcquisitionResult::success(
            '/tmp/test.jpg',
            $timestamp,
            'static_jpeg',
            $metadata
        );
        
        $this->assertTrue($result->success, 'Success should be true');
        $this->assertEquals('/tmp/test.jpg', $result->imagePath, 'Image path should be stored');
        $this->assertEquals($timestamp, $result->timestamp, 'Timestamp should be stored');
        $this->assertEquals('static_jpeg', $result->sourceType, 'Source type should be stored');
        $this->assertNull($result->errorReason, 'Error reason should be null on success');
        $this->assertEquals(200, $result->metadata['http_code'], 'Metadata should be stored');
    }

    /**
     * Test PipelineResult failure includes error details
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
     * Test AcquisitionStrategyFactory creates correct strategy types
     */
    public function testAcquisitionStrategyFactory()
    {
        // Pull camera (has URL, no push_config)
        $pullStrategy = AcquisitionStrategyFactory::create('kspb', 0, ['url' => 'http://test.com/cam.mjpg'], ['name' => 'Test']);
        $this->assertInstanceOf(PullAcquisitionStrategy::class, $pullStrategy, 'Should create PullAcquisitionStrategy for pull config');

        // Push camera (has type=push)
        $pushStrategy1 = AcquisitionStrategyFactory::create('kczk', 0, ['type' => 'push', 'push_config' => ['username' => 'test']], ['name' => 'Test']);
        $this->assertInstanceOf(PushAcquisitionStrategy::class, $pushStrategy1, 'Should create PushAcquisitionStrategy when type=push');

        // Push camera (has push_config without type)
        $pushStrategy2 = AcquisitionStrategyFactory::create('kczk', 1, ['push_config' => ['username' => 'test']], ['name' => 'Test']);
        $this->assertInstanceOf(PushAcquisitionStrategy::class, $pushStrategy2, 'Should create PushAcquisitionStrategy when push_config present');
    }

    /**
     * Test shouldSkip returns correct structure
     */
    public function testShouldSkipStructure()
    {
        $strategy = new PullAcquisitionStrategy('kspb', 0, ['url' => 'http://test.com/cam.mjpg'], ['name' => 'Test']);
        $result = $strategy->shouldSkip();

        $this->assertIsArray($result, 'shouldSkip should return array');
        $this->assertArrayHasKey('skip', $result, 'Should have skip key');
        $this->assertArrayHasKey('reason', $result, 'Should have reason key');
        $this->assertIsBool($result['skip'], 'skip should be boolean');
    }
}
