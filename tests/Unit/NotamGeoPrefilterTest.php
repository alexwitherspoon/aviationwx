<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class NotamGeoPrefilterTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/notam/geo-prefilter.php';
    }

    public function testMayBeTfr_KeepsTemporaryFlightRestrictionText(): void
    {
        $xml = '<notam><text>TEMPORARY FLIGHT RESTRICTION WI 5 NM RADIUS</text></notam>';

        self::assertTrue(notamAixmXmlMayBeTfr($xml));
    }

    public function testMayBeTfr_KeepsTfrAbbreviation(): void
    {
        $xml = '<notam><translation><simpleText>!FDC 1/1234 TFR WI 10NM</simpleText></translation></notam>';

        self::assertTrue(notamAixmXmlMayBeTfr($xml));
    }

    public function testMayBeTfr_SkipsRunwayClosureWithoutTfrIndicators(): void
    {
        $xml = '<notam><translation><simpleText>RWY 15/33 CLSD</simpleText></translation></notam>';

        self::assertFalse(notamAixmXmlMayBeTfr($xml));
    }

    public function testMayBeTfr_SkipsObstacleLightingNoise(): void
    {
        $xml = '<notam><translation><simpleText>OBST TOWER LGT U/S</simpleText></translation></notam>';

        self::assertFalse(notamAixmXmlMayBeTfr($xml));
    }

    public function testMayBeTfr_KeepsRestrictedAndAirspacePair(): void
    {
        $xml = '<notam><text>RESTRICTED AREA AIRSPACE WI 10NM</text></notam>';

        self::assertTrue(notamAixmXmlMayBeTfr($xml));
    }

    public function testFilterGeoXmlForTfrParsing_KeepsOnlyTfrRows(): void
    {
        $tfr = '<notam><text>TFR WI 5NM</text></notam>';
        $closure = '<notam><text>RWY 15/33 CLSD</text></notam>';

        $filtered = notamFilterGeoXmlForTfrParsing([$closure, $tfr, '']);

        self::assertSame([$tfr], $filtered);
    }

    public function testFilterGeoXmlForTfrParsing_SkipsNonStringElements(): void
    {
        $tfr = '<notam><text>TFR WI 5NM</text></notam>';

        $filtered = notamFilterGeoXmlForTfrParsing([123, $tfr]);

        self::assertSame([$tfr], $filtered);
    }

    public function testBuildGeoQueryParams_IncludesAirspaceFeature(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/constants.php';

        $params = notamBuildGeoQueryParams(45.5, -122.8, NOTAM_GEO_RADIUS_DEFAULT);

        self::assertSame('AIRSPACE', $params['feature']);
        self::assertSame(45.5, $params['latitude']);
        self::assertSame(-122.8, $params['longitude']);
        self::assertSame(NOTAM_GEO_RADIUS_DEFAULT, $params['radius']);
    }
}
