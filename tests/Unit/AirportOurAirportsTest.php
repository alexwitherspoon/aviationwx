<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/airport-ourairports.php';

class AirportOurAirportsTest extends TestCase
{
    public function testIsValidOurAirportsIdentFormat_AcceptsCommonPatterns(): void
    {
        $this->assertTrue(isValidOurAirportsIdentFormat('CYAV'));
        $this->assertTrue(isValidOurAirportsIdentFormat('US-4027'));
        $this->assertTrue(isValidOurAirportsIdentFormat('ID35'));
        $this->assertTrue(isValidOurAirportsIdentFormat('03ID'));
    }

    public function testIsValidOurAirportsIdentFormat_RejectsInvalid(): void
    {
        $this->assertFalse(isValidOurAirportsIdentFormat(''));
        $this->assertFalse(isValidOurAirportsIdentFormat('-US'));
        $this->assertFalse(isValidOurAirportsIdentFormat('US-'));
        $this->assertFalse(isValidOurAirportsIdentFormat('bad ident'));
    }

    public function testCacheLookupIdents_PrefersExplicitOurAirportsIdent(): void
    {
        $idents = ourAirportsCacheLookupIdentsForAirport('45ranch', [
            'ourairports_ident' => 'US-4027',
            'icao' => 'ZZZZ',
            'faa' => 'ZZZ1',
        ]);

        $this->assertSame(['US-4027', 'ZZZZ', 'ZZZ1', '45RANCH'], $idents);
    }

    public function testCacheLookupIdents_CastsScalarIcaoFaa(): void
    {
        $idents = ourAirportsCacheLookupIdentsForAirport('45ranch', [
            'icao' => 1234,
            'faa' => 5678,
        ]);

        $this->assertSame(['1234', '5678', '45RANCH'], $idents);
    }

    public function testGetOurAirportsIdentFromAirportConfig_NormalizesCase(): void
    {
        $this->assertSame('US-4027', getOurAirportsIdentFromAirportConfig([
            'ourairports_ident' => ' us-4027 ',
        ]));
        $this->assertNull(getOurAirportsIdentFromAirportConfig([]));
    }

    public function testGetOurAirportsNumericIdFromAirportConfig(): void
    {
        $this->assertSame(344311, getOurAirportsNumericIdFromAirportConfig([
            'ourairports_id' => 344311,
        ]));
        $this->assertNull(getOurAirportsNumericIdFromAirportConfig([
            'ourairports_id' => 0,
        ]));
        $this->assertNull(getOurAirportsNumericIdFromAirportConfig([
            'ourairports_id' => '1.2',
        ]));
        $this->assertNull(getOurAirportsNumericIdFromAirportConfig([
            'ourairports_id' => '1e3',
        ]));
        $this->assertNull(getOurAirportsNumericIdFromAirportConfig([
            'ourairports_id' => true,
        ]));
    }
}
