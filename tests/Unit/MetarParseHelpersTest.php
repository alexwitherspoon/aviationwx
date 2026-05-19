<?php

declare(strict_types=1);

namespace AviationWX\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for METAR parse helpers in `lib/weather/adapter/metar-v1.php`.
 */
final class MetarParseHelpersTest extends TestCase
{
    public function testMetarParseGustSpeedKts_RawObWithGGroup_ReturnsGust(): void
    {
        require_once __DIR__ . '/../../lib/weather/adapter/metar-v1.php';

        $gust = metarParseGustSpeedKts('METAR KORD 181951Z 22016G27KT 10SM', []);
        $this->assertSame(27, $gust);
    }

    public function testMetarParseGustSpeedKts_WgstOnly_ReturnsWgst(): void
    {
        require_once __DIR__ . '/../../lib/weather/adapter/metar-v1.php';

        $gust = metarParseGustSpeedKts(
            'METAR KZZZ 181500Z AUTO 18015KT 10SM CLR',
            ['wgst' => 22]
        );
        $this->assertSame(22, $gust);
    }

    public function testMetarParseGustSpeedKts_RawObGGroup_PreferredOverWgst(): void
    {
        require_once __DIR__ . '/../../lib/weather/adapter/metar-v1.php';

        $gust = metarParseGustSpeedKts(
            'METAR KZZZ 181448Z 16011G17KT 10SM',
            ['wgst' => 22]
        );
        $this->assertSame(17, $gust);
    }

    public function testMetarParseGustSpeedKts_NoGust_ReturnsNull(): void
    {
        require_once __DIR__ . '/../../lib/weather/adapter/metar-v1.php';

        $this->assertNull(metarParseGustSpeedKts('METAR KZZZ 181500Z 18015KT 10SM', []));
    }
}
