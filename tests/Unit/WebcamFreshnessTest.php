<?php
/**
 * Unit tests for webcam_get_last_completed_timestamp_for_freshness()
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/cache-paths.php';
require_once __DIR__ . '/../../lib/webcam-metadata.php';

class WebcamFreshnessTest extends TestCase
{
    private string $airportId = 'test_freshness_airport';

    protected function setUp(): void
    {
        parent::setUp();
        ensureCacheDir(CACHE_BASE_DIR);
        $this->removeTestCameraTree();
    }

    protected function tearDown(): void
    {
        $this->removeTestCameraTree();
        if (function_exists('apcu_delete')) {
            @apcu_delete('webcam_fresh_ts_v1_' . strtolower($this->airportId) . '_0');
        }
        parent::tearDown();
    }

    private function removeTestCameraTree(): void
    {
        $base = getWebcamCameraDir($this->airportId, 0);
        if (is_dir($base)) {
            $this->deleteTree($base);
        }
    }

    private function deleteTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->deleteTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function writeFrame(int $camIndex, int $timestamp, string $suffix = 'original'): void
    {
        $framesDir = getWebcamFramesDir($this->airportId, $camIndex, $timestamp);
        if (!is_dir($framesDir)) {
            mkdir($framesDir, 0755, true);
        }
        $path = $framesDir . '/' . $timestamp . '_' . $suffix . '.jpg';
        file_put_contents($path, 'x');
    }

    public function testWebcamGetLastCompletedTimestampForFreshness_ReturnsZeroWhenNoFiles(): void
    {
        $this->assertSame(0, webcam_get_last_completed_timestamp_for_freshness($this->airportId, 0));
    }

    public function testWebcamGetLastCompletedTimestampForFreshness_MatchesSingleFrame(): void
    {
        $ts = time() - 120;
        $this->writeFrame(0, $ts);
        $this->assertSame($ts, webcam_get_last_completed_timestamp_for_freshness($this->airportId, 0));
    }

    public function testWebcamGetLastCompletedTimestampForFreshness_UsesSecondNewestWhenTwoFrames(): void
    {
        $older = time() - 3600;
        $newer = time() - 60;
        $this->writeFrame(0, $older);
        $this->writeFrame(0, $newer);
        $this->assertSame($older, webcam_get_last_completed_timestamp_for_freshness($this->airportId, 0));
    }
}
