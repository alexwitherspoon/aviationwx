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
     */
    private function createSnapshot(
        string $source,
        array $fields,
        ?int $obsTime = null
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
            isValid: true
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
    
    public function testMetarPreferredForVisibility(): void {
        // Primary has visibility
        $primary = $this->createSnapshot('tempest', [
            'temperature' => 20.5,
            'visibility' => 5, // Tempest visibility
        ]);
        
        // METAR also has visibility
        $metar = $this->createSnapshot('metar', [
            'visibility' => 10, // METAR visibility (authoritative)
            'ceiling' => 3500,
            'cloud_cover' => 'BKN',
        ]);
        
        $aggregator = new WeatherAggregator($this->now);
        $result = $aggregator->aggregate([$primary, $metar]);
        
        // Temperature from primary
        $this->assertEquals(20.5, $result['temperature']);
        
        // Visibility should come from METAR (preferred for this field)
        $this->assertEquals(10, $result['visibility']);
        $this->assertEquals('metar', $result['_field_source_map']['visibility']);
        
        // Ceiling from METAR
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
}

