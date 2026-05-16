<?php
/**
 * Regression tests for TFR map layer UI wiring in pages/airports.php.
 *
 * Ensures the directory map uses a single tap or click popup for TFR copy (no hover tooltip),
 * which avoids duplicate overlays on mobile.
 */

use PHPUnit\Framework\TestCase;

class AirportsDirectoryTfrLayerUiTest extends TestCase {
    private string $airportsPhp;

    protected function setUp(): void {
        parent::setUp();
        $path = dirname(__DIR__, 2) . '/pages/airports.php';
        $this->assertFileExists($path);
        $raw = file_get_contents($path);
        $this->assertIsString($raw);
        $this->airportsPhp = $raw;
    }

    public function testTfrLayerUsesBindPopupOnly(): void {
        $this->assertStringContainsString(
            'layer.bindPopup(\'<div class="tfr-map-popup">\' + lines.join(\'\') + \'</div>\');',
            $this->airportsPhp
        );
        $this->assertStringContainsString('function onEachTfrFeature(feature, layer)', $this->airportsPhp);
    }

    public function testTfrLayerDoesNotUseHoverTooltip(): void {
        $this->assertStringNotContainsString('bindTooltip', $this->airportsPhp);
        $this->assertStringNotContainsString('notam-map-hover-tip', $this->airportsPhp);
        $this->assertStringNotContainsString('bindNotamMapHoverTooltip', $this->airportsPhp);
    }

    public function testTfrLayerLoadsInternalMapApi(): void {
        $this->assertStringContainsString('/api/notam-map.php', $this->airportsPhp);
        $this->assertStringContainsString('loadTfrMapLayer', $this->airportsPhp);
    }
}
