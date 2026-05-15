<?php

declare(strict_types=1);

require_once __DIR__ . '/../../lib/production-health-check-airports.php';

use PHPUnit\Framework\TestCase;

/**
 * @covers ::productionHealthCheckPickSampleAirports
 */
final class ProductionHealthCheckAirportsTest extends TestCase
{
    public function testProductionHealthCheckPickSampleAirports_NullList_ReturnsFallbackRepeated(): void
    {
        $out = productionHealthCheckPickSampleAirports(null, 'kabc', 3);
        $this->assertSame(['kabc', 'kabc', 'kabc'], $out);
    }

    public function testProductionHealthCheckPickSampleAirports_EmptyAirportsList_ReturnsFallbackRepeated(): void
    {
        $out = productionHealthCheckPickSampleAirports(['success' => true, 'airports' => []], 'kdef', 2);
        $this->assertSame(['kdef', 'kdef'], $out);
    }

    public function testProductionHealthCheckPickSampleAirports_EnoughBothCapable_ReturnsOnlyBothCapable(): void
    {
        $list = [
            'success' => true,
            'airports' => [
                ['id' => 'aaa', 'has_weather' => true, 'has_webcams' => false],
                ['id' => 'bbb', 'has_weather' => true, 'has_webcams' => true],
                ['id' => 'ccc', 'has_weather' => true, 'has_webcams' => true],
                ['id' => 'ddd', 'has_weather' => true, 'has_webcams' => true],
            ],
        ];
        $out = productionHealthCheckPickSampleAirports($list, 'kspb', 3);
        $this->assertCount(3, $out);
        sort($out);
        $this->assertSame(['bbb', 'ccc', 'ddd'], $out);
    }

    public function testProductionHealthCheckPickSampleAirports_FewBothCapable_AlwaysIncludesAllBoth(): void
    {
        $list = [
            'success' => true,
            'airports' => [
                ['id' => 'w1', 'has_weather' => true, 'has_webcams' => false],
                ['id' => 'w2', 'has_weather' => true, 'has_webcams' => false],
                ['id' => 'w3', 'has_weather' => true, 'has_webcams' => false],
                ['id' => 'w4', 'has_weather' => true, 'has_webcams' => false],
                ['id' => 'w5', 'has_weather' => true, 'has_webcams' => false],
                ['id' => 'b1', 'has_weather' => true, 'has_webcams' => true],
                ['id' => 'b2', 'has_weather' => true, 'has_webcams' => true],
            ],
        ];
        for ($i = 0; $i < 40; $i++) {
            $out = productionHealthCheckPickSampleAirports($list, 'kspb', 3);
            $this->assertCount(3, $out);
            $this->assertContains('b1', $out);
            $this->assertContains('b2', $out);
        }
    }

    public function testProductionHealthCheckPickSampleAirports_NoWeatherRows_NeverSamplesThemWhenPadding(): void
    {
        $list = [
            'success' => true,
            'airports' => [
                ['id' => 'nowx', 'has_weather' => false, 'has_webcams' => true],
                ['id' => 'wx1', 'has_weather' => true, 'has_webcams' => false],
            ],
        ];
        for ($i = 0; $i < 25; $i++) {
            $out = productionHealthCheckPickSampleAirports($list, 'kspb', 5);
            $this->assertCount(5, $out);
            foreach ($out as $id) {
                $this->assertSame('wx1', $id);
            }
        }
    }

    public function testProductionHealthCheckPickSampleAirports_SmallPool_PadsWithRepeats(): void
    {
        $list = [
            'success' => true,
            'airports' => [
                ['id' => 'only', 'has_weather' => true, 'has_webcams' => true],
            ],
        ];
        $out = productionHealthCheckPickSampleAirports($list, 'kspb', 3);
        $this->assertSame(['only', 'only', 'only'], $out);
    }

    public function testProductionHealthCheckPickSampleAirports_ZeroCount_ClampedToOne(): void
    {
        $list = [
            'success' => true,
            'airports' => [
                ['id' => 'x', 'has_weather' => true, 'has_webcams' => true],
            ],
        ];
        $out = productionHealthCheckPickSampleAirports($list, 'kspb', 0);
        $this->assertCount(1, $out);
        $this->assertSame('x', $out[0]);
    }
}
