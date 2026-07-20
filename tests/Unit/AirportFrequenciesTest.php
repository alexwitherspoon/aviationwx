<?php
/**
 * Unit tests for NASR FRQ parsing and cache lookup.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Helpers/LoadsNasrFrqFixtureCacheTrait.php';
require_once __DIR__ . '/../../lib/nasr/frequencies-parse.php';
require_once __DIR__ . '/../../lib/nasr/frequencies-cache.php';
require_once __DIR__ . '/../../lib/airport-frequencies.php';

class NasrFrequenciesParseTest extends TestCase
{
    use LoadsNasrFrqFixtureCacheTrait;

    protected function tearDown(): void
    {
        $this->tearDownNasrFrqFixtureCache();
    }

    public function testParseFrqCsvMapsPilotFacingRoles(): void
    {
        $parsed = nasrParseFrqCsvFile($this->nasrFrqFixtureDirectory() . '/FRQ.csv');

        $this->assertArrayHasKey('69V', $parsed['airports']);
        $this->assertSame('122.8', $parsed['airports']['69V']['ctaf']);
        $this->assertSame('122.8', $parsed['airports']['69V']['unicom']);

        $this->assertArrayHasKey('HIO', $parsed['airports']);
        $this->assertSame('119.3', $parsed['airports']['HIO']['tower']);
        $this->assertSame('121.7', $parsed['airports']['HIO']['ground']);
        $this->assertSame('127.65', $parsed['airports']['HIO']['atis']);
        $this->assertSame('122.95', $parsed['airports']['HIO']['unicom']);
    }

    public function testGetNasrFrequenciesForConfigResolvesFaaIdentifier(): void
    {
        $this->loadNasrFrqFixtureCache();

        $freqs = getNasrFrequenciesForConfig([
            'id' => '69v',
            'faa' => '69V',
        ]);

        $this->assertSame('122.8', $freqs['ctaf']);
        $this->assertSame('122.8', $freqs['unicom']);
    }
}

class AirportFrequenciesTest extends TestCase
{
    use LoadsNasrFrqFixtureCacheTrait;

    protected function tearDown(): void
    {
        $this->tearDownNasrFrqFixtureCache();
        resetOurAirportsFrequenciesCacheMemo();
    }

    public function testCollapseDuplicateRolesRemovesUnicomWhenSameAsCtaf(): void
    {
        $collapsed = collapseDuplicateAirportFrequencyRoles([
            'ctaf' => '122.8',
            'unicom' => '122.800',
        ]);

        $this->assertSame(['ctaf' => '122.8'], $collapsed);
    }

    public function testCollapseDuplicateRolesRemovesCtafWhenSameAsTower(): void
    {
        $collapsed = collapseDuplicateAirportFrequencyRoles([
            'tower' => '119.3',
            'ctaf' => '119.300',
        ]);

        $this->assertSame(['tower' => '119.3'], $collapsed);
    }

    public function testCollapseKeepsDistinctUnicomAndCtaf(): void
    {
        $collapsed = collapseDuplicateAirportFrequencyRoles([
            'ctaf' => '122.9',
            'unicom' => '122.95',
        ]);

        $this->assertSame([
            'ctaf' => '122.9',
            'unicom' => '122.95',
        ], $collapsed);
    }

    public function testMergedFrequenciesPreferConfigOverNasr(): void
    {
        $this->loadNasrFrqFixtureCache();

        $merged = getMergedAirportFrequencies('69v', [
            'faa' => '69V',
            'frequencies' => [
                'ctaf' => '123.0',
            ],
        ]);

        $this->assertSame('123', $merged['ctaf']);
        $this->assertSame('122.8', $merged['unicom']);
    }

    public function testMergedFrequenciesFromNasrCollapseCtafUnicomDuplicate(): void
    {
        $this->loadNasrFrqFixtureCache();

        $merged = getMergedAirportFrequencies('69v', [
            'faa' => '69V',
        ]);

        $this->assertSame(['ctaf' => '122.8'], $merged);
    }

    public function testMergedFrequenciesFromNasrForToweredAirport(): void
    {
        $this->loadNasrFrqFixtureCache();

        $merged = getMergedAirportFrequencies('khio', [
            'icao' => 'KHIO',
            'faa' => 'HIO',
        ]);

        $this->assertSame([
            'tower' => '119.3',
            'ground' => '121.7',
            'unicom' => '122.95',
            'atis' => '127.65',
        ], $merged);
    }

    public function testMergedFrequenciesUseOurAirportsWhenNasrMissing(): void
    {
        setOurAirportsFrequenciesCacheForTesting([
            'CYAV' => [
                'ctaf' => '123.2',
                'unicom' => '123.2',
            ],
        ]);

        $merged = getMergedAirportFrequencies('cyav', [
            'icao' => 'CYAV',
        ]);

        $this->assertSame(['ctaf' => '123.2'], $merged);
    }

    public function testParseOurAirportsFrequenciesCsvDedupesRoles(): void
    {
        $csv = <<<CSV
"id","airport_ref","airport_ident","type","description","frequency_mhz"
1,1,"TEST","CTAF","CTAF",122.9
2,1,"TEST","UNIC","UNICOM",122.9
CSV;

        $parsed = parseOurAirportsFrequenciesCsv($csv);

        $this->assertSame(['ctaf' => '122.9'], $parsed['TEST']);
    }
}
