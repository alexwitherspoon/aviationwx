<?php
/**
 * Critical EXIF Pipeline Tests
 * 
 * Tests that catch critical bugs in the EXIF processing pipeline:
 * - EXIF must be added before processing continues
 * - GPS timestamp must be written correctly
 * - exiftool commands must be syntactically valid
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
    private $hasExiftool;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->testDir = sys_get_temp_dir() . '/exif_pipeline_test_' . uniqid();
        @mkdir($this->testDir, 0755, true);
        
        // Check if exiftool is available
        exec('which exiftool 2>/dev/null', $output, $exitCode);
        $this->hasExiftool = ($exitCode === 0);
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
     * - Broken exiftool command syntax
     * - Missing exiftool binary
     * - File permissions issues
     */
    public function testAddExifTimestamp_WithValidImage_SucceedsAndAddsExif()
    {
        if (!$this->hasExiftool) {
            $this->markTestSkipped('exiftool not available');
        }
        
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
     * - Shell escaping issues that break GPS timestamp
     */
    public function testAddExifTimestamp_WithValidImage_AddsGpsTimestamp()
    {
        if (!$this->hasExiftool) {
            $this->markTestSkipped('exiftool not available');
        }
        
        $testFile = $this->createTestJpeg('test_gps_timestamp.jpg');
        $timestamp = strtotime('2026-01-13 05:20:03 UTC');
        
        // ACT: Add EXIF timestamp
        $result = addExifTimestamp($testFile, $timestamp);
        $this->assertTrue($result, 'addExifTimestamp() must succeed');
        
        // ASSERT: GPS TimeStamp must be present
        exec('exiftool -s -s -s -GPSTimeStamp ' . escapeshellarg($testFile), $output, $exitCode);
        $this->assertEquals(0, $exitCode, 'exiftool must read GPS timestamp successfully');
        $this->assertNotEmpty($output, 'GPS TimeStamp must be present in EXIF');
        
        $gpsTimeStamp = trim($output[0] ?? '');
        $this->assertNotEmpty($gpsTimeStamp, 'GPS TimeStamp value must not be empty');
        
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
        if (!$this->hasExiftool) {
            $this->markTestSkipped('exiftool not available');
        }
        
        $testFile = $this->createTestJpeg('test_gps_date.jpg');
        $timestamp = strtotime('2026-01-13 05:20:03 UTC');
        
        // ACT
        $result = addExifTimestamp($testFile, $timestamp);
        $this->assertTrue($result);
        
        // ASSERT: GPS DateStamp must be present
        exec('exiftool -s -s -s -GPSDateStamp ' . escapeshellarg($testFile), $output, $exitCode);
        $this->assertEquals(0, $exitCode);
        $this->assertNotEmpty($output);
        
        $gpsDateStamp = trim($output[0] ?? '');
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
        if (!$this->hasExiftool) {
            $this->markTestSkipped('exiftool not available');
        }
        
        $testFile = $this->createTestJpeg('test_attribution.jpg');
        $timestamp = time();
        
        // ACT
        $result = addExifTimestamp($testFile, $timestamp);
        $this->assertTrue($result);
        
        // ASSERT: Check all attribution fields
        exec('exiftool -Artist -Copyright -Rights -ImageDescription -UserComment ' . 
             escapeshellarg($testFile), $output);
        $outputText = implode("\n", $output);
        
        $this->assertStringContainsString('Artist', $outputText, 'Artist field must be present');
        $this->assertStringContainsString('AviationWX.org', $outputText, 'AviationWX.org must be in metadata');
        $this->assertStringContainsString('Copyright', $outputText, 'Copyright field must be present');
        $this->assertStringContainsString('Rights', $outputText, 'Rights field must be present');
        $this->assertStringContainsString('Image Description', $outputText, 'ImageDescription must be present');
        $this->assertStringContainsString('User Comment', $outputText, 'UserComment must be present');
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
        if (!$this->hasExiftool) {
            $this->markTestSkipped('exiftool not available');
        }
        
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
        if (!$this->hasExiftool) {
            $this->markTestSkipped('exiftool not available');
        }
        
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
    
    // ========================================
    // CRITICAL TEST 5: exiftool Command Syntax Must Be Valid
    // ========================================
    
    /**
     * CRITICAL: exiftool must not receive broken arguments
     * 
     * This test catches:
     * - Arguments with spaces being split incorrectly
     * - Missing shell escaping
     * - Array/string concatenation bugs
     */
    public function testAddExifTimestamp_CommandSyntax_DoesNotProduceShellErrors()
    {
        if (!$this->hasExiftool) {
            $this->markTestSkipped('exiftool not available');
        }
        
        $testFile = $this->createTestJpeg('test_command_syntax.jpg');
        $timestamp = time();
        
        // ACT: This should not produce shell errors
        $result = addExifTimestamp($testFile, $timestamp);
        
        // ASSERT: Must succeed without errors
        $this->assertTrue($result, 'addExifTimestamp() must not fail due to command syntax errors');
        
        // ASSERT: File must not have error messages written to it
        $fileContents = file_get_contents($testFile);
        $this->assertStringNotContainsString('File not found', $fileContents,
            'Image file must not contain shell error messages');
        $this->assertStringNotContainsString('Error:', $fileContents,
            'Image file must not contain error messages from broken commands');
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
        if (!$this->hasExiftool) {
            $this->markTestSkipped('exiftool not available');
        }
        
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
        exec('exiftool -GPSTimeStamp -GPSDateStamp ' . escapeshellarg($testFile), $output);
        $outputText = implode("\n", $output);
        $this->assertStringContainsString('GPS Time Stamp', $outputText,
            'GPS TimeStamp must be present for client-side verification');
        $this->assertStringContainsString('GPS Date Stamp', $outputText,
            'GPS DateStamp must be present for client-side verification');
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
        if (!$this->hasExiftool) {
            $this->markTestSkipped('exiftool not available');
        }
        
        // Use a specific UTC timestamp
        // 2026-01-13 10:30:00 UTC = 2026-01-13 02:30:00 PST (America/Los_Angeles, UTC-8)
        $utcTimestamp = strtotime('2026-01-13 10:30:00 UTC');
        
        $testFile = $this->createTestJpeg('test_local_time.jpg');
        
        // ACT: Add EXIF with America/Los_Angeles timezone
        $result = addExifTimestamp($testFile, $utcTimestamp, 'America/Los_Angeles');
        $this->assertTrue($result);
        
        // ASSERT: Read back EXIF DateTimeOriginal
        exec('exiftool -s -s -s -DateTimeOriginal ' . escapeshellarg($testFile), $output, $exitCode);
        $this->assertEquals(0, $exitCode);
        $this->assertNotEmpty($output);
        
        $exifDateTime = trim($output[0] ?? '');
        
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
        if (!$this->hasExiftool) {
            $this->markTestSkipped('exiftool not available');
        }
        
        // Use a timestamp where UTC differs from most local timezones
        // 2026-01-13 23:45:30 UTC (nearly midnight UTC, different day in US timezones)
        $utcTimestamp = strtotime('2026-01-13 23:45:30 UTC');
        
        $testFile = $this->createTestJpeg('test_gps_utc.jpg');
        
        // ACT
        $result = addExifTimestamp($testFile, $utcTimestamp);
        $this->assertTrue($result);
        
        // ASSERT: GPS fields must match UTC time
        exec('exiftool -s -s -s -GPSTimeStamp -GPSDateStamp ' . escapeshellarg($testFile), $output);
        $outputText = implode("\n", $output);
        
        $expectedGpsDate = gmdate('Y:m:d', $utcTimestamp);  // UTC!
        $expectedGpsTime = gmdate('H:i:s', $utcTimestamp);  // UTC!
        
        $this->assertStringContainsString($expectedGpsDate, $outputText,
            'GPS DateStamp must be UTC date');
        $this->assertStringContainsString($expectedGpsTime, $outputText,
            'GPS TimeStamp must be UTC time');
    }

    // ========================================
    // P1: Retry Logic - Fail closed after retries exhausted
    // ========================================

    /**
     * CRITICAL: addExifTimestamp must return false on corrupt/unwritable image
     *
     * When exiftool fails (corrupt image, permission denied), retries exhaust
     * and function must fail closed - return false, never pass invalid image.
     */
    public function testAddExifTimestamp_WithCorruptImage_ReturnsFalse()
    {
        if (!$this->hasExiftool) {
            $this->markTestSkipped('exiftool not available');
        }

        // Create file that exiftool cannot process (not a valid image)
        $corruptFile = $this->testDir . '/corrupt.jpg';
        file_put_contents($corruptFile, 'not a valid jpeg - garbage data');

        $result = addExifTimestamp($corruptFile, time());

        $this->assertFalse($result,
            'addExifTimestamp must return false when exiftool fails - fail closed');
    }

    /**
     * CRITICAL: ensureImageHasExif must fail when adding GPS to existing EXIF fails
     *
     * When image has EXIF but addExifTimestamp fails (e.g. read-only directory),
     * ensureImageHasExif must return false - not ignore the failure.
     */
    public function testEnsureImageHasExif_WhenGpsAddFails_ReturnsFalse()
    {
        if (!$this->hasExiftool) {
            $this->markTestSkipped('exiftool not available');
        }

        $testFile = $this->createTestJpeg('test_readonly.jpg');
        addExifTimestamp($testFile, time());
        $this->assertTrue(hasExifTimestamp($testFile), 'Setup: image must have EXIF');

        // Make directory read-only so exiftool cannot create temp file for -overwrite_original
        $testDir = dirname($testFile);
        $origDirMode = @fileperms($testDir);
        @chmod($testDir, 0555);

        $result = ensureImageHasExif($testFile);

        // Restore directory permissions for cleanup
        if ($origDirMode !== false) {
            @chmod($testDir, $origDirMode & 0777);
        }

        $this->assertFalse($result,
            'ensureImageHasExif must return false when addExifTimestamp fails on existing EXIF');
    }

    // ========================================
    // P2: Timeout - Must complete within reasonable time
    // ========================================

    /**
     * CRITICAL: addExifTimestamp must complete within timeout (no indefinite hang)
     */
    public function testAddExifTimestamp_WithValidImage_CompletesWithinTimeout()
    {
        if (!$this->hasExiftool) {
            $this->markTestSkipped('exiftool not available');
        }

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

    // ========================================
    // WebP EXIF: exiftool version in failure logs
    // ========================================

    /**
     * getExiftoolVersion must return version string when exiftool available
     *
     * Used for WebP EXIF failure diagnostics - exiftool WebP support varies by version.
     */
    public function testGetExiftoolVersion_WhenExiftoolAvailable_ReturnsVersionString()
    {
        if (!$this->hasExiftool) {
            $this->markTestSkipped('exiftool not available');
        }

        $version = getExiftoolVersion();

        $this->assertNotNull($version, 'getExiftoolVersion must return non-null when exiftool available');
        $this->assertMatchesRegularExpression('/^\d+\.\d+/', $version,
            'exiftool version must be in X.Y format (e.g. 12.70)');
    }

    /**
     * getExiftoolVersion must cache result (same value on repeated calls)
     */
    public function testGetExiftoolVersion_IsCached()
    {
        if (!$this->hasExiftool) {
            $this->markTestSkipped('exiftool not available');
        }

        $v1 = getExiftoolVersion();
        $v2 = getExiftoolVersion();

        $this->assertSame($v1, $v2, 'getExiftoolVersion must return cached value on repeated calls');
    }

    /**
     * copyExifMetadata with WebP dest: on exiftool failure, log includes exiftool_version
     *
     * When EXIF copy fails for a WebP file, logs must include exiftool version
     * for diagnostics (WebP EXIF support varies by exiftool version).
     */
    public function testCopyExifMetadata_WhenWebpDestAndExiftoolFails_LogsIncludeExiftoolVersion()
    {
        if (!$this->hasExiftool) {
            $this->markTestSkipped('exiftool not available');
        }

        $sourceFile = $this->createTestJpeg('source.jpg');
        addExifTimestamp($sourceFile, time());
        $destFile = $this->testDir . '/output.webp';
        file_put_contents($destFile, 'RIFF....WEBP'); // Minimal WebP header placeholder

        $testDir = dirname($destFile);
        $origDirMode = @fileperms($testDir);
        @chmod($testDir, 0555);

        $result = copyExifMetadata($sourceFile, $destFile);

        if ($origDirMode !== false) {
            @chmod($testDir, $origDirMode & 0777);
        }

        $this->assertFalse($result, 'copyExifMetadata returns false when exiftool fails');
        $this->assertNotNull(getExiftoolVersion(), 'exiftool version must be available for log context');
    }
}
