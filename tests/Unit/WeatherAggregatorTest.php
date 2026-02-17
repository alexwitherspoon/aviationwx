<?php
/**
 * Unit tests for WeatherAggregator
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

// Include the new classes
require_once __DIR__ . '/../../lib/weather/data/WeatherReading.php';
require_once __DIR__ . '/../../lib/weather/data/WindGroup.php';
require_once __DIR__ . '/../../lib/weather/data/WeatherSnapshot.php';
require_once __DIR__ . '/../../lib/weather/AggregationPolicy.php';
require_once __DIR__ . '/../../lib/weather/WeatherAggregator.php';

// Import namespaced classes
use AviationWX\Weather\Data\WeatherReading;
use AviationWX\Weather\Data\WindGroup;
use AviationWX\Weather\Data\WeatherSnapshot;
use AviationWX\Weather\AggregationPolicy;
use AviationWX\Weather\WeatherAggregator;

class WeatherAggregatorTest extends TestCase {
    
    private int $now;
    
    protected function setUp(): void {
        $this->now = time();
    }
    
    /**
     * Helper to create a snapshot with specified fields
     *
     * @param string $source Source identifier (e.g., 'tempest', 'metar')
     * @param array $fields Field values keyed by name
     * @param int|null $obsTime Observation timestamp
     * @param string|null $metarStationId For METAR source: station ICAO (e.g., KSPB). Null = not METAR or unknown.
     * @param string|null $stationId For ICAO-keyed sources (swob, nws, awosnet): station ICAO for attribution.
     */
    private function createSnapshot(
        string $source,
        array $fields,
        ?int $obsTime = null,
        ?string $metarStationId = null,
        ?string $stationId = null
    ): WeatherSnapshot {
        $obsTime = $obsTime ?? $this->now;
        
        return new WeatherSnapshot(
            source: $source,
            fetchTime: $this->now,
            temperature: isset($fields['temperature']) 
                ? WeatherReading::from($fields['temperature'], $source, $obsTime)
                : WeatherReading::null($source),
            dewpoint: isset($fields['dewpoint'])
                ? WeatherReading::from($fields['dewpoint'], $source, $obsTime)
                : WeatherReading::null($source),
            humidity: isset($fields['humidity'])
                ? WeatherReading::from($fields['humidity'], $source, $obsTime)
                : WeatherReading::null($source),
            pressure: isset($fields['pressure'])
                ? WeatherReading::from($fields['pressure'], $source, $obsTime)
                : WeatherReading::null($source),
            precipAccum: isset($fields['precip_accum'])
                ? WeatherReading::from($fields['precip_accum'], $source, $obsTime)
                : WeatherReading::null($source),
            wind: isset($fields['wind_speed']) && isset($fields['wind_direction'])
                ? WindGroup::from(
                    $fields['wind_speed'],
                    $fields['wind_direction'],
                    $fields['gust_speed'] ?? null,
                    $source,
                    $obsTime
                )
                : WindGroup::empty(),
            visibility: isset($fields['visibility'])
                ? WeatherReading::from($fields['visibility'], $source, $obsTime)
                : WeatherReading::null($source),
            ceiling: isset($fields['ceiling'])
                ? WeatherReading::from($fields['ceiling'], $source, $obsTime)
                : WeatherReading::null($source),
            cloudCover: isset($fields['cloud_cover'])
                ? WeatherReading::from($fields['cloud_cover'], $source, $obsTime)
                : WeatherReading::null($source),
            rawMetar: $fields['raw_metar'] ?? null,
            isValid: true,
            metarStationId: $metarStationId,
            stationId: $stationId
        );
    }
    
    public function testEmptySnapshotsReturnsNulls(): void {
        $aggregator = new WeatherAggregator($this->now);
        $result = $aggregator->aggregate([]);
        
        $this->assertNull($result['temperature']);
        $this->assertNull($result['wind_speed']);
        $this->assertNull($result['visibility']);
        $this->assertIsArray($result['_field_obs_time_map']);
        $this->assertEmpty($result['_field_obs_time_map']);
    }
    
    public function testSingleSourceAllFields(): void {
        $snapshot = $this->createSnapshot('tempest', [
            'temperature' => 20.5,
            'dewpoint' => 15.0,
            'humidity' => 75,
            'pressure' => 29.92,
            'wind_speed' => 10,
            'wind_direction' => 270,
            'gust_speed' => 15,
        ]);
        
        $aggregator = new WeatherAggregator($this->now);
        $result = $aggregator->aggregate([$snapshot]);
        
        $this->assertEquals(20.5, $result['temperature']);
        $this->assertEquals(15.0, $result['dewpoint']);
        $this->assertEquals(75, $result['humidity']);
        $this->assertEquals(29.92, $result['pressure']);
        $this->assertEquals(10, $result['wind_speed']);
        $this->assertEquals(270, $result['wind_direction']);
        $this->assertEquals(15, $result['gust_speed']);
        $this->assertEquals(5, $result['gust_factor']); // 15 - 10
        
        // Check attribution
        $this->assertEquals('tempest', $result['_field_source_map']['temperature']);
        $this->assertEquals('tempest', $result['_field_source_map']['wind_speed']);
    }
    
    public function testWindGroupMustBeComplete(): void {
        // Primary has only wind speed (no direction)
        $primary = $this->createSnapshot('tempest', [
            'temperature' => 20.5,
            'wind_speed' => 10,
            // Missing wind_direction!
        ]);
        
        // Backup has complete wind
        $backup = $this->createSnapshot('synopticdata', [
            'temperature' => 19.0,
            'wind_speed' => 8,
            'wind_direction' => 180,
            'gust_speed' => 12,
        ]);
        
        $aggregator = new WeatherAggregator($this->now);
        $result = $aggregator->aggregate([$primary, $backup]);
        
        // Temperature should come from primary (preferred)
        $this->assertEquals(20.5, $result['temperature']);
        $this->assertEquals('tempest', $result['_field_source_map']['temperature']);
        
        // Wind should come from backup (has complete group)
        $this->assertEquals(8, $result['wind_speed']);
        $this->assertEquals(180, $result['wind_direction']);
        $this->assertEquals(12, $result['gust_speed']);
        $this->assertEquals('synopticdata', $result['_field_source_map']['wind_speed']);
        $this->assertEquals('synopticdata', $result['_field_source_map']['wind_direction']);
    }
    
    public function testFreshnessBasedVisibilitySelection(): void {
        // Primary has visibility but is older
        $primaryTime = $this->now - 600; // 10 minutes ago
        $primary = $this->createSnapshot('tempest', [
            'temperature' => 20.5,
            'visibility' => 5, // Tempest visibility (older)
        ], $primaryTime);
        
        // METAR also has visibility but is fresher
        $metarTime = $this->now - 300; // 5 minutes ago
        $metar = $this->createSnapshot('metar', [
            'visibility' => 10, // METAR visibility (fresher)
            'ceiling' => 3500,
            'cloud_cover' => 'BKN',
        ], $metarTime);
        
        $aggregator = new WeatherAggregator($this->now);
        $result = $aggregator->aggregate([$primary, $metar]);
        
        // Visibility should come from METAR (fresher observation)
        $this->assertEquals(10, $result['visibility']);
        $this->assertEquals('metar', $result['_field_source_map']['visibility']);
        
        // Temperature should also come from METAR (fresher observation)
        // Note: Primary doesn't have temp in METAR, so this tests freshness across fields
        
        // Ceiling from METAR (only source)
        $this->assertEquals(3500, $result['ceiling']);
        $this->assertEquals('metar', $result['_field_source_map']['ceiling']);
    }
    
    public function testFresherPrimaryWinsOverMetar(): void {
        // Primary has visibility and is fresher
        $primaryTime = $this->now - 300; // 5 minutes ago
        $primary = $this->createSnapshot('tempest', [
            'temperature' => 20.5,
            'visibility' => 5, // Tempest visibility (fresher)
        ], $primaryTime);
        
        // METAR also has visibility but is older
        $metarTime = $this->now - 1200; // 20 minutes ago
        $metar = $this->createSnapshot('metar', [
            'visibility' => 10, // METAR visibility (older)
            'ceiling' => 3500,
            'cloud_cover' => 'BKN',
        ], $metarTime);
        
        $aggregator = new WeatherAggregator($this->now);
        $result = $aggregator->aggregate([$primary, $metar]);
        
        // Visibility should come from primary (fresher observation)
        $this->assertEquals(5, $result['visibility']);
        $this->assertEquals('tempest', $result['_field_source_map']['visibility']);
        
        // Temperature from primary (fresher and only source with temp)
        $this->assertEquals(20.5, $result['temperature']);
        $this->assertEquals('tempest', $result['_field_source_map']['temperature']);
        
        // Ceiling from METAR (only source with ceiling)
        $this->assertEquals(3500, $result['ceiling']);
        $this->assertEquals('metar', $result['_field_source_map']['ceiling']);
    }
    
    public function testMetarFallbackWhenMissing(): void {
        // Primary has visibility, METAR doesn't
        $primary = $this->createSnapshot('tempest', [
            'temperature' => 20.5,
            'visibility' => 5,
        ]);
        
        // METAR only has ceiling
        $metar = $this->createSnapshot('metar', [
            'ceiling' => 3500,
            'cloud_cover' => 'BKN',
        ]);
        
        $aggregator = new WeatherAggregator($this->now);
        $result = $aggregator->aggregate([$primary, $metar]);
        
        // Visibility should fall back to primary since METAR doesn't have it
        $this->assertEquals(5, $result['visibility']);
        $this->assertEquals('tempest', $result['_field_source_map']['visibility']);
    }
    
    public function testStaleDataIsSkipped(): void {
        $oldTime = $this->now - 7200; // 2 hours ago
        
        // Primary is stale
        $staleSnapshot = $this->createSnapshot('tempest', [
            'temperature' => 20.5,
            'wind_speed' => 10,
            'wind_direction' => 270,
        ], $oldTime);
        
        // Backup is fresh
        $freshSnapshot = $this->createSnapshot('synopticdata', [
            'temperature' => 19.0,
            'wind_speed' => 8,
            'wind_direction' => 180,
        ], $this->now);
        
        $aggregator = new WeatherAggregator($this->now);
        $maxAges = [
            'tempest' => 300, // 5 minutes - primary is stale
            'synopticdata' => 900, // 15 minutes - backup is fresh
        ];
        $result = $aggregator->aggregate([$staleSnapshot, $freshSnapshot], $maxAges);
        
        // Should use backup since primary is stale
        $this->assertEquals(19.0, $result['temperature']);
        $this->assertEquals('synopticdata', $result['_field_source_map']['temperature']);
        $this->assertEquals(8, $result['wind_speed']);
        $this->assertEquals('synopticdata', $result['_field_source_map']['wind_speed']);
    }
    
    public function testObservationTimeMapPopulated(): void {
        $snapshot = $this->createSnapshot('tempest', [
            'temperature' => 20.5,
            'humidity' => 75,
            'wind_speed' => 10,
            'wind_direction' => 270,
        ], $this->now - 60);
        
        $aggregator = new WeatherAggregator($this->now);
        $result = $aggregator->aggregate([$snapshot]);
        
        // Check observation times are populated
        $this->assertArrayHasKey('temperature', $result['_field_obs_time_map']);
        $this->assertArrayHasKey('humidity', $result['_field_obs_time_map']);
        $this->assertArrayHasKey('wind_speed', $result['_field_obs_time_map']);
        $this->assertArrayHasKey('wind_direction', $result['_field_obs_time_map']);
        
        // All should be the same observation time
        $this->assertEquals($this->now - 60, $result['_field_obs_time_map']['temperature']);
        $this->assertEquals($this->now - 60, $result['_field_obs_time_map']['wind_speed']);
    }
    
    public function testMixedSourcesForIndependentFields(): void {
        // Primary has temp but not humidity
        $primary = $this->createSnapshot('tempest', [
            'temperature' => 20.5,
            'pressure' => 29.92,
        ]);
        
        // Backup has humidity
        $backup = $this->createSnapshot('synopticdata', [
            'temperature' => 19.0,
            'humidity' => 75,
            'pressure' => 29.90,
        ]);
        
        $aggregator = new WeatherAggregator($this->now);
        $result = $aggregator->aggregate([$primary, $backup]);
        
        // Temperature and pressure from primary (preferred)
        $this->assertEquals(20.5, $result['temperature']);
        $this->assertEquals('tempest', $result['_field_source_map']['temperature']);
        $this->assertEquals(29.92, $result['pressure']);
        $this->assertEquals('tempest', $result['_field_source_map']['pressure']);
        
        // Humidity from backup (primary doesn't have it)
        $this->assertEquals(75, $result['humidity']);
        $this->assertEquals('synopticdata', $result['_field_source_map']['humidity']);
    }
    
    public function testAllSourcesFailReturnsNulls(): void {
        $oldTime = $this->now - 7200; // 2 hours ago
        
        // Both sources are stale
        $stale1 = $this->createSnapshot('tempest', [
            'temperature' => 20.5,
        ], $oldTime);
        
        $stale2 = $this->createSnapshot('synopticdata', [
            'temperature' => 19.0,
        ], $oldTime);
        
        $aggregator = new WeatherAggregator($this->now);
        $maxAges = [
            'tempest' => 300,
            'synopticdata' => 300,
        ];
        $result = $aggregator->aggregate([$stale1, $stale2], $maxAges);
        
        // All fields should be null (fail closed)
        $this->assertNull($result['temperature']);
        $this->assertEmpty($result['_field_source_map']);
    }

    /**
     * Local wind overrides neighboring METAR wind even when METAR is fresher.
     *
     * Safety: Wind at the airport must come from on-site sensors when available.
     * Neighboring METAR (different station) must not override local measurements.
     */
    public function testLocalWindOverridesNeighboringMetar_EvenWhenMetarFresher(): void {
        $localTime = $this->now - 600; // 10 min ago - local is older
        $local = $this->createSnapshot('tempest', [
            'wind_speed' => 8,
            'wind_direction' => 270,
            'gust_speed' => 12,
            'temperature' => 18.0,
        ], $localTime);

        $metarTime = $this->now - 120; // 2 min ago - neighboring METAR is fresher
        $neighboringMetar = $this->createSnapshot('metar', [
            'wind_speed' => 15,
            'wind_direction' => 180,
            'gust_speed' => 20,
            'temperature' => 17.0,
            'visibility' => 10,
            'ceiling' => 3500,
            'cloud_cover' => 'BKN',
        ], $metarTime, 'KVUO'); // Neighboring station, not KSPB

        $aggregator = new WeatherAggregator($this->now);
        $result = $aggregator->aggregate([$local, $neighboringMetar], null, 'KSPB');

        $this->assertEquals(8, $result['wind_speed'], 'Local wind must override neighboring METAR');
        $this->assertEquals(270, $result['wind_direction']);
        $this->assertEquals(12, $result['gust_speed']);
        $this->assertEquals('tempest', $result['_field_source_map']['wind_speed']);

        $this->assertEquals(18.0, $result['temperature'], 'Local temp must override neighboring METAR');
        $this->assertEquals('tempest', $result['_field_source_map']['temperature']);

        // Aviation fields: neighboring METAR can fill in (local doesn't have them)
        $this->assertEquals(10, $result['visibility']);
        $this->assertEquals(3500, $result['ceiling']);
        $this->assertEquals('metar', $result['_field_source_map']['visibility']);
    }

    /**
     * Neighboring METAR fills in missing local fields when local has no data.
     */
    public function testNeighboringMetarFillsInMissingFields_WhenLocalHasNoData(): void {
        $local = $this->createSnapshot('tempest', [
            'temperature' => 20.5,
            'wind_speed' => 10,
            'wind_direction' => 270,
            // No visibility, ceiling, cloud_cover
        ]);

        $neighboringMetar = $this->createSnapshot('metar', [
            'visibility' => 6,
            'ceiling' => 2500,
            'cloud_cover' => 'OVC',
            'wind_speed' => 8,
            'wind_direction' => 180,
        ], $this->now - 300, 'KVUO');

        $aggregator = new WeatherAggregator($this->now);
        $result = $aggregator->aggregate([$local, $neighboringMetar], null, 'KSPB');

        $this->assertEquals(10, $result['wind_speed'], 'Local wind must be used');
        $this->assertEquals(270, $result['wind_direction']);
        $this->assertEquals(20.5, $result['temperature']);

        $this->assertEquals(6, $result['visibility'], 'Neighboring METAR fills visibility');
        $this->assertEquals(2500, $result['ceiling']);
        $this->assertEquals('OVC', $result['cloud_cover']);
        $this->assertEquals('metar', $result['_field_source_map']['visibility']);
    }

    /**
     * Local METAR (same station as airport) - freshest wins, no local override.
     */
    public function testLocalMetar_SameStation_FreshestWins(): void {
        $localTime = $this->now - 600;
        $local = $this->createSnapshot('tempest', [
            'wind_speed' => 8,
            'wind_direction' => 270,
            'temperature' => 18.0,
        ], $localTime);

        $metarTime = $this->now - 120;
        $localMetar = $this->createSnapshot('metar', [
            'wind_speed' => 12,
            'wind_direction' => 180,
            'temperature' => 17.5,
        ], $metarTime, 'KSPB'); // Same station as airport

        $aggregator = new WeatherAggregator($this->now);
        $result = $aggregator->aggregate([$local, $localMetar], null, 'KSPB');

        $this->assertEquals(12, $result['wind_speed'], 'Local METAR (same station) fresher wins');
        $this->assertEquals(17.5, $result['temperature']);
        $this->assertEquals('metar', $result['_field_source_map']['wind_speed']);
    }

    /**
     * When localAirportIcao is null, preserve existing freshness-based behavior.
     */
    public function testNullLocalAirportIcao_PreservesFreshnessBehavior(): void {
        $localTime = $this->now - 600;
        $local = $this->createSnapshot('tempest', [
            'wind_speed' => 8,
            'wind_direction' => 270,
        ], $localTime);

        $metarTime = $this->now - 120;
        $metar = $this->createSnapshot('metar', [
            'wind_speed' => 12,
            'wind_direction' => 180,
        ], $metarTime, 'KVUO');

        $aggregator = new WeatherAggregator($this->now);
        $result = $aggregator->aggregate([$local, $metar], null, null);

        $this->assertEquals(12, $result['wind_speed'], 'Without localAirportIcao, freshest wins');
        $this->assertEquals('metar', $result['_field_source_map']['wind_speed']);
    }

    /**
     * _field_station_map populated for METAR when metarStationId is set
     */
    public function testFieldStationMap_MetarWithStationId_PopulatesMap(): void
    {
        $metar = $this->createSnapshot('metar', [
            'wind_speed' => 10,
            'wind_direction' => 270,
            'visibility' => 10,
            'temperature' => 15.0,
        ], null, 'KSPB');

        $aggregator = new WeatherAggregator($this->now);
        $result = $aggregator->aggregate([$metar]);

        $this->assertArrayHasKey('_field_station_map', $result);
        $this->assertEquals('KSPB', $result['_field_station_map']['wind_speed']);
        $this->assertEquals('KSPB', $result['_field_station_map']['wind_direction']);
        $this->assertEquals('KSPB', $result['_field_station_map']['visibility']);
        $this->assertEquals('KSPB', $result['_field_station_map']['temperature']);
    }

    /**
     * _field_station_map populated for swob_auto when stationId is set
     */
    public function testFieldStationMap_SwobWithStationId_PopulatesMap(): void
    {
        $swob = $this->createSnapshot('swob_auto', [
            'wind_speed' => 12,
            'wind_direction' => 180,
            'temperature' => -5.0,
            'pressure' => 29.92,
        ], null, null, 'CYAV');

        $aggregator = new WeatherAggregator($this->now);
        $result = $aggregator->aggregate([$swob]);

        $this->assertArrayHasKey('_field_station_map', $result);
        $this->assertEquals('CYAV', $result['_field_station_map']['wind_speed']);
        $this->assertEquals('CYAV', $result['_field_station_map']['temperature']);
    }

    /**
     * _field_station_map empty when source has no station (e.g. tempest)
     */
    public function testFieldStationMap_NonIcaoSource_EmptyMap(): void
    {
        $tempest = $this->createSnapshot('tempest', [
            'temperature' => 20.5,
            'wind_speed' => 10,
            'wind_direction' => 270,
        ]);

        $aggregator = new WeatherAggregator($this->now);
        $result = $aggregator->aggregate([$tempest]);

        $this->assertArrayHasKey('_field_station_map', $result);
        $this->assertEmpty($result['_field_station_map']);
    }

    /**
     * _field_station_map from winning source when multiple ICAO sources
     */
    public function testFieldStationMap_MultipleIcaoSources_UsesWinningStation(): void
    {
        $olderTime = $this->now - 600;
        $swob = $this->createSnapshot('swob_auto', [
            'wind_speed' => 8,
            'wind_direction' => 270,
            'temperature' => 10.0,
        ], $olderTime, null, 'CYAV');

        $fresherTime = $this->now - 60;
        $nws = $this->createSnapshot('nws', [
            'wind_speed' => 12,
            'wind_direction' => 180,
            'temperature' => 12.0,
        ], $fresherTime, null, 'KSPB');

        $aggregator = new WeatherAggregator($this->now);
        $result = $aggregator->aggregate([$swob, $nws]);

        $this->assertEquals('nws', $result['_field_source_map']['wind_speed']);
        $this->assertEquals('KSPB', $result['_field_station_map']['wind_speed']);
        $this->assertEquals('KSPB', $result['_field_station_map']['temperature']);
    }

    /**
     * Empty result includes empty _field_station_map
     */
    public function testEmptyResult_IncludesFieldStationMap(): void
    {
        $aggregator = new WeatherAggregator($this->now);
        $result = $aggregator->aggregate([]);

        $this->assertArrayHasKey('_field_station_map', $result);
        $this->assertIsArray($result['_field_station_map']);
        $this->assertEmpty($result['_field_station_map']);
    }
}

