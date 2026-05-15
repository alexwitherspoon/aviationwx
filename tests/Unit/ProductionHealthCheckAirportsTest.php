<?php

declare(strict_types=1);

require_once __DIR__ . '/../../lib/production-health-check-airports.php';

use PHPUnit\Framework\TestCase;

/**
 * @covers ::production_health_check_pick_sample_airports
 */
final class ProductionHealthCheckAirportsTest extends TestCase
{
    public function testNullListUsesFallbackRepeated(): void
    {
        $out = production_health_check_pick_sample_airports(null, 'kabc', 3);
        $this->assertSame(['kabc', 'kabc', 'kabc'], $out);
    }

    public function testEmptyAirportsArrayUsesFallback(): void
    {
        $out = production_health_check_pick_sample_airports(['success' => true, 'airports' => []], 'kdef', 2);
        $this->assertSame(['kdef', 'kdef'], $out);
    }

    public function testPrefersBothWeatherAndWebcamsWhenEnoughExist(): void
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
        $out = production_health_check_pick_sample_airports($list, 'kspb', 3);
        $this->assertCount(3, $out);
        sort($out);
        $this->assertSame(['bbb', 'ccc', 'ddd'], $out);
    }

    public function testTakesAllBothCapableBeforeWeatherOnlyFillers(): void
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
            $out = production_health_check_pick_sample_airports($list, 'kspb', 3);
            $this->assertCount(3, $out);
            $this->assertContains('b1', $out);
            $this->assertContains('b2', $out);
        }
    }

    public function testPadsWithRepeatWhenPoolSmallerThanCount(): void
    {
        $list = [
            'success' => true,
            'airports' => [
                ['id' => 'only', 'has_weather' => true, 'has_webcams' => true],
            ],
        ];
        $out = production_health_check_pick_sample_airports($list, 'kspb', 3);
        $this->assertSame(['only', 'only', 'only'], $out);
    }

    public function testCountMinimumOne(): void
    {
        $list = [
            'success' => true,
            'airports' => [
                ['id' => 'x', 'has_weather' => true, 'has_webcams' => true],
            ],
        ];
        $out = production_health_check_pick_sample_airports($list, 'kspb', 0);
        $this->assertCount(1, $out);
        $this->assertSame('x', $out[0]);
    }
}
