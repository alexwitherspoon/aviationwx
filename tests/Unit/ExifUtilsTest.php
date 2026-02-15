<?php
/**
 * Unit Tests for EXIF Utilities
 * 
 * Tests EXIF timestamp functionality including:
 * - Filename timestamp parsing for various IP camera formats
 * - Timestamp validation with rolling windows
 * - Year boundary edge cases
 * - Unix timestamp detection
 * - Fallback to file mtime
 */

namespace AviationWX\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/exif-utils.php';

class ExifUtilsTest extends TestCase
{
    private $testDir;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->testDir = sys_get_temp_dir() . '/exif_utils_test_' . uniqid();
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
     * Create a test file with specific mtime
     */
    private function createTestFile(string $filename, ?int $mtime = null): string
    {
        $filePath = $this->testDir . '/' . $filename;
        file_put_contents($filePath, 'test content');
        
        if ($mtime !== null) {
            touch($filePath, $mtime);
        }
        
        return $filePath;
    }
    
    // ========================================
    // parseFilenameTimestamp() Tests
    // ========================================
    
    /**
     * Test YYYYMMDDHHmmss pattern (Reolink format)
     */
    public function testParseFilenameTimestamp_ReolinkFormat_ReturnsTimestamp()
    {
        // Create filename with current year timestamp
        $now = time();
        $timestampStr = date('YmdHis', $now);
        $filename = "KCZK-01_00_{$timestampStr}.jpg";
        
        $filePath = $this->createTestFile($filename, $now);
        
        $result = parseFilenameTimestamp($filePath);
        
        $this->assertTrue($result['found']);
        $this->assertEquals('YYYYMMDDHHmmss', $result['pattern']);
        // Allow 1 second difference due to date() precision
        $this->assertEqualsWithDelta($now, $result['timestamp'], 1);
    }
    
    /**
     * Test YYYY-MM-DD_HH-MM-SS pattern
     */
    public function testParseFilenameTimestamp_DashUnderscoreFormat_ReturnsTimestamp()
    {
        $now = time();
        $timestampStr = date('Y-m-d_H-i-s', $now);
        $filename = "webcam_{$timestampStr}.jpg";
        
        $filePath = $this->createTestFile($filename, $now);
        
        $result = parseFilenameTimestamp($filePath);
        
        $this->assertTrue($result['found']);
        $this->assertEquals('YYYY-MM-DD_HH-MM-SS', $result['pattern']);
        $this->assertEqualsWithDelta($now, $result['timestamp'], 1);
    }
    
    /**
     * Test YYYY_MM_DD_HH_MM_SS pattern
     */
    public function testParseFilenameTimestamp_UnderscoreFormat_ReturnsTimestamp()
    {
        $now = time();
        $timestampStr = date('Y_m_d_H_i_s', $now);
        $filename = "cam_{$timestampStr}.jpg";
        
        $filePath = $this->createTestFile($filename, $now);
        
        $result = parseFilenameTimestamp($filePath);
        
        $this->assertTrue($result['found']);
        $this->assertEquals('YYYY_MM_DD_HH_MM_SS', $result['pattern']);
        $this->assertEqualsWithDelta($now, $result['timestamp'], 1);
    }
    
    /**
     * Test YYYYMMDDTHHmmss pattern (ISO-like)
     */
    public function testParseFilenameTimestamp_IsoLikeFormat_ReturnsTimestamp()
    {
        $now = time();
        $timestampStr = date('Ymd\THis', $now);
        $filename = "{$timestampStr}.jpg";
        
        $filePath = $this->createTestFile($filename, $now);
        
        $result = parseFilenameTimestamp($filePath);
        
        $this->assertTrue($result['found']);
        $this->assertEquals('YYYYMMDDTHHmmss', $result['pattern']);
        $this->assertEqualsWithDelta($now, $result['timestamp'], 1);
    }
    
    /**
     * Test Unix timestamp pattern
     */
    public function testParseFilenameTimestamp_UnixTimestamp_ReturnsTimestamp()
    {
        $now = time();
        $filename = "webcam_{$now}.jpg";
        
        $filePath = $this->createTestFile($filename, $now);
        
        $result = parseFilenameTimestamp($filePath);
        
        $this->assertTrue($result['found']);
        $this->assertEquals('unix_timestamp', $result['pattern']);
        $this->assertEquals($now, $result['timestamp']);
    }
    
    /**
     * Test filename with no timestamp
     */
    public function testParseFilenameTimestamp_NoTimestamp_ReturnsNotFound()
    {
        $filename = "webcam_image.jpg";
        
        $filePath = $this->createTestFile($filename, time());
        
        $result = parseFilenameTimestamp($filePath);
        
        $this->assertFalse($result['found']);
        $this->assertEquals(0, $result['timestamp']);
        $this->assertNull($result['pattern']);
    }
    
    /**
     * Test random 14-digit number that doesn't form valid date
     */
    public function testParseFilenameTimestamp_Invalid14Digits_ReturnsNotFound()
    {
        // 99991399990099 - invalid month (99), invalid day (99), etc.
        $filename = "cam_99991399990099.jpg";
        
        $filePath = $this->createTestFile($filename, time());
        
        $result = parseFilenameTimestamp($filePath);
        
        $this->assertFalse($result['found']);
    }
    
    /**
     * Test timestamp from wrong year (should be rejected)
     */
    public function testParseFilenameTimestamp_WrongYear_ReturnsNotFound()
    {
        // Year 2019 is outside the allowed window (current year ±1 at boundaries)
        $filename = "cam_20190615120000.jpg";
        
        $filePath = $this->createTestFile($filename, time());
        
        $result = parseFilenameTimestamp($filePath);
        
        $this->assertFalse($result['found']);
    }
    
    /**
     * Test timestamp too far from file mtime (>24 hours difference)
     */
    public function testParseFilenameTimestamp_TooFarFromMtime_ReturnsNotFound()
    {
        $now = time();
        // Filename timestamp is current, but file mtime is 48 hours ago
        $timestampStr = date('YmdHis', $now);
        $filename = "cam_{$timestampStr}.jpg";
        
        $filePath = $this->createTestFile($filename, $now - (48 * 3600));
        
        $result = parseFilenameTimestamp($filePath);
        
        $this->assertFalse($result['found']);
    }

    // ========================================
    // False positive prevention tests
    // ========================================

    /**
     * Reject 14 digits embedded in longer numeric string (product ID / serial)
     *
     * "20251229210421000123" - first 14 digits look like timestamp but are part
     * of longer number. Non-digit boundaries prevent false positive.
     */
    public function testParseFilenameTimestamp_14DigitsInLongerNumber_ReturnsNotFound()
    {
        $now = time();
        $ts = date('YmdHis', $now);
        $filename = "product{$ts}00123.jpg";
        $filePath = $this->createTestFile($filename, $now);

        $result = parseFilenameTimestamp($filePath);

        $this->assertFalse($result['found'], 'Must not extract timestamp from longer numeric string');
    }

    /**
     * Reject product ID format: no delimiter before 14 digits
     *
     * "SN20251229210421X" - serial number, not camera timestamp.
     */
    public function testParseFilenameTimestamp_ProductIdNoDelimiter_ReturnsNotFound()
    {
        $now = time();
        $ts = date('YmdHis', $now);
        $filename = "SN{$ts}X.jpg";
        $filePath = $this->createTestFile($filename, $now);

        $result = parseFilenameTimestamp($filePath);

        $this->assertFalse($result['found'], 'Must require delimiter before 14-digit timestamp');
    }

    /**
     * Accept timestamp at start of filename (no delimiter needed)
     */
    public function testParseFilenameTimestamp_TimestampAtStart_ReturnsTimestamp()
    {
        $now = time();
        $ts = date('YmdHis', $now);
        $filename = "{$ts}.jpg";
        $filePath = $this->createTestFile($filename, $now);

        $result = parseFilenameTimestamp($filePath);

        $this->assertTrue($result['found']);
        $this->assertEqualsWithDelta($now, $result['timestamp'], 1);
    }

    /**
     * When multiple 14-digit sequences exist, prefer one closest to mtime
     */
    public function testParseFilenameTimestamp_Multiple14DigitSequences_PrefersClosestToMtime()
    {
        $now = time();
        $ts1 = date('YmdHis', $now - (2 * 3600));
        $ts2 = date('YmdHis', $now + 3600);
        $filename = "{$ts1}_{$ts2}.jpg";
        $filePath = $this->createTestFile($filename, $now);

        $result = parseFilenameTimestamp($filePath);

        $this->assertTrue($result['found']);
        $this->assertEqualsWithDelta($now + 3600, $result['timestamp'], 1,
            'Should prefer ts2 (1h ahead) over ts1 (2h behind) - closer to mtime');
    }

    /**
     * Reject when timestamp exceeds tighter mtime window (±12 hours)
     */
    public function testParseFilenameTimestamp_Exceeds12HourMtimeWindow_ReturnsNotFound()
    {
        $now = time();
        $ts = date('YmdHis', $now);
        $filename = "cam_{$ts}.jpg";
        $filePath = $this->createTestFile($filename, $now - (14 * 3600));

        $result = parseFilenameTimestamp($filePath);

        $this->assertFalse($result['found'], '14h difference must exceed 12h window');
    }

    /**
     * Accept when timestamp within 12-hour mtime window
     */
    public function testParseFilenameTimestamp_Within12HourMtimeWindow_ReturnsTimestamp()
    {
        $now = time();
        $ts = date('YmdHis', $now);
        $filename = "cam_{$ts}.jpg";
        $filePath = $this->createTestFile($filename, $now - (10 * 3600));

        $result = parseFilenameTimestamp($filePath);

        $this->assertTrue($result['found']);
        $this->assertEqualsWithDelta($now, $result['timestamp'], 1);
    }

    // ========================================
    // parseTimestampComponents() Tests
    // ========================================
    
    /**
     * Test valid timestamp components
     */
    public function testParseTimestampComponents_ValidComponents_ReturnsTimestamp()
    {
        $year = date('Y');
        $result = parseTimestampComponents($year, '06', '15', '12', '30', '45');
        
        $this->assertNotNull($result);
        $expected = mktime(12, 30, 45, 6, 15, intval($year));
        $this->assertEquals($expected, $result);
    }
    
    /**
     * Test invalid month
     */
    public function testParseTimestampComponents_InvalidMonth_ReturnsNull()
    {
        $year = date('Y');
        $result = parseTimestampComponents($year, '13', '15', '12', '30', '45');
        
        $this->assertNull($result);
    }
    
    /**
     * Test invalid day (Feb 30)
     */
    public function testParseTimestampComponents_InvalidDay_ReturnsNull()
    {
        $year = date('Y');
        $result = parseTimestampComponents($year, '02', '30', '12', '30', '45');
        
        $this->assertNull($result);
    }
    
    /**
     * Test invalid hour
     */
    public function testParseTimestampComponents_InvalidHour_ReturnsNull()
    {
        $year = date('Y');
        $result = parseTimestampComponents($year, '06', '15', '25', '30', '45');
        
        $this->assertNull($result);
    }
    
    /**
     * Test Feb 29 in leap year (valid)
     */
    public function testParseTimestampComponents_Feb29LeapYear_ReturnsTimestamp()
    {
        // 2024 is a leap year
        $currentYear = intval(date('Y'));
        // Find the closest leap year to current year
        $leapYear = $currentYear;
        while ($leapYear % 4 !== 0 || ($leapYear % 100 === 0 && $leapYear % 400 !== 0)) {
            $leapYear--;
        }
        
        // Skip if leap year is outside allowed window
        $currentMonth = intval(date('n'));
        $allowedYears = [$currentYear];
        if ($currentMonth === 1) $allowedYears[] = $currentYear - 1;
        if ($currentMonth === 12) $allowedYears[] = $currentYear + 1;
        
        if (!in_array($leapYear, $allowedYears)) {
            $this->markTestSkipped('No leap year in allowed window');
        }
        
        $result = parseTimestampComponents((string)$leapYear, '02', '29', '12', '30', '45');
        
        $this->assertNotNull($result);
    }
    
    /**
     * Test Feb 29 in non-leap year (invalid)
     */
    public function testParseTimestampComponents_Feb29NonLeapYear_ReturnsNull()
    {
        $currentYear = intval(date('Y'));
        // Find a non-leap year in the allowed window
        $nonLeapYear = $currentYear;
        while ($nonLeapYear % 4 === 0 && ($nonLeapYear % 100 !== 0 || $nonLeapYear % 400 === 0)) {
            $nonLeapYear--;
        }
        
        // Skip if non-leap year is outside allowed window
        $currentMonth = intval(date('n'));
        $allowedYears = [$currentYear];
        if ($currentMonth === 1) $allowedYears[] = $currentYear - 1;
        if ($currentMonth === 12) $allowedYears[] = $currentYear + 1;
        
        if (!in_array($nonLeapYear, $allowedYears)) {
            $this->markTestSkipped('No non-leap year in allowed window');
        }
        
        $result = parseTimestampComponents((string)$nonLeapYear, '02', '29', '12', '30', '45');
        
        $this->assertNull($result);
    }
    
    // ========================================
    // isTimestampReasonable() Tests
    // ========================================
    
    /**
     * Test timestamp within reasonable range
     */
    public function testIsTimestampReasonable_WithinRange_ReturnsTrue()
    {
        $mtime = time();
        $timestamp = $mtime - 3600; // 1 hour difference
        
        $result = isTimestampReasonable($timestamp, $mtime);
        
        $this->assertTrue($result);
    }
    
    /**
     * Test timestamp outside reasonable range
     */
    public function testIsTimestampReasonable_OutsideRange_ReturnsFalse()
    {
        $mtime = time();
        $timestamp = $mtime - (48 * 3600); // 48 hours difference
        
        $result = isTimestampReasonable($timestamp, $mtime);
        
        $this->assertFalse($result);
    }
    
    /**
     * Test zero timestamp
     */
    public function testIsTimestampReasonable_ZeroTimestamp_ReturnsFalse()
    {
        $result = isTimestampReasonable(0, time());
        
        $this->assertFalse($result);
    }
    
    /**
     * Test negative timestamp
     */
    public function testIsTimestampReasonable_NegativeTimestamp_ReturnsFalse()
    {
        $result = isTimestampReasonable(-1, time());
        
        $this->assertFalse($result);
    }
    
    // ========================================
    // getTimestampForExif() Tests
    // ========================================
    
    /**
     * Test getTimestampForExif with filename timestamp
     */
    public function testGetTimestampForExif_WithFilenameTimestamp_ReturnsFilenameTimestamp()
    {
        $now = time();
        $timestampStr = date('YmdHis', $now);
        $filename = "KCZK-01_00_{$timestampStr}.jpg";
        
        $filePath = $this->createTestFile($filename, $now);
        
        $result = getTimestampForExif($filePath);
        
        $this->assertEqualsWithDelta($now, $result, 1);
    }
    
    /**
     * Test getTimestampForExif falls back to mtime
     */
    public function testGetTimestampForExif_NoFilenameTimestamp_ReturnsMtime()
    {
        $mtime = time() - 3600; // 1 hour ago
        $filename = "webcam_image.jpg";
        
        $filePath = $this->createTestFile($filename, $mtime);
        
        $result = getTimestampForExif($filePath);
        
        $this->assertEquals($mtime, $result);
    }
    
    // ========================================
    // Year Boundary Edge Cases
    // ========================================
    
    /**
     * Test that current year is always accepted
     */
    public function testYearValidation_CurrentYear_Accepted()
    {
        $currentYear = date('Y');
        $result = parseTimestampComponents($currentYear, '06', '15', '12', '00', '00');
        
        $this->assertNotNull($result, "Current year {$currentYear} should be accepted");
    }
    
    /**
     * Test that years far in the past are rejected
     */
    public function testYearValidation_FarPast_Rejected()
    {
        $oldYear = (string)(intval(date('Y')) - 10);
        $result = parseTimestampComponents($oldYear, '06', '15', '12', '00', '00');
        
        $this->assertNull($result, "Year {$oldYear} should be rejected");
    }
    
    /**
     * Test that years far in the future are rejected
     */
    public function testYearValidation_FarFuture_Rejected()
    {
        $futureYear = (string)(intval(date('Y')) + 10);
        $result = parseTimestampComponents($futureYear, '06', '15', '12', '00', '00');
        
        $this->assertNull($result, "Year {$futureYear} should be rejected");
    }
}
