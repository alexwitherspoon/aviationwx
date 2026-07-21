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
        $this->assertSame('118.1', $parsed['airports']['HIO']['approach']);
        $this->assertSame('118.1', $parsed['airports']['HIO']['departure']);
        $this->assertSame('119.3', $parsed['airports']['HIO']['ctaf']);
    }

    public function testNasrDescribeFreqUseMapping_ApproachDepartureCombined_ReturnsBothRoles(): void
    {
        $mapping = nasrDescribeFreqUseMapping('APCH/P DEP/P');

        $this->assertNotNull($mapping);
        $this->assertSame(['approach', 'departure'], $mapping['roles']);
        $this->assertSame(NASR_FREQ_MAP_TIER_PRIMARY, $mapping['tier']);
    }

    public function testNasrDescribeFreqUseMapping_InstrumentApproachBackup_ReturnsNull(): void
    {
        $this->assertNull(nasrDescribeFreqUseMapping('APCH/P IC'));
    }

    public function testNasrDescribeFreqUseMapping_AwosPattern_ReturnsAwosRole(): void
    {
        $mapping = nasrDescribeFreqUseMapping('AWOS-3 PVT');

        $this->assertNotNull($mapping);
        $this->assertSame(['awos'], $mapping['roles']);
    }

    public function testParseFrqCsv_ApproachDepartureClearanceAndWeatherRoles(): void
    {
        $parsed = nasrParseFrqCsvFile($this->nasrFrqFixtureDirectory() . '/FRQ.csv');

        $this->assertSame('125.3', $parsed['airports']['SPB']['approach']);
        $this->assertSame('125.3', $parsed['airports']['SPB']['departure']);
        $this->assertSame('121.9', $parsed['airports']['SPB']['clearance']);
        $this->assertSame('118.275', $parsed['airports']['LLJ']['asos']);
        $this->assertSame('119.025', $parsed['airports']['AWS1']['awos']);
        $this->assertSame('121.75', $parsed['airports']['CLR1']['clearance']);
    }

    public function testParseFrqCsv_SecondaryTowerFillsWhenPrimaryMissing(): void
    {
        $parsed = nasrParseFrqCsvFile($this->nasrFrqFixtureDirectory() . '/FRQ.csv');

        $this->assertSame('119.5', $parsed['airports']['SEC1']['tower']);
    }

    public function testParseFrqCsv_PrimaryTowerWinsOverSecondary(): void
    {
        $parsed = nasrParseFrqCsvFile($this->nasrFrqFixtureDirectory() . '/FRQ.csv');

        $this->assertSame('120.1', $parsed['airports']['PRI1']['tower']);
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

    protected function setUp(): void
    {
        parent::setUp();
        setOurAirportsFrequenciesCacheForTesting([]);
    }

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
            'approach' => '118.1',
            'departure' => '118.1',
        ], $merged);
    }

    public function testMergedFrequenciesFromNasr_ApproachDepartureClearanceRoles(): void
    {
        $this->loadNasrFrqFixtureCache();

        $merged = getMergedAirportFrequencies('kspb', [
            'icao' => 'KSPB',
            'faa' => 'SPB',
        ]);

        $this->assertSame([
            'approach' => '125.3',
            'departure' => '125.3',
            'clearance' => '121.9',
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

    public function testGetMergedAirportFrequencies_NasrAndOurAirportsBothPresent_PrefersNasr(): void
    {
        $this->loadNasrFrqFixtureCache();

        setOurAirportsFrequenciesCacheForTesting([
            'CYVR' => [
                'tower' => '119.0',
                'ground' => '121.0',
                'atis' => '127.0',
            ],
        ]);

        $merged = getMergedAirportFrequencies('cyvr', [
            'icao' => 'CYVR',
        ]);

        $this->assertSame([
            'tower' => '118.7',
            'ground' => '121.7',
            'atis' => '124.6',
        ], $merged);
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
