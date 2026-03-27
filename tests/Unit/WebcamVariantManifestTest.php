<?php
/**
 * Unit tests for webcam variant manifest helpers (availability counts).
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../lib/webcam-variant-manifest.php';

class WebcamVariantManifestTest extends TestCase
{
    private string $airportId = 'cvtst_manifest';

    protected function tearDown(): void
    {
        $base = CACHE_WEBCAMS_DIR . '/' . strtolower($this->airportId);
        if (is_dir($base)) {
            $this->deleteTreeRecursive($base);
        }
        parent::tearDown();
    }

    private function deleteTreeRecursive(string $dir): void
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
                $this->deleteTreeRecursive($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * Resolved timestamp <= 0 must yield null (invalid frame), not 0% coverage from bogus paths.
     */
    public function testGetVariantAvailabilityCounts_ReturnsNullWhenActualTimestampNotPositive(): void
    {
        $camIndex = 0;
        $ts = 0;
        $framesDir = getWebcamFramesDir($this->airportId, $camIndex, $ts);
        ensureCacheDir($framesDir);
        $manifestPath = $framesDir . '/' . $ts . '_manifest.json';
        file_put_contents($manifestPath, json_encode([
            'total_files' => 1,
            'timestamp' => 0,
            'original' => ['exists' => false],
        ]));

        $this->assertNull(getVariantAvailabilityCounts($this->airportId, $camIndex, $ts));
    }

    /**
     * Corrupt manifest with non-array variants must not emit foreach warnings; treat as invalid.
     */
    public function testGetVariantAvailabilityCounts_ReturnsNullWhenVariantsNotArray(): void
    {
        $camIndex = 0;
        $ts = 1704067200;
        $framesDir = getWebcamFramesDir($this->airportId, $camIndex, $ts);
        ensureCacheDir($framesDir);
        $manifestPath = $framesDir . '/' . $ts . '_manifest.json';
        file_put_contents($manifestPath, json_encode([
            'total_files' => 2,
            'timestamp' => $ts,
            'variants' => 'not-an-array',
            'original' => ['exists' => false],
        ]));

        $this->assertNull(getVariantAvailabilityCounts($this->airportId, $camIndex, $ts));
    }

    /**
     * Missing variants key is valid (treated as no height variants); only original/expected totals apply.
     */
    public function testGetVariantAvailabilityCounts_MissingVariantsKey_ReturnsCounts(): void
    {
        $camIndex = 0;
        $ts = 1704153600;
        $framesDir = getWebcamFramesDir($this->airportId, $camIndex, $ts);
        ensureCacheDir($framesDir);
        $manifestPath = $framesDir . '/' . $ts . '_manifest.json';
        file_put_contents($manifestPath, json_encode([
            'total_files' => 1,
            'timestamp' => $ts,
            'original' => ['exists' => false],
        ]));

        $counts = getVariantAvailabilityCounts($this->airportId, $camIndex, $ts);
        $this->assertIsArray($counts);
        $this->assertSame(1, $counts['total']);
        $this->assertSame(0, $counts['available']);
    }
}
