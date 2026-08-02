<?php
/**
 * AviationWX Bridge weather adapter (local cache; no upstream HTTP).
 *
 * Reads latest accepted sample for an enabled weather_sources binding.
 */

require_once __DIR__ . '/../data/WeatherReading.php';
require_once __DIR__ . '/../data/WindGroup.php';
require_once __DIR__ . '/../data/WeatherSnapshot.php';
require_once __DIR__ . '/../../bridge/weather-store.php';
require_once __DIR__ . '/../../bridge/config.php';

use AviationWX\Weather\Data\WeatherReading;
use AviationWX\Weather\Data\WeatherSnapshot;
use AviationWX\Weather\Data\WindGroup;

/**
 * Resolve a WeatherSnapshot from bridge weather cache for a weather_sources row.
 *
 * @param string $airportId Airport id
 * @param array $source weather_sources entry (type aviationwx_bridge)
 * @param array|null $airport Full airport config for enable re-check
 * @return WeatherSnapshot|null
 */
function aviationwxBridgeResolveSnapshot(string $airportId, array $source, ?array $airport = null): ?WeatherSnapshot
{
    $bridgeId = $source['bridge_id'] ?? null;
    $sourceId = $source['bridge_source_id'] ?? null;
    if (!is_string($bridgeId) || $bridgeId === '' || !is_string($sourceId) || $sourceId === '') {
        return null;
    }

    if (is_array($airport) && !isBridgeWeatherSourceEnabled($airport, $bridgeId, $sourceId)) {
        return null;
    }

    $latest = bridgeLoadWeatherLatest($airportId, $bridgeId, $sourceId);
    if ($latest === null || !isset($latest['sample']) || !is_array($latest['sample'])) {
        return null;
    }

    return AviationWXBridgeAdapter::snapshotFromLatestRecord($latest, $source);
}

class AviationWXBridgeAdapter
{
    public const SOURCE_TYPE = 'aviationwx_bridge';

    /**
     * Build a WeatherSnapshot from a stored latest.json record.
     *
     * @param array $record Stored latest record
     * @param array $config weather_sources row
     * @return WeatherSnapshot
     */
    public static function snapshotFromLatestRecord(array $record, array $config = []): WeatherSnapshot
    {
        $sample = $record['sample'] ?? [];
        if (!is_array($sample)) {
            $sample = [];
        }

        $obsTime = isset($record['observed_unix']) && is_numeric($record['observed_unix'])
            ? (int) $record['observed_unix']
            : (isset($record['observed_at']) ? (strtotime((string) $record['observed_at']) ?: time()) : time());

        $source = self::SOURCE_TYPE;
        $temp = isset($sample['temp_c']) && is_numeric($sample['temp_c']) ? (float) $sample['temp_c'] : null;
        $humidity = isset($sample['humidity_pct']) && is_numeric($sample['humidity_pct'])
            ? (float) $sample['humidity_pct']
            : null;
        $pressure = isset($sample['pressure_inhg']) && is_numeric($sample['pressure_inhg'])
            ? (float) $sample['pressure_inhg']
            : null;
        $precip = isset($sample['rain_in']) && is_numeric($sample['rain_in']) ? (float) $sample['rain_in'] : null;
        $windSpeed = isset($sample['wind_speed_kt']) && is_numeric($sample['wind_speed_kt'])
            ? (float) $sample['wind_speed_kt']
            : null;
        $windGust = isset($sample['wind_gust_kt']) && is_numeric($sample['wind_gust_kt'])
            ? (float) $sample['wind_gust_kt']
            : null;
        $windDir = isset($sample['wind_dir_deg']) && is_numeric($sample['wind_dir_deg'])
            ? (float) $sample['wind_dir_deg']
            : null;

        $hasCompleteWind = $windSpeed !== null && $windDir !== null;
        $stationId = isset($config['station_id']) && is_string($config['station_id']) && $config['station_id'] !== ''
            ? $config['station_id']
            : null;

        $hasAny = $temp !== null || $humidity !== null || $pressure !== null || $precip !== null || $hasCompleteWind;

        return new WeatherSnapshot(
            source: $source,
            fetchTime: time(),
            temperature: WeatherReading::celsius($temp, $source, $obsTime),
            dewpoint: WeatherReading::null($source),
            humidity: WeatherReading::percent($humidity, $source, $obsTime),
            pressure: WeatherReading::inHg($pressure, $source, $obsTime),
            precipAccum: WeatherReading::inches($precip, $source, $obsTime),
            wind: $hasCompleteWind
                ? WindGroup::from($windSpeed, $windDir, $windGust, $source, $obsTime)
                : WindGroup::empty(),
            visibility: WeatherReading::null($source),
            ceiling: WeatherReading::null($source),
            cloudCover: WeatherReading::null($source),
            rawMetar: null,
            isValid: $hasAny,
            metarStationId: null,
            stationId: $stationId
        );
    }
}
