<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/notam/parser.php';

final class NotamParserTest extends TestCase
{
    public function testParseNotamXml_KspbScenario86_ParsesRunwayClosureFields(): void
    {
        $xml = (string) file_get_contents(__DIR__ . '/../Fixtures/notam/kspb-runway-closure-scenario86.xml');
        $notam = parseNotamXml($xml);

        self::assertIsArray($notam);
        self::assertSame('86', $notam['scenario'] ?? null);
        self::assertTrue($notam['aixm_runway_event'] ?? false);
        self::assertSame('KSPB', $notam['location']);
        self::assertSame('RWY 15/33 CLSD', $notam['text']);
        self::assertSame('06/001/2026', $notam['id']);
        self::assertSame('2026-06-08T14:00:00.000Z', $notam['start_time_utc']);
    }

    public function testNotamResolvePublicIdFromAixmFields_DomSimpleText_ReturnsDomId(): void
    {
        self::assertSame(
            '06/001/2026',
            notamResolvePublicIdFromAixmFields('', '1', '2026', '!SPB 06/001 SPB RWY 15/33 CLSD')
        );
    }

    public function testNotamResolvePublicIdFromAixmFields_IcaoSeries_ReturnsIcaoId(): void
    {
        self::assertSame(
            'A1234/2026',
            notamResolvePublicIdFromAixmFields('A', '1234', '2026', '')
        );
    }
}
