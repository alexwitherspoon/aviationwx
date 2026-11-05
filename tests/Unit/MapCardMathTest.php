<?php
/**
 * Unit tests for the pure web-mercator projection, zoom selection, and focus
 * selection helpers in lib/map-card.php. No GD and no network here.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/map-card.php';

class MapCardMathTest extends TestCase
{
    public function testDistanceKm_OneDegreeLongitudeAtEquator_IsAbout111km(): void
    {
        $d = mapCardDistanceKm(0.0, 0.0, 0.0, 1.0);
        $this->assertEqualsWithDelta(111.195, $d, 0.2);
    }

    public function testDistanceKm_IsSymmetric(): void
    {
        $a = mapCardDistanceKm(45.0, -120.0, 47.0, -122.0);
        $b = mapCardDistanceKm(47.0, -122.0, 45.0, -120.0);
        $this->assertEqualsWithDelta($a, $b, 1e-9);
    }

    public function testLonToTileX_BoundariesAndCenter(): void
    {
        $this->assertEqualsWithDelta(0.0, mapCardLonToTileX(-180.0, 0), 1e-9);
        $this->assertEqualsWithDelta(1.0, mapCardLonToTileX(180.0, 0), 1e-9);
        $this->assertEqualsWithDelta(0.5, mapCardLonToTileX(0.0, 0), 1e-9);
    }

    public function testLatToTileY_EquatorIsHalfway(): void
    {
        $this->assertEqualsWithDelta(0.5, mapCardLatToTileY(0.0, 0), 1e-9);
    }

    public function testPointPixel_OriginAtZoomZero_IsCanvasCenter(): void
    {
        [$px, $py] = mapCardPointPixel(0.0, 0.0, 0);
        $this->assertEqualsWithDelta(128.0, $px, 1e-6);
        $this->assertEqualsWithDelta(128.0, $py, 1e-6);
    }

    public function testPointPixel_ScalesByPowerOfTwo(): void
    {
        [$x0, $y0] = mapCardPointPixel(45.5, -122.5, 3);
        [$x1, $y1] = mapCardPointPixel(45.5, -122.5, 4);
        $this->assertEqualsWithDelta($x0 * 2.0, $x1, 1e-6);
        $this->assertEqualsWithDelta($y0 * 2.0, $y1, 1e-6);
    }

    public function testPseudoRandom_IsDeterministicAndInRange(): void
    {
        $a = mapCardPseudoRandom(123456, 0, 100000);
        $b = mapCardPseudoRandom(123456, 0, 100000);
        $this->assertSame($a, $b);
        $this->assertGreaterThanOrEqual(0, $a);
        $this->assertLessThan(100000, $a);
    }

    public function testChooseZoom_ClosePoints_ReturnMaxZoom(): void
    {
        $points = [
            ['lat' => 45.0, 'lon' => -122.0],
            ['lat' => 45.3, 'lon' => -122.2],
            ['lat' => 44.9, 'lon' => -122.6],
        ];
        $this->assertSame(7, mapCardChooseZoom($points, 1200, 630, 7));
    }

    public function testChooseZoom_WidelySeparatedPoints_StepDown(): void
    {
        $points = [
            ['lat' => 40.0, 'lon' => -120.0],
            ['lat' => 40.0, 'lon' => -75.0],
        ];
        $this->assertSame(5, mapCardChooseZoom($points, 1200, 630, 7));
    }

    public function testSelectFocus_ReturnsAtLeastMinimum(): void
    {
        $points = [];
        for ($i = 0; $i < 10; $i++) {
            $points[] = ['id' => "ap$i", 'lat' => 45.0 + $i * 0.01, 'lon' => -122.0];
        }
        $focus = mapCardSelectFocus($points, 42);
        $this->assertCount(MAP_CARD_MIN_FOCUS_AIRPORTS, $focus);
    }

    public function testSelectFocus_IsDeterministic(): void
    {
        $points = [];
        for ($i = 0; $i < 10; $i++) {
            $points[] = ['id' => "ap$i", 'lat' => 45.0 + $i * 0.05, 'lon' => -122.0 - $i * 0.03];
        }
        $this->assertEquals(mapCardSelectFocus($points, 7), mapCardSelectFocus($points, 7));
    }

    public function testSelectFocus_ReturnsSubsetOfInput(): void
    {
        $points = [];
        for ($i = 0; $i < 10; $i++) {
            $points[] = ['id' => "ap$i", 'lat' => 45.0 + $i * 0.05, 'lon' => -122.0];
        }
        $ids = array_column($points, 'id');
        foreach (mapCardSelectFocus($points, 99) as $chosen) {
            $this->assertContains($chosen['id'], $ids);
        }
    }

    public function testPixelBounds_HoldsAllPoints(): void
    {
        $points = [
            ['lat' => 45.0, 'lon' => -122.0],
            ['lat' => 45.3, 'lon' => -122.5],
            ['lat' => 44.8, 'lon' => -121.9],
        ];
        $b = mapCardPixelBounds($points, 6);
        foreach ($points as $p) {
            [$px, $py] = mapCardPointPixel($p['lat'], $p['lon'], 6);
            $this->assertGreaterThanOrEqual($b['minX'], $px);
            $this->assertLessThanOrEqual($b['maxX'], $px);
            $this->assertGreaterThanOrEqual($b['minY'], $py);
            $this->assertLessThanOrEqual($b['maxY'], $py);
        }
    }
}
