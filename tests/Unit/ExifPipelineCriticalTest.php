<?php
/**
 * Critical EXIF Pipeline Tests
 * 
 * Tests that catch critical bugs in the EXIF processing pipeline:
 * - EXIF must be added before processing continues
 * - GPS timestamp must be written correctly
 * - EXIF must be written without external tools
 * - All attribution fields must be present
 * 
 * These tests should fail FAST if critical pipeline issues are introduced.
 */

namespace AviationWX\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/exif-utils.php';

class ExifPipelineCriticalTest extends TestCase
{
    private $testDir;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->testDir = sys_get_temp_dir() . '/exif_pipeline_test_' . uniqid();
        @mkdir($this->testDir, 0755, true);
    }
    
    protected function tearDown(): void
    {
        // Clean up test files
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
    private function createTestJpeg(string $filename): string
    {
        $filePath = $this->testDir . '/' . $filename;
        
        // Create a simple 10x10 gray image using GD
        $image = imagecreatetruecolor(10, 10);
        $gray = imagecolorallocate($image, 128, 128, 128);
        imagefill($image, 0, 0, $gray);
        imagejpeg($image, $filePath, 90);
        
        return $filePath;
    }
    
    // ========================================
    // CRITICAL TEST 1: addExifTimestamp() Must Actually Work
    // ========================================
    
    /**
     * CRITICAL: addExifTimestamp() must successfully add EXIF to images
     * 
     * This test catches:
     * - Missing EXIF block after a native stamp
     * - Broken write path
     * - File permissions issues
     */
    public function testAddExifTimestamp_WithValidImage_SucceedsAndAddsExif()
    {
        $testFile = $this->createTestJpeg('test_add_exif.jpg');
        $timestamp = time();
        
        // ACT: Add EXIF timestamp
        $result = addExifTimestamp($testFile, $timestamp);
        
        // ASSERT: Must succeed
        $this->assertTrue($result, 'addExifTimestamp() must return true');
        
        // ASSERT: File must still exist
        $this->assertFileExists($testFile, 'Image file must still exist after EXIF addition');
        
        // ASSERT: EXIF must actually be present
        $exifTimestamp = getExifTimestamp($testFile);
        $this->assertGreaterThan(0, $exifTimestamp, 'EXIF DateTimeOriginal must be present after addExifTimestamp()');
        
        // ASSERT: EXIF timestamp must match what we set (within 1 second)
        $this->assertEqualsWithDelta($timestamp, $exifTimestamp, 1, 
            'EXIF timestamp must match the timestamp we provided');
    }
    
    // ========================================
    // CRITICAL TEST 2: GPS Timestamp Must Be Written
    // ========================================
    
    /**
     * CRITICAL: GPS timestamp must be written in correct format
     * 
     * This test catches:
     * - Wrong GPS timestamp format (rational vs HH:MM:SS)
     * - Missing GPS timestamp
     */
    public function testAddExifTimestamp_WithValidImage_AddsGpsTimestamp()
    {
        $testFile = $this->createTestJpeg('test_gps_timestamp.jpg');
        $timestamp = strtotime('2026-01-13 05:20:03 UTC');
        
        // ACT: Add EXIF timestamp
        $result = addExifTimestamp($testFile, $timestamp);
        $this->assertTrue($result, 'addExifTimestamp() must succeed');
        
        // ASSERT: GPS TimeStamp must be present and UTC
        $exif = exifNativeRead($testFile);
        $this->assertSame('ok', $exif['status'] ?? null);
        $components = $exif['GpsTimeComponents'] ?? null;
        $this->assertNotEmpty($components, 'GPS TimeStamp must be present in EXIF');

        $gpsTimeStamp = sprintf('%02d:%02d:%02d', $components[0], $components[1], $components[2]);
        
        // ASSERT: GPS timestamp must be in HH:MM:SS format
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}:\d{2}$/', $gpsTimeStamp,
            'GPS TimeStamp must be in HH:MM:SS format, not rational format');
        
        // ASSERT: GPS timestamp must match the expected time (UTC)
        $expectedGpsTime = gmdate('H:i:s', $timestamp);
        $this->assertEquals($expectedGpsTime, $gpsTimeStamp,
            'GPS TimeStamp must match the UTC time we provided');
    }
    
    /**
     * CRITICAL: GPS date stamp must be written
     */
    public function testAddExifTimestamp_WithValidImage_AddsGpsDateStamp()
    {
        $testFile = $this->createTestJpeg('test_gps_date.jpg');
        $timestamp = strtotime('2026-01-13 05:20:03 UTC');
        
        // ACT
        $result = addExifTimestamp($testFile, $timestamp);
        $this->assertTrue($result);
        
        // ASSERT: GPS DateStamp must be present
        $exif = exifNativeRead($testFile);
        $this->assertSame('ok', $exif['status'] ?? null);
        $gpsDateStamp = $exif['GpsDateStamp'] ?? null;
        $this->assertNotEmpty($gpsDateStamp);
        $expectedGpsDate = gmdate('Y:m:d', $timestamp);
        $this->assertEquals($expectedGpsDate, $gpsDateStamp,
            'GPS DateStamp must match the UTC date we provided');
    }
    
    // ========================================
    // CRITICAL TEST 3: Attribution Fields Must Be Present
    // ========================================
    
    /**
     * CRITICAL: All attribution fields must be written by addExifTimestamp()
     */
    public function testAddExifTimestamp_WithValidImage_AddsAllAttributionFields()
    {
        $testFile = $this->createTestJpeg('test_attribution.jpg');
        $timestamp = time();
        
        // ACT
        $result = addExifTimestamp($testFile, $timestamp);
        $this->assertTrue($result);
        
        // ASSERT: EXIF-resident attribution fields. XMP Rights is deliberately
        // dropped under the EXIF-only policy, so it is not asserted.
        $exif = exifNativeRead($testFile);
        $this->assertSame('ok', $exif['status'] ?? null);
        $this->assertSame('Aviation weather webcam image processed by AviationWX.org', $exif['ImageDescription'] ?? null);
        $this->assertSame('AviationWX.org', $exif['Artist'] ?? null);
        $this->assertSame('© AviationWX.org and image contributors', $exif['Copyright'] ?? null);
        $this->assertSame('Processed by AviationWX.org', $exif['UserComment'] ?? null);
    }
    
    // ========================================
    // CRITICAL TEST 4: ensureImageHasExif() Must Be Called
    // ========================================
    
    /**
     * CRITICAL: ensureImageHasExif() function must exist and work
     * 
     * This test catches:
     * - Wrong function name (ensureExifTimestamp vs ensureImageHasExif)
     * - Function signature changes
     * - Refactoring that breaks the function
     */
    public function testEnsureImageHasExif_FunctionExists()
    {
        $this->assertTrue(function_exists('ensureImageHasExif'),
            'ensureImageHasExif() function must exist - check for typos like ensureExifTimestamp()');
    }
    
    /**
     * CRITICAL: ensureImageHasExif() must add EXIF if missing
     */
    public function testEnsureImageHasExif_WithImageWithoutExif_AddsExif()
    {
        $testFile = $this->createTestJpeg('test_ensure_exif.jpg');
        
        // ASSERT: Initially has no EXIF
        $this->assertEquals(0, getExifTimestamp($testFile), 'Test file must start without EXIF');
        
        // ACT: Ensure EXIF exists
        $result = ensureImageHasExif($testFile);
        
        // ASSERT: Must succeed
        $this->assertTrue($result, 'ensureImageHasExif() must return true');
        
        // ASSERT: EXIF must now be present
        $exifTimestamp = getExifTimestamp($testFile);
        $this->assertGreaterThan(0, $exifTimestamp, 
            'ensureImageHasExif() must add EXIF if it was missing');
    }
    
    /**
     * CRITICAL: ensureImageHasExif() must preserve existing EXIF
     */
    public function testEnsureImageHasExif_WithImageWithExif_PreservesExistingExif()
    {
        $testFile = $this->createTestJpeg('test_preserve_exif.jpg');
        $originalTimestamp = strtotime('2026-01-13 10:00:00 UTC');
        
        // Add EXIF first
        addExifTimestamp($testFile, $originalTimestamp);
        $firstExifTimestamp = getExifTimestamp($testFile);
        
        // Wait a moment to ensure timestamps would differ if overwritten
        sleep(1);
        
        // ACT: Call ensureImageHasExif (should preserve existing EXIF)
        $result = ensureImageHasExif($testFile);
        $this->assertTrue($result);
        
        // ASSERT: EXIF timestamp must be unchanged
        $secondExifTimestamp = getExifTimestamp($testFile);
        $this->assertEquals($firstExifTimestamp, $secondExifTimestamp,
            'ensureImageHasExif() must preserve existing EXIF DateTimeOriginal');
    }

    /**
     * An explicit capture timestamp must win over file mtime, so the pull path's
     * returned fetch time and the EXIF it writes agree exactly.
     */
    public function testEnsureImageHasExif_PrefersProvidedTimestampOverMtime()
    {
        $testFile = $this->createTestJpeg('test_prefer_provided_ts.jpg');
        // Age the file mtime so it clearly disagrees with the provided timestamp.
        touch($testFile, time() - 3600);

        $captureTime = time();
        $result = ensureImageHasExif($testFile, $captureTime);

        $this->assertTrue($result, 'ensureImageHasExif() must return true');
        $this->assertSame(
            $captureTime,
            getExifTimestamp($testFile),
            'EXIF capture time must be the provided timestamp, not file mtime'
        );
    }

    /**
     * A live pull's fetch time must overwrite an embedded camera-clock
     * DateTimeOriginal so the stored and embedded times agree. The camera clock
     * is not trusted, so its embedded value must not be preserved.
     */
    public function testEnsureImageHasExif_ProvidedFetchTimeOverwritesEmbeddedExif()
    {
        $testFile = $this->createTestJpeg('test_overwrite_embedded.jpg');
        $cameraTime = strtotime('2026-01-13 10:00:00 UTC');
        addExifTimestamp($testFile, $cameraTime);
        $this->assertSame(
            $cameraTime,
            getDateTimeOriginalTimestamp($testFile),
            'precondition: the frame carries an embedded DateTimeOriginal'
        );

        $fetchTime = time();
        $result = ensureImageHasExif($testFile, $fetchTime);

        $this->assertTrue($result, 'ensureImageHasExif() must return true');
        $this->assertSame(
            $fetchTime,
            getDateTimeOriginalTimestamp($testFile),
            'a provided fetch time must overwrite the embedded DateTimeOriginal so stored and embedded agree'
        );
        $this->assertSame(
            $fetchTime,
            getExifTimestamp($testFile),
            'GPS UTC must be rewritten to the fetch time too'
        );
    }

    /**
     * A complete capture-time payload carries both the UTC GPS time and the
     * local DateTimeOriginal. Federated sources are trusted only when both are
     * present; a partial payload means the source is not actually healthy.
     */
    public function testHasCompleteWebcamExif_RequiresBothFields()
    {
        $complete = $this->createTestJpeg('complete.jpg');
        $this->assertTrue(addExifTimestamp($complete, time()), 'addExifTimestamp must succeed');
        $this->assertTrue(hasCompleteWebcamExif($complete), 'Both fields present must count as complete');

        $localOnly = $this->testDir . '/local_only.jpg';
        copy(__DIR__ . '/../Fixtures/webcam-exif/datetime_only.jpg', $localOnly);
        $this->assertFalse(hasCompleteWebcamExif($localOnly), 'Local time without GPS UTC must be incomplete');

        $gpsOnly = $this->testDir . '/gps_only.jpg';
        copy(__DIR__ . '/../Fixtures/webcam-exif/gps_only.jpg', $gpsOnly);
        $this->assertFalse(hasCompleteWebcamExif($gpsOnly), 'GPS UTC without local time must be incomplete');

        $none = $this->createTestJpeg('none.jpg');
        $this->assertFalse(hasCompleteWebcamExif($none), 'A bare image must be incomplete');
    }
    
    // ========================================
    // CRITICAL TEST 6: Validate Entire Pipeline Integration
    // ========================================
    
    /**
     * @test
     * CRITICAL: Complete EXIF pipeline from image without EXIF to validated image
     * 
     * This simulates the full pipeline that webcam processing uses:
     * 1. Image arrives (no EXIF)
     * 2. ensureImageHasExif() adds EXIF
     * 3. validateExifTimestamp() verifies it
     */
    public function exifPipeline_FromNoExifToValidated_Succeeds()
    {
        $testFile = $this->createTestJpeg('test_full_pipeline.jpg');
        
        // STEP 1: Verify no EXIF initially
        $this->assertFalse(hasExifTimestamp($testFile), 
            'Test must start with image without EXIF');
        
        // STEP 2: ensureImageHasExif must succeed
        $ensureResult = ensureImageHasExif($testFile);
        $this->assertTrue($ensureResult, 
            'ensureImageHasExif() must succeed - this is MANDATORY in pipeline');
        
        // STEP 3: hasExifTimestamp must now return true
        $this->assertTrue(hasExifTimestamp($testFile),
            'hasExifTimestamp() must return true after ensureImageHasExif()');
        
        // STEP 4: validateExifTimestamp must pass
        $validation = validateExifTimestamp($testFile);
        $this->assertTrue($validation['valid'], 
            'validateExifTimestamp() must pass after ensureImageHasExif() - ' .
            'reason: ' . ($validation['reason'] ?? 'none'));
        
        // STEP 5: GPS fields must be present for client-side verification
        $exif = exifNativeRead($testFile);
        $this->assertNotEmpty($exif['GpsDateStamp'] ?? null,
            'GPS DateStamp must be present for client-side verification');
        $this->assertNotEmpty($exif['GpsTimeComponents'] ?? null,
            'GPS TimeStamp must be present for client-side verification');
    }
    
    /**
     * CRITICAL: Pipeline must reject images if EXIF cannot be added
     * 
     * If ensureImageHasExif() fails, processing MUST NOT continue.
     * This is the "mandatory EXIF" requirement.
     */
    public function testExifPipeline_WhenEnsureExifFails_ProcessingMustStop()
    {
        // Use a non-existent file to force failure
        $nonExistentFile = $this->testDir . '/does_not_exist.jpg';
        
        // ACT: Try to ensure EXIF on non-existent file
        $result = ensureImageHasExif($nonExistentFile);
        
        // ASSERT: Must return false
        $this->assertFalse($result, 
            'ensureImageHasExif() must return false when it cannot add EXIF - ' .
            'this signals to caller that processing must stop');
    }
    
    // ========================================
    // CRITICAL TEST 7: UTC Time Verification
    // ========================================
    
    /**
     * CRITICAL: EXIF DateTimeOriginal must be written in LOCAL time per EXIF standard
     * 
     * Per EXIF standard:
     * - DateTimeOriginal: Local time at capture location (camera's timezone)
     * - GPS fields: UTC (Zulu time) for aviation use
     * 
     * For PULL cameras (MJPEG/RTSP/static), we convert UTC to the airport's local timezone.
     * For PUSH cameras, normalizeExifToUtc() preserves camera's local DateTimeOriginal
     * and adds GPS UTC fields.
     * 
     * Client-side JavaScript uses GPS fields for UTC verification.
     */
    public function testAddExifTimestamp_WritesLocalTime_BasedOnTimezone()
    {
        // Use a specific UTC timestamp
        // 2026-01-13 10:30:00 UTC = 2026-01-13 02:30:00 PST (America/Los_Angeles, UTC-8)
        $utcTimestamp = strtotime('2026-01-13 10:30:00 UTC');
        
        $testFile = $this->createTestJpeg('test_local_time.jpg');
        
        // ACT: Add EXIF with America/Los_Angeles timezone
        $result = addExifTimestamp($testFile, $utcTimestamp, 'America/Los_Angeles');
        $this->assertTrue($result);
        
        // ASSERT: Read back EXIF DateTimeOriginal
        $exifDateTime = exifNativeRead($testFile)['DateTimeOriginal'] ?? null;
        $this->assertNotEmpty($exifDateTime);
        
        // ASSERT: Must be LOCAL time (PST), not UTC
        $dt = new \DateTime('@' . $utcTimestamp);
        $dt->setTimezone(new \DateTimeZone('America/Los_Angeles'));
        $expectedLocal = $dt->format('Y:m:d H:i:s');
        
        $this->assertEquals($expectedLocal, $exifDateTime,
            'EXIF DateTimeOriginal must be local time per EXIF standard - ' .
            'Client uses GPS fields for UTC verification');
        
        // ASSERT: Verify it's definitely NOT UTC
        $expectedUtc = gmdate('Y:m:d H:i:s', $utcTimestamp);
        $this->assertNotEquals($expectedUtc, $exifDateTime,
            'DateTimeOriginal must be local time, not UTC (GPS fields contain UTC)');
    }
    
    /**
     * CRITICAL: GPS timestamp must be UTC for client-side verification
     */
    public function testAddExifTimestamp_GpsFieldsAreUtc()
    {
        // Use a timestamp where UTC differs from most local timezones
        // 2026-01-13 23:45:30 UTC (nearly midnight UTC, different day in US timezones)
        $utcTimestamp = strtotime('2026-01-13 23:45:30 UTC');
        
        $testFile = $this->createTestJpeg('test_gps_utc.jpg');
        
        // ACT
        $result = addExifTimestamp($testFile, $utcTimestamp);
        $this->assertTrue($result);
        
        // ASSERT: GPS fields must match UTC time
        $exif = exifNativeRead($testFile);
        $gpsDate = $exif['GpsDateStamp'] ?? null;
        $components = $exif['GpsTimeComponents'] ?? null;
        
        $expectedGpsDate = gmdate('Y:m:d', $utcTimestamp);  // UTC!
        $expectedGpsTime = gmdate('H:i:s', $utcTimestamp);  // UTC!
        
        $this->assertSame($expectedGpsDate, $gpsDate, 'GPS DateStamp must be UTC date');
        $this->assertSame(
            $expectedGpsTime,
            sprintf('%02d:%02d:%02d', $components[0], $components[1], $components[2]),
            'GPS TimeStamp must be UTC time'
        );
    }

    // ========================================
    // P1: Retry Logic - Fail closed after retries exhausted
    // ========================================

    /**
     * CRITICAL: addExifTimestamp must return false on corrupt/unwritable image
     *
     * When the native writer fails (corrupt image, permission denied), retries
     * exhaust and function must fail closed - return false, never pass invalid image.
     */
    public function testAddExifTimestamp_WithCorruptImage_ReturnsFalse()
    {
        // Create a file the native writer cannot process (not a valid image)
        $corruptFile = $this->testDir . '/corrupt.jpg';
        file_put_contents($corruptFile, 'not a valid jpeg - garbage data');

        $result = addExifTimestamp($corruptFile, time());

        $this->assertFalse($result,
            'addExifTimestamp must return false when the native writer fails - fail closed');
    }

    /**
     * CRITICAL: ensureImageHasExif must fail when adding GPS to existing EXIF fails
     *
     * When image has EXIF but addExifTimestamp fails (e.g. read-only directory),
     * ensureImageHasExif must return false - not ignore the failure.
     */
    public function testEnsureImageHasExif_ReturnsFalseForMissingFile()
    {
        $missing = $this->testDir . '/does-not-exist.jpg';
        $this->assertFalse(ensureImageHasExif($missing, null, 'UTC'),
            'ensureImageHasExif must fail closed on a missing file');
    }

    // ========================================
    // P2: Timeout - Must complete within reasonable time
    // ========================================

    /**
     * CRITICAL: addExifTimestamp must complete within timeout (no indefinite hang)
     */
    public function testAddExifTimestamp_WithValidImage_CompletesWithinTimeout()
    {
        $testFile = $this->createTestJpeg('test_timeout.jpg');
        $start = microtime(true);
        $result = addExifTimestamp($testFile, time());
        $elapsed = microtime(true) - $start;

        $this->assertTrue($result, 'addExifTimestamp must succeed');
        $this->assertLessThan(15, $elapsed,
            'addExifTimestamp must complete within 15s (timeout prevents indefinite hang)');
    }

    // ========================================
    // P4: Context in error logs
    // ========================================

    /**
     * addExifTimestamp must accept optional context for error logging
     */
    public function testAddExifTimestamp_AcceptsOptionalContextParameter()
    {
        $nonExistent = $this->testDir . '/nonexistent.jpg';
        $context = ['airport_id' => 'KSPB', 'cam_index' => 0, 'source_type' => 'push'];

        // Should not throw - context is optional and used in error log
        $result = addExifTimestamp($nonExistent, time(), 'UTC', false, $context);

        $this->assertFalse($result);
    }

    public function testCopyExifMetadata_WebpDestination_CarriesExifAndFailsClosedWithoutSource()
    {
        $sourceFile = $this->createTestJpeg('source_copy.jpg');
        addExifTimestamp($sourceFile, strtotime('2025-01-02 03:04:05 UTC'));

        $destFile = $this->testDir . '/output.webp';
        $img = imagecreatetruecolor(32, 24);
        imagefill($img, 0, 0, imagecolorallocate($img, 10, 20, 30));
        imagewebp($img, $destFile);
        imagedestroy($img);

        $this->assertTrue(copyExifMetadata($sourceFile, $destFile),
            'native copy into a WebP must succeed');
        $dest = exifNativeRead($destFile);
        $this->assertSame('2025:01:02 03:04:05', $dest['DateTimeOriginal'] ?? null);

        // A source without EXIF must fail closed and leave the destination alone.
        $noExif = $this->createTestJpeg('source_noexif_copy.jpg');
        $destCopy = $destFile . '.copy';
        copy($destFile, $destCopy);
        $before = file_get_contents($destCopy);
        $this->assertFalse(copyExifMetadata($noExif, $destCopy));
        $this->assertSame($before, file_get_contents($destCopy));
    }
}
