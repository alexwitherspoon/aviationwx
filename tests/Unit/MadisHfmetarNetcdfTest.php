<?php
/**
 * Unit Tests for the classic netCDF reader and MADIS HFMETAR decoder
 *
 * Exercises the reader against a real MADIS HFMETAR hourly file (sample
 * fixture: 2026-11-20 23:00 UTC, 12,239 records, 1,026 stations). The
 * fixture is gzipped; tests decompress it in memory.
 *
 * @package AviationWX\Tests\Unit
 */

use PHPUnit\Framework\TestCase;
use AviationWX\Madis\NetcdfClassic;
use AviationWX\Madis\NetcdfFormatException;

require_once __DIR__ . '/../../lib/madis-hfmetar/netcdf-classic.php';
require_once __DIR__ . '/../../lib/madis-hfmetar/madis-hfmetar.php';
require_once __DIR__ . '/../../lib/weather/adapter/madis-hfmetar-v1.php';

class MadisHfmetarNetcdfTest extends TestCase
{
    private string $fixturePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixturePath = __DIR__ . '/../Fixtures/madis/hfmetar-sample.gz';
    }

    /**
     * Load the gzipped fixture and decompress it in memory.
     */
    private function fixtureBytes(): string
    {
        $bytes = file_get_contents($this->fixturePath);
        $this->assertNotFalse($bytes, 'fixture file must exist');
        $raw = gzdecode($bytes);
        $this->assertNotFalse($raw, 'fixture must decompress');

        return $raw;
    }

    private function fixtureNetcdf(): NetcdfClassic
    {
        return new NetcdfClassic($this->fixtureBytes());
    }

    /**
     * Reader rejects a file that is not netCDF
     */
    public function testNetcdfClassic_BadMagic_Throws(): void
    {
        $this->expectException(NetcdfFormatException::class);
        new NetcdfClassic(str_repeat('x', 64));
    }

    /**
     * Reader rejects CDF-2 (64-bit offset) files rather than misreading them
     */
    public function testNetcdfClassic_Cdf2Magic_Throws(): void
    {
        $this->expectException(NetcdfFormatException::class);
        new NetcdfClassic("CDF\x02" . str_repeat("\x00", 60));
    }

    /**
     * Reader rejects a truncated header
     */
    public function testNetcdfClassic_TooShort_Throws(): void
    {
        $this->expectException(NetcdfFormatException::class);
        new NetcdfClassic('CDF');
    }

    /**
     * Record count matches the fixture's 12,239 records
     */
    public function testNetcdfClassic_RecordCount_MatchesFixture(): void
    {
        $this->assertSame(12239, $this->fixtureNetcdf()->recordCount());
    }

    /**
     * The unlimited (record) dimension is present with length 0
     */
    public function testNetcdfClassic_Dims_IncludeRecNumAsRecordDimension(): void
    {
        $dims = $this->fixtureNetcdf()->dims();
        $this->assertArrayHasKey('recNum', $dims);
        $this->assertSame(0, $dims['recNum']);
        $this->assertSame(512, $dims['maxLDADmessageLen']);
    }

    /**
     * stationId column decodes to fixed-width station ICAOs
     */
    public function testNetcdfClassic_StationIdColumn_ReturnsTrimmedIcaos(): void
    {
        $ids = $this->fixtureNetcdf()->readColumnString('stationId');
        $this->assertCount(12239, $ids);
        $this->assertSame('PASH', $ids[0]);
        $this->assertSame('KSPB', trim($ids[array_search('KSPB', $ids, true)]));
    }

    /**
     * observationTime column decodes to Unix timestamps (double)
     */
    public function testNetcdfClassic_ObservationTimeColumn_IsNumeric(): void
    {
        $times = $this->fixtureNetcdf()->readColumn('observationTime');
        $this->assertCount(12239, $times);
        $this->assertIsFloat($times[0]);
        $this->assertGreaterThan(1700000000, $times[0]);
    }

    /**
     * Missing sentinels come back as scalars and are detected
     */
    public function testNetcdfClassic_IsMissing_DetectsFillValue(): void
    {
        $netcdf = $this->fixtureNetcdf();
        $sentinels = $netcdf->missingValues('windGust');
        $this->assertContains(3.4028234663852886e38, $sentinels, '', 0.0);
        $this->assertTrue($netcdf->isMissing('windGust', 3.4028234663852886e38));
        $this->assertFalse($netcdf->isMissing('windGust', 12.5));
    }

    /**
     * Decoding the sample file returns all 12 five-minute marks for KSPB
     */
    public function testMadisHfmetarDecode_Kspb_ReturnsTwelveObservations(): void
    {
        $observations = madisHfmetarDecodeBytes($this->fixtureBytes(), 'KSPB');
        $this->assertNotNull($observations);
        $this->assertCount(12, $observations);
    }

    /**
     * Observations are sorted ascending by observation time
     */
    public function testMadisHfmetarDecode_Kspb_SortedAscending(): void
    {
        $observations = madisHfmetarDecodeBytes($this->fixtureBytes(), 'KSPB');
        $times = array_column($observations, 'obs_time');
        $sorted = $times;
        sort($sorted);
        $this->assertSame($sorted, $times);
    }

    /**
     * KSPB was calm and 7.0C on the sample hour; units are converted
     */
    public function testMadisHfmetarDecode_Kspb_UnitsConverted(): void
    {
        $observations = madisHfmetarDecodeBytes($this->fixtureBytes(), 'KSPB');
        $first = $observations[0];
        // 280.15 K - 273.15 = 7.0 C
        $this->assertEqualsWithDelta(7.0, $first['temperature_c'], 0.01);
        // altimeter 101964 Pa -> 30.11 inHg
        $this->assertEqualsWithDelta(30.11, $first['altimeter_inhg'], 0.01);
        // visibility 16093.44 m -> 10 SM
        $this->assertEqualsWithDelta(10.0, $first['visibility_sm'], 0.01);
        // calm wind, no gust
        $this->assertSame(0.0, $first['wind_speed_kts']);
        $this->assertNull($first['gust_speed_kts']);
        $this->assertEqualsWithDelta(0.0, $first['wind_direction_deg'], 0.001);
    }

    /**
     * KMMV has 11 marks and shows a wind change mid-hour
     */
    public function testMadisHfmetarDecode_Kmmv_WindAppears(): void
    {
        $observations = madisHfmetarDecodeBytes($this->fixtureBytes(), 'KMMV');
        $this->assertNotNull($observations);
        $this->assertCount(11, $observations);
        $speeds = array_column($observations, 'wind_speed_kts');
        // Wind appears mid-hour at ~3 kts (float32 source precision)
        $this->assertGreaterThan(2.9, max($speeds));
    }

    /**
     * Station absent from the file returns null
     */
    public function testMadisHfmetarDecode_UnknownStation_ReturnsNull(): void
    {
        $this->assertNull(madisHfmetarDecodeBytes($this->fixtureBytes(), 'KZZZ'));
    }

    /**
     * Garbage bytes return null instead of throwing
     */
    public function testMadisHfmetarDecode_GarbageBytes_ReturnsNull(): void
    {
        $this->assertNull(madisHfmetarDecodeBytes(str_repeat("\x00", 64), 'KSPB'));
    }

    /**
     * Adapter exposes the source type and 5-minute update frequency
     */
    public function testMadisAdapter_SourceContract(): void
    {
        $this->assertSame('madis_hfmetar', MadisHfmetarAdapter::getSourceType());
        $this->assertSame(300, MadisHfmetarAdapter::getTypicalUpdateFrequency());
        $this->assertSame(3600, MadisHfmetarAdapter::getMaxAcceptableAge());
        $this->assertTrue(MadisHfmetarAdapter::providesField('wind_speed'));
        $this->assertTrue(MadisHfmetarAdapter::providesField('wind_direction'));
        $this->assertFalse(MadisHfmetarAdapter::providesField('ceiling'));
    }

    /**
     * buildUrl validates the station and returns the current UTC hour file
     */
    public function testMadisAdapter_BuildUrl_ValidStation_ReturnsMadisUrl(): void
    {
        $url = MadisHfmetarAdapter::buildUrl(['station_id' => 'kspb']);
        $this->assertNotNull($url);
        $this->assertStringContainsString('madis-data.ncep.noaa.gov', $url);
        $this->assertStringContainsString('LDAD/hfmetar/netCDF/', $url);
        $this->assertMatchesRegularExpression('/\d{8}_\d{4}\.gz$/', $url);
    }

    /**
     * buildUrl rejects missing or malformed station ids
     */
    public function testMadisAdapter_BuildUrl_InvalidStation_ReturnsNull(): void
    {
        $this->assertNull(MadisHfmetarAdapter::buildUrl([]));
        $this->assertNull(MadisHfmetarAdapter::buildUrl(['station_id' => 'k/../evil']));
    }

    /**
     * parseResponse builds a valid snapshot from the fixture file bytes
     */
    public function testMadisAdapter_ParseResponse_ReturnsLatestSnapshot(): void
    {
        $bytes = $this->fixtureBytes();
        $snapshot = MadisHfmetarAdapter::parseResponse($bytes, ['station_id' => 'kspb']);
        $this->assertNotNull($snapshot);
        $this->assertTrue($snapshot->isValid);
        $this->assertSame('madis_hfmetar', $snapshot->source);
        $this->assertSame('KSPB', $snapshot->stationId);
        $this->assertEqualsWithDelta(7.0, $snapshot->temperature->value, 0.01);
        $this->assertSame(0.0, $snapshot->wind->speed->value);
        $this->assertTrue($snapshot->wind->direction->hasValue());
        $this->assertNull($snapshot->wind->gust->value, 'gust is missing for calm KSPB');
        // The latest mark is 23:55 UTC on 2026-11-20
        $this->assertSame(gmmktime(23, 55, 0, 11, 20, 2026), $snapshot->getFieldObservationTime('temperature'));
    }

    /**
     * parseResponse supports a pre-sliced file path via config
     */
    public function testMadisAdapter_ParseResponse_FilePathConfig_Works(): void
    {
        $snapshot = MadisHfmetarAdapter::parseResponse('', [
            'station_id' => 'kspb',
            'file_path' => $this->fixturePath,
        ]);
        $this->assertNotNull($snapshot);
        $this->assertTrue($snapshot->isValid);
    }

    /**
     * parseResponse degrades to an empty snapshot for unknown stations
     */
    public function testMadisAdapter_ParseResponse_UnknownStation_ReturnsEmpty(): void
    {
        $snapshot = MadisHfmetarAdapter::parseResponse($this->fixtureBytes(), ['station_id' => 'kzzz']);
        $this->assertNotNull($snapshot);
        $this->assertFalse($snapshot->isValid);
    }
}
