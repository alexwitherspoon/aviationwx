<?php
/**
 * Unit Tests for BaseAcquisitionStrategy::detectErrorFrame
 * Verifies the acquisition wrapper forwards the capture timestamp to the
 * global error-frame detector so daylight-phase checks use capture time.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../../lib/cache-paths.php';
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/webcam-acquisition.php';

class TestAcquisitionStrategy extends BaseAcquisitionStrategy
{
    public function acquire(): AcquisitionResult
    {
        return AcquisitionResult::failure('not_implemented', 'test');
    }

    public function getSourceType(): string
    {
        return 'test';
    }

    public function exposeDetectErrorFrame(string $imagePath, $gdImage = null, ?string $sourceFormat = null, ?int $timestamp = null): array
    {
        return $this->detectErrorFrame($imagePath, $gdImage, $sourceFormat, $timestamp);
    }
}

class BaseAcquisitionStrategyTest extends TestCase
{
    /**
     * The wrapper must forward the timestamp, not let the detector fall back to
     * processing time. A solid black frame is accepted at midnight and rejected
     * at noon only when the timestamp reaches the phase check.
     */
    public function testDetectErrorFrame_ForwardsCaptureTimestamp(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD library not available');
        }

        $strategy = new TestAcquisitionStrategy('test', 0, [], ['lat' => 0.0, 'lon' => 0.0]);

        $path = sys_get_temp_dir() . '/acq_black_' . uniqid() . '.jpg';
        $img = imagecreatetruecolor(320, 240);
        $black = imagecolorallocate($img, 0, 0, 0);
        imagefilledrectangle($img, 0, 0, 319, 239, $black);
        imagejpeg($img, $path, 90);
        imagedestroy($img);

        try {
            $midnight = strtotime('2026-06-15 00:00:00 UTC');
            $night = $strategy->exposeDetectErrorFrame($path, null, null, $midnight);
            $this->assertFalse($night['is_error'], 'Night black frame must be accepted');

            $noon = strtotime('2026-06-15 12:00:00 UTC');
            $day = $strategy->exposeDetectErrorFrame($path, null, null, $noon);
            $this->assertTrue($day['is_error'], 'Day black frame must be rejected');
        } finally {
            if (file_exists($path)) {
                @unlink($path);
            }
        }
    }
}
