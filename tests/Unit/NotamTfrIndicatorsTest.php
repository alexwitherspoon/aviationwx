<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class NotamTfrIndicatorsTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/notam/tfr-indicators.php';
        require_once dirname(__DIR__, 2) . '/lib/notam/geo-prefilter.php';
        require_once dirname(__DIR__, 2) . '/lib/notam/filter.php';
    }

    public function testTextMayIndicateTfr_RestrictedAndAirspacePair(): void
    {
        self::assertTrue(notamTextMayIndicateTfr('RESTRICTED AREA WITHIN 15NM OF AIRPORT AIRSPACE'));
    }

    public function testPrefilterParity_MatchesIsTfrForSampleText(): void
    {
        $text = 'ZLC UT..AIRSPACE OGDEN, UT..TEMPORARY FLIGHT RESTRICTIONS WITHIN AN AREA';
        $xml = '<notam><translation><simpleText>' . $text . '</simpleText></translation></notam>';

        self::assertSame(isTfr(['text' => $text]), notamAixmXmlMayBeTfr($xml));
        self::assertTrue(notamTextMayIndicateTfr($text));
    }

    public function testPrefilterParity_BothRejectRunwayClosure(): void
    {
        $text = 'RWY 15/33 CLSD';
        $xml = '<notam><translation><simpleText>' . $text . '</simpleText></translation></notam>';

        self::assertSame(isTfr(['text' => $text]), notamAixmXmlMayBeTfr($xml));
        self::assertFalse(isTfr(['text' => $text]));
    }
}
