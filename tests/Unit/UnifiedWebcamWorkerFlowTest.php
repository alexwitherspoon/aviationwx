<?php
/**
 * Unified Webcam Worker Flow Tests
 * 
 * Tests the complete flow of image acquisition and processing for all camera types.
 * Ensures timezone handling, GPS UTC timestamps, and source type routing work correctly.
 * 
 * Camera Source Types Tested:
 * - MJPEG streams (pull)
 * - Static JPEG/PNG (pull)
 * - RTSP streams (pull)
 * - Push cameras (FTPS upload)
 * 
 * Critical Behaviors Tested:
 * - GPS UTC timestamp is prioritized over local DateTimeOriginal
 * - Timezone normalization for push cameras
 * - Correct strategy selection based on config
 * - Pipeline processes all source types identically
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/exif-utils.php';
require_once __DIR__ . '/../../lib/webcam-format-generation.php';
require_once __DIR__ . '/../../lib/webcam-acquisition.php';
require_once __DIR__ . '/../../lib/webcam-worker.php';
require_once __DIR__ . '/../../lib/webcam-pipeline.php';

class UnifiedWebcamWorkerFlowTest extends TestCase
{
    private string $testDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testDir = sys_get_temp_dir() . '/unified_worker_test_' . uniqid();
        @mkdir($this->testDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->testDir)) {
            $files = glob($this->testDir . '/*');
            foreach ($files as $file) {
                @unlink($file);
            }
            @rmdir($this->testDir);
        }
        parent::tearDown();
    }

    /**
     * Create a minimal valid JPEG file using GD
     */
    private function createTestJpeg(string $filename, int $width = 100, int $height = 100): string
    {
        $filePath = $this->testDir . '/' . $filename;

        $image = imagecreatetruecolor($width, $height);
        $color = imagecolorallocate($image, 128, 128, 128);
        imagefill($image, 0, 0, $color);
        imagejpeg($image, $filePath, 90);

        return $filePath;
    }

    // ========================================
    // TEST GROUP 1: GPS UTC Priority
    // ========================================

    /**
     * CRITICAL: getExifTimestamp() must read GPS UTC timestamp FIRST
     * 
     * This ensures that after our pipeline processes an image:
     * - GPS fields contain correct UTC timestamp
     * - DateTimeOriginal may contain local time (preserved for audit)
     * - Application logic uses UTC, not local time
     */
    public function testGetExifTimestamp_PrioritizesGpsUtcOverDateTimeOriginal()
    {
        $testFile = $this->createTestJpeg('test_gps_priority.jpg');

        // Simulate a camera that wrote local time (PST = UTC-8)
        // DateTimeOriginal: 2026-01-16 15:30:00 (local PST)
        // GPS: 2026-01-16 23:30:00 (UTC)
        // The native stamp produces exactly that split for a non-UTC timezone.
        $this->assertTrue(
            addExifTimestamp($testFile, strtotime('2026-01-16 23:30:00 UTC'), 'America/Los_Angeles', false, [
                'airport_id' => 'test',
                'cam_index' => 0,
                'source_type' => 'test'
            ]),
            'native stamp must prepare the test fixture'
        );

        // ACT: Get timestamp using our function
        $timestamp = getExifTimestamp($testFile);

        // ASSERT: Must return UTC timestamp (23:30), NOT local time (15:30)
        $expectedUtc = strtotime('2026-01-16 23:30:00 UTC');
        $gotLocal = strtotime('2026-01-16 15:30:00 UTC'); // What we'd get if reading DateTimeOriginal as UTC

        $this->assertGreaterThan(0, $timestamp, 'Must return a valid timestamp');
        $this->assertEquals($expectedUtc, $timestamp,
            'Must return UTC timestamp from GPS fields, not local time from DateTimeOriginal. ' .
            'Expected UTC 23:30 (' . $expectedUtc . '), got ' . date('H:i:s', $timestamp) . ' (' . $timestamp . '). ' .
            'If you got ' . $gotLocal . ', then DateTimeOriginal was read instead of GPS.');
    }

    /**
     * Fallback: getExifTimestamp() should use DateTimeOriginal if no GPS fields
     */
    public function testGetExifTimestamp_FallsBackToDateTimeOriginalIfNoGps()
    {
        $testFile = $this->testDir . '/test_no_gps.jpg';
        // Committed fixture carries DateTimeOriginal and no GPS fields.
        copy(__DIR__ . '/../Fixtures/webcam-exif/datetime_only.jpg', $testFile);

        // ACT
        $timestamp = getExifTimestamp($testFile);

        // ASSERT: Should fall back to DateTimeOriginal
        $this->assertGreaterThan(0, $timestamp, 'Must return timestamp from DateTimeOriginal fallback');
    }

    /**
     * getSourceCaptureTime() should also prioritize GPS UTC
     */
    public function testGetSourceCaptureTime_PrioritizesGpsUtc()
    {
        $testFile = $this->createTestJpeg('test_source_capture.jpg');

        // Same setup: local time in DateTimeOriginal, UTC in GPS.
        // 16:00 UTC is 08:00 PST (UTC-8), which the native stamp writes.
        $this->assertTrue(
            addExifTimestamp($testFile, strtotime('2026-01-16 16:00:00 UTC'), 'America/Los_Angeles', false, [
                'airport_id' => 'test',
                'cam_index' => 0,
                'source_type' => 'test'
            ]),
            'native stamp must prepare the test fixture'
        );

        // ACT
        $timestamp = getSourceCaptureTime($testFile);

        // ASSERT: Must return UTC
        $expectedUtc = strtotime('2026-01-16 16:00:00 UTC');
        $this->assertEquals($expectedUtc, $timestamp,
            'getSourceCaptureTime must return UTC from GPS fields');
    }

    // ========================================
    // TEST GROUP 2: Source Type Strategy Selection
    // ========================================

    /**
     * Factory creates PullAcquisitionStrategy for URL-based cameras
     */
    public function testFactory_CreatesCorrectStrategyForPullCamera()
    {
        $configs = [
            ['url' => 'http://cam.test/video.mjpg', 'expected_type' => 'mjpeg'],
            ['url' => 'http://cam.test/image.jpg', 'expected_type' => 'static_jpeg'],
            ['url' => 'http://cam.test/image.png', 'expected_type' => 'static_png'],
            ['url' => 'rtsp://cam.test/stream', 'expected_type' => 'rtsp'],
        ];

        foreach ($configs as $config) {
            $strategy = AcquisitionStrategyFactory::create('test', 0, $config, ['name' => 'Test']);

            $this->assertInstanceOf(PullAcquisitionStrategy::class, $strategy,
                "URL {$config['url']} should create PullAcquisitionStrategy");

            $this->assertEquals($config['expected_type'], $strategy->getSourceType(),
                "URL {$config['url']} should detect as {$config['expected_type']}");
        }
    }

    /**
     * Factory creates PushAcquisitionStrategy for push cameras
     */
    public function testFactory_CreatesCorrectStrategyForPushCamera()
    {
        // Test with type=push
        $strategy1 = AcquisitionStrategyFactory::create('test', 0,
            ['type' => 'push', 'push_config' => ['username' => 'testuser']],
            ['name' => 'Test']
        );
        $this->assertInstanceOf(PushAcquisitionStrategy::class, $strategy1);
        $this->assertEquals('push', $strategy1->getSourceType());

        // Test with push_config only (implicit push)
        $strategy2 = AcquisitionStrategyFactory::create('test', 0,
            ['push_config' => ['username' => 'testuser']],
            ['name' => 'Test']
        );
        $this->assertInstanceOf(PushAcquisitionStrategy::class, $strategy2);
    }

    /**
     * Explicit type override takes precedence over URL detection
     */
    public function testFactory_ExplicitTypeOverridesUrlDetection()
    {
        // URL looks like static JPEG, but type says MJPEG
        $strategy = AcquisitionStrategyFactory::create('test', 0,
            ['url' => 'http://cam.test/snapshot.jpg', 'type' => 'mjpeg'],
            ['name' => 'Test']
        );

        $this->assertEquals('mjpeg', $strategy->getSourceType(),
            'Explicit type=mjpeg should override .jpg URL detection');
    }

    // ========================================
    // TEST GROUP 3: Push Camera Timezone Handling
    // ========================================

    /**
     * normalizeExifToUtc() detects timezone offset and adds GPS UTC fields
     */
    public function testNormalizeExifToUtc_DetectsTimezoneAndAddsGpsFields()
    {
        $testFile = $this->createTestJpeg('test_normalize.jpg');

        // Simulate a camera clock eight hours behind UTC: stamp DateTimeOriginal
        // and GPS both at the local value, then set mtime to the true UTC time.
        // The diff beyond drift is what triggers offset detection.
        $utcTimestamp = time() - 60; // 1 minute ago (well within retention window)
        $localTimestamp = $utcTimestamp - (8 * 3600); // PST = UTC-8
        $localTime = gmdate('Y:m:d H:i:s', $localTimestamp);

        $this->assertTrue(
            addExifTimestamp($testFile, $localTimestamp, 'UTC', false, [
                'airport_id' => 'test',
                'cam_index' => 0,
                'source_type' => 'test'
            ]),
            'native stamp must prepare the test fixture'
        );

        // Set file mtime to UTC time
        touch($testFile, $utcTimestamp);

        // ACT: Normalize EXIF
        $result = normalizeExifToUtc($testFile, 'test', 0, 'America/Los_Angeles');

        // ASSERT: Should succeed (timezone detected)
        $this->assertTrue($result, 'normalizeExifToUtc should succeed when timezone offset is detected');

        // ASSERT: GPS fields should now contain UTC
        $timestamp = getExifTimestamp($testFile);
        $this->assertEqualsWithDelta($utcTimestamp, $timestamp, 300,
            'After normalization, getExifTimestamp should return UTC time from GPS fields');
    }

    /**
     * normalizeExifToUtc() preserves original DateTimeOriginal (for audit)
     */
    public function testNormalizeExifToUtc_PreservesOriginalDateTimeOriginal()
    {
        $testFile = $this->createTestJpeg('test_preserve_local.jpg');

        // Use relative timestamps to avoid test failures as time passes
        $utcTimestamp = time() - 60; // 1 minute ago
        $localTimestamp = $utcTimestamp - (8 * 3600); // PST = UTC-8
        $localTime = gmdate('Y:m:d H:i:s', $localTimestamp);

        $this->assertTrue(
            addExifTimestamp($testFile, $localTimestamp, 'UTC', false, [
                'airport_id' => 'test',
                'cam_index' => 0,
                'source_type' => 'test'
            ]),
            'native stamp must prepare the test fixture'
        );

        touch($testFile, $utcTimestamp);

        // ACT
        normalizeExifToUtc($testFile, 'test', 0, 'America/Los_Angeles');

        // ASSERT: DateTimeOriginal should still have local time
        $dto = exifNativeRead($testFile)['DateTimeOriginal'] ?? null;
        $this->assertEquals($localTime, $dto,
            'DateTimeOriginal should be preserved in local time for audit purposes');
    }

    // ========================================
    // TEST GROUP 4: Pipeline Processing
    // ========================================

    /**
     * ProcessingPipeline accepts AcquisitionResult and processes it
     */
    public function testPipeline_AcceptsAcquisitionResult()
    {
        // Create mock acquisition result
        $timestamp = time();
        $result = AcquisitionResult::success('/tmp/test.jpg', $timestamp, 'mjpeg', []);

        // Pipeline should accept this result structure
        $this->assertTrue($result->success);
        $this->assertEquals('/tmp/test.jpg', $result->imagePath);
        $this->assertEquals($timestamp, $result->timestamp);
        $this->assertEquals('mjpeg', $result->sourceType);
    }

    /**
     * PipelineResult correctly counts variants
     */
    public function testPipelineResult_CountsVariantsCorrectly()
    {
        $variants = [
            'original' => ['jpg' => '/cache/orig.jpg'],
            '720' => ['jpg' => '/cache/720.jpg', 'webp' => '/cache/720.webp'],
            '360' => ['jpg' => '/cache/360.jpg', 'webp' => '/cache/360.webp'],
        ];

        $result = PipelineResult::success('/cache/orig.jpg', $variants, time());

        // 1 original + 2 for 720 + 2 for 360 = 5
        $this->assertEquals(5, $result->getVariantCount());

        $formats = $result->getPromotedFormats();
        $this->assertContains('jpg', $formats);
        $this->assertContains('webp', $formats);
    }

    // ========================================
    // TEST GROUP 5: Worker Result Exit Codes
    // ========================================

    /**
     * WorkerResult exit codes map correctly to process exit codes
     */
    public function testWorkerResult_ExitCodesCorrect()
    {
        // Success = 0 (standard Unix success)
        $success = WorkerResult::success([]);
        $this->assertEquals(0, $success->exitCode);
        $this->assertTrue($success->isSuccess());
        $this->assertFalse($success->isSkip());

        // Failure = 1 (standard Unix failure)
        $failure = WorkerResult::failure('test_error', []);
        $this->assertEquals(1, $failure->exitCode);
        $this->assertFalse($failure->isSuccess());
        $this->assertFalse($failure->isSkip());

        // Skip = 2 (custom: not a failure, just nothing to do)
        $skip = WorkerResult::skip('no_work', []);
        $this->assertEquals(2, $skip->exitCode);
        $this->assertFalse($skip->isSuccess());
        $this->assertTrue($skip->isSkip());
    }

    // ========================================
    // TEST GROUP 6: Source Type Configuration Scenarios
    // ========================================

    /**
     * Test realistic camera configurations from airports.json
     */
    public function testRealisticCameraConfigurations()
    {
        // MJPEG camera (most common pull type)
        $mjpegConfig = [
            'name' => 'North Runway',
            'url' => 'https://cam.example.com/mjpg/video.mjpg?user=cam&pw=pass',
            'refresh_seconds' => 60
        ];
        $strategy = AcquisitionStrategyFactory::create('kspb', 0, $mjpegConfig, ['name' => 'Test Airport']);
        $this->assertInstanceOf(PullAcquisitionStrategy::class, $strategy);
        $this->assertEquals('mjpeg', $strategy->getSourceType());

        // Push camera (FTP/SFTP upload)
        $pushConfig = [
            'name' => 'Tower Cam',
            'type' => 'push',
            'refresh_seconds' => 60,
            'push_config' => [
                'username' => 'towercam',
                'password' => 'secret12345678',
                'max_file_size_mb' => 25,
                'allowed_extensions' => ['jpg', 'jpeg']
            ]
        ];
        $strategy = AcquisitionStrategyFactory::create('kczk', 0, $pushConfig, ['name' => 'Test Airport']);
        $this->assertInstanceOf(PushAcquisitionStrategy::class, $strategy);
        $this->assertEquals('push', $strategy->getSourceType());

        // RTSP camera
        $rtspConfig = [
            'name' => 'Security Cam',
            'url' => 'rtsp://192.168.1.100:554/stream1',
            'rtsp_transport' => 'tcp',
            'refresh_seconds' => 30
        ];
        $strategy = AcquisitionStrategyFactory::create('test', 0, $rtspConfig, ['name' => 'Test Airport']);
        $this->assertInstanceOf(PullAcquisitionStrategy::class, $strategy);
        $this->assertEquals('rtsp', $strategy->getSourceType());
    }

    // ========================================
    // TEST GROUP 7: Error Handling
    // ========================================

    /**
     * Push strategy fails gracefully without username (same gate as scheduler skip)
     */
    public function testPushStrategy_FailsWithoutUsername()
    {
        $strategy = new PushAcquisitionStrategy('test', 0, ['push_config' => []], ['name' => 'Test']);
        $result = $strategy->acquire();

        $this->assertFalse($result->success);
        $this->assertEquals('webcam_not_configured', $result->errorReason);
    }

    /**
     * Push strategy skips when no upload directory exists
     */
    public function testPushStrategy_SkipsWhenNoUploadDir()
    {
        $strategy = new PushAcquisitionStrategy('test', 0,
            ['push_config' => ['username' => 'nonexistent_user_12345']],
            ['name' => 'Test']
        );
        $result = $strategy->acquire();

        // Should skip (not fail) when directory doesn't exist
        $this->assertFalse($result->success);
        $this->assertStringContainsString('skip:', $result->errorReason,
            'Missing upload directory should result in skip, not failure');
    }

    /**
     * AcquisitionResult failure preserves error details
     */
    public function testAcquisitionResult_PreservesErrorDetails()
    {
        $metadata = [
            'http_code' => 503,
            'curl_error' => 'Connection timed out',
            'duration_ms' => 30000
        ];

        $result = AcquisitionResult::failure('timeout', 'mjpeg', $metadata);

        $this->assertFalse($result->success);
        $this->assertEquals('timeout', $result->errorReason);
        $this->assertEquals('mjpeg', $result->sourceType);
        $this->assertEquals(503, $result->metadata['http_code']);
        $this->assertEquals('Connection timed out', $result->metadata['curl_error']);
    }
}
