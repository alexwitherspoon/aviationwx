<?php
/**
 * Shared embed metric tile builder for density altitude performance.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Helpers/LoadsNasrAptFixtureCacheTrait.php';
require_once __DIR__ . '/../../lib/embed-templates/shared.php';

class DensityAltitudeMetricTileTest extends TestCase
{
    use LoadsNasrAptFixtureCacheTrait;

    protected function setUp(): void
    {
        $this->loadNasrAptFixtureCache();
    }

    protected function tearDown(): void
    {
        $this->tearDownNasrAptFixtureCache();
    }

    public function testBuildDensityAltitudeMetricTileReturnsNullWhenDaMissing(): void
    {
        $this->assertNull(buildDensityAltitudeMetricTile(null, [], 'ft', 'DA'));
    }

    public function testBuildDensityAltitudeMetricTileNormalTierHasNoWarningClass(): void
    {
        $weather = [
            'density_altitude' => 2000,
            'density_altitude_performance' => [
                'tier' => 'normal',
                'best_end' => ['end_id' => '26', 'rwy_id' => '08/26'],
            ],
        ];

        $tile = buildDensityAltitudeMetricTile(2000, $weather, 'ft', 'DA');

        $this->assertNotNull($tile);
        $this->assertSame('DA', $tile['label']);
        $this->assertStringNotContainsString('density-altitude-warning', (string) ($tile['tile_class_suffix'] ?? ''));
        $this->assertStringNotContainsString('🚩', (string) ($tile['value'] ?? ''));
        $this->assertStringNotContainsString('⚠️', (string) ($tile['value'] ?? ''));
    }

    public function testBuildDensityAltitudeMetricTileWarningIncludesCueAndBestEndTooltip(): void
    {
        $weather = [
            'density_altitude' => 5342,
            'density_altitude_performance' => [
                'tier' => 'warning',
                'fallback' => false,
                'selection_basis' => 'best_performance',
                'best_end' => [
                    'end_id' => '08',
                    'rwy_id' => '08/26',
                    'total_risk' => 2.45,
                    'tier' => 'warning',
                ],
            ],
        ];

        $tile = buildDensityAltitudeMetricTile(5342, $weather, 'ft', 'Density Alt');

        $this->assertNotNull($tile);
        $this->assertStringContainsString('🚩', (string) $tile['value']);
        $this->assertStringContainsString('density-altitude-warning', (string) ($tile['tile_class_suffix'] ?? ''));
        $this->assertStringContainsString('RWY 08 (08/26)', (string) ($tile['tile_attrs'] ?? ''));
    }

    public function testRenderFullWidgetDensityAltitudeMetricHtmlEscapesLabel(): void
    {
        $html = renderFullWidgetDensityAltitudeMetricHtml([
            'label' => 'Density Alt',
            'value' => '5,342 ft 🚩',
            'tile_class_suffix' => ' density-altitude-warning',
            'tile_attrs' => ' title="test"',
        ]);

        $this->assertStringContainsString('Density Alt', $html);
        $this->assertStringContainsString('class="value density-altitude-warning"', $html);
        $this->assertStringContainsString('5,342 ft 🚩', $html);
    }
}
