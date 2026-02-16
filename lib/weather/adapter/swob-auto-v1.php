<?php
/**
 * SWOB-ML AUTO Adapter v1
 *
 * Fetches weather from Environment Canada SWOB-ML for automated Canadian
 * stations (NAV Canada AWOS). Prefers {ICAO}-AUTO-minute-swob.xml when available
 * (sub-hourly updates); falls back to {ICAO}-AUTO-swob.xml (hourly) for stations
 * without minute data.
 *
 * Configuration:
 * - station_id: ICAO station identifier (e.g., "CYAV", "CBBC") - REQUIRED
 *
 * @package AviationWX\Weather\Adapter
 */

require_once __DIR__ . '/swob-helper.php';
require_once __DIR__ . '/../data/WeatherReading.php';
require_once __DIR__ . '/../data/WindGroup.php';
require_once __DIR__ . '/../data/WeatherSnapshot.php';

use AviationWX\Weather\Data\WeatherReading;
use AviationWX\Weather\Data\WindGroup;
use AviationWX\Weather\Data\WeatherSnapshot;

class SwobAutoAdapter
{
    /** @var string[] */
    public const FIELDS_PROVIDED = [
        'temperature',
        'dewpoint',
        'humidity',
        'pressure',
        'wind_speed',
        'wind_direction',
        'gust_speed',
        'visibility',
        'ceiling',
        'cloud_cover',
    ];

    /** Typical update frequency: minute SWOB ~5 min, standard SWOB hourly */
    public const UPDATE_FREQUENCY = 300;

    /** Max acceptable age before data is stale (1 hour) */
    public const MAX_ACCEPTABLE_AGE = 3600;

    public const SOURCE_TYPE = 'swob_auto';

    private const BASE_URL = 'https://dd.weather.gc.ca/today/observations/swob-ml/latest';

    public static function getFieldsProvided(): array
    {
        return self::FIELDS_PROVIDED;
    }

    public static function getTypicalUpdateFrequency(): int
    {
        return self::UPDATE_FREQUENCY;
    }

    public static function getMaxAcceptableAge(): int
    {
        return self::MAX_ACCEPTABLE_AGE;
    }

    public static function getSourceType(): string
    {
        return self::SOURCE_TYPE;
    }

    public static function providesField(string $fieldName): bool
    {
        return in_array($fieldName, self::FIELDS_PROVIDED, true);
    }

    /**
     * Build the fetch URL for a SWOB AUTO station (prefers minute when available)
     *
     * @param array $config Source config with station_id (e.g., "CYAV")
     * @return string|null URL or null if invalid
     */
    public static function buildUrl(array $config): ?string
    {
        $stationId = self::normalizeStationId($config['station_id'] ?? '');
        if ($stationId === null) {
            return null;
        }
        return self::BASE_URL . '/' . $stationId . '-AUTO-minute-swob.xml';
    }

    /**
     * Build fallback URL for standard SWOB (hourly) when minute SWOB returns 404
     *
     * @param array $config Source config with station_id
     * @return string|null URL or null if invalid
     */
    public static function buildStandardUrl(array $config): ?string
    {
        $stationId = self::normalizeStationId($config['station_id'] ?? '');
        if ($stationId === null) {
            return null;
        }
        return self::BASE_URL . '/' . $stationId . '-AUTO-swob.xml';
    }

    /**
     * @param mixed $stationId
     * @return string|null Uppercase 4-char ICAO or null
     */
    private static function normalizeStationId($stationId): ?string
    {
        if (empty($stationId) || !is_string($stationId)) {
            return null;
        }
        $stationId = strtoupper(trim($stationId));
        return preg_match('/^[A-Z]{4}$/', $stationId) ? $stationId : null;
    }

    /**
     * Parse SWOB-ML XML response into WeatherSnapshot
     *
     * @param string $response Raw XML response
     * @param array $config Source configuration
     * @return WeatherSnapshot
     */
    public static function parseToSnapshot(string $response, array $config = []): WeatherSnapshot
    {
        $parsed = parseSwobXmlToWeatherArray($response);
        if ($parsed === null) {
            return WeatherSnapshot::empty(self::SOURCE_TYPE);
        }

        $obsTime = $parsed['obs_time'] ?? time();
        $source = self::SOURCE_TYPE;
        $hasCompleteWind = $parsed['wind_speed'] !== null && $parsed['wind_direction'] !== null;

        return new WeatherSnapshot(
            source: $source,
            fetchTime: time(),
            temperature: WeatherReading::celsius($parsed['temperature'], $source, $obsTime),
            dewpoint: WeatherReading::celsius($parsed['dewpoint'], $source, $obsTime),
            humidity: WeatherReading::percent($parsed['humidity'], $source, $obsTime),
            pressure: WeatherReading::inHg($parsed['pressure'], $source, $obsTime),
            precipAccum: WeatherReading::null($source),
            wind: $hasCompleteWind
                ? WindGroup::from(
                    $parsed['wind_speed'],
                    $parsed['wind_direction'],
                    $parsed['gust_speed'],
                    $source,
                    $obsTime
                )
                : WindGroup::empty(),
            visibility: WeatherReading::statuteMiles($parsed['visibility'], $source, $obsTime),
            ceiling: WeatherReading::feet($parsed['ceiling'], $source, $obsTime),
            cloudCover: $parsed['cloud_cover'] !== null
                ? new WeatherReading($parsed['cloud_cover'], 'text', $obsTime, $source, true)
                : WeatherReading::null($source),
            rawMetar: null,
            isValid: true
        );
    }
}
