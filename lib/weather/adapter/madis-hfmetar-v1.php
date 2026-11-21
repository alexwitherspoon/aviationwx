<?php
/**
 * MADIS HFMETAR Adapter v1
 *
 * Handles parsing MADIS HFMETAR netCDF files into WeatherSnapshots for
 * Federal ASOS stations. HFMETAR carries ASOS observations at 5-minute
 * marks (12 per hour per station), which gives airports with otherwise
 * hourly METAR feeds a much denser wind history for the compass petals
 * and a faster-moving current wind.
 *
 * Data source: https://madis-data.ncep.noaa.gov/madisPublic1/data/LDAD/hfmetar/netCDF/
 * Public anonymous access; no API key required.
 *
 * Note: buildUrl() returns the current UTC hour's bulk file, which holds
 * every station's observations for that hour. A production integration
 * should share one download across airports (same pattern as the METAR
 * bulk refresh) rather than fetching the file per airport.
 *
 * @package AviationWX\Weather\Adapter
 */

require_once __DIR__ . '/../../constants.php';
require_once __DIR__ . '/../../logger.php';
require_once __DIR__ . '/../../test-mocks.php';
require_once __DIR__ . '/../data/WeatherReading.php';
require_once __DIR__ . '/../data/WindGroup.php';
require_once __DIR__ . '/../data/WeatherSnapshot.php';
require_once __DIR__ . '/../calculator.php';
require_once __DIR__ . '/../../madis-hfmetar/madis-hfmetar.php';

use AviationWX\Weather\Data\WeatherReading;
use AviationWX\Weather\Data\WindGroup;
use AviationWX\Weather\Data\WeatherSnapshot;

/**
 * MADIS HFMETAR Adapter
 *
 * Provides the fields ASOS reports at 5-minute marks. Wind, temperature,
 * dewpoint, altimeter, and visibility come from the structured netCDF
 * columns; humidity is derived from temperature and dewpoint.
 */
class MadisHfmetarAdapter {

    /** Fields this adapter can provide */
    public const FIELDS_PROVIDED = [
        'temperature',
        'dewpoint',
        'humidity',
        'pressure',
        'wind_speed',
        'wind_direction',
        'gust_speed',
        'visibility',
    ];

    /** Typical update frequency in seconds (5-minute ASOS marks) */
    public const UPDATE_FREQUENCY = 300;

    /**
     * Max acceptable age before data is stale.
     *
     * Observations arrive in the public feed roughly 10-25 minutes after
     * the 5-minute mark, so the threshold is an hour to avoid failing
     * closed on data that is simply in delivery.
     */
    public const MAX_ACCEPTABLE_AGE = 3600;

    /** Source type identifier */
    public const SOURCE_TYPE = 'madis_hfmetar';

    /**
     * Get fields this adapter can provide
     */
    public static function getFieldsProvided(): array {
        return self::FIELDS_PROVIDED;
    }

    /**
     * Get typical update frequency in seconds
     */
    public static function getTypicalUpdateFrequency(): int {
        return self::UPDATE_FREQUENCY;
    }

    /**
     * Get maximum acceptable age before data is stale
     */
    public static function getMaxAcceptableAge(): int {
        return self::MAX_ACCEPTABLE_AGE;
    }

    /**
     * Get source type identifier
     */
    public static function getSourceType(): string {
        return self::SOURCE_TYPE;
    }

    /**
     * Check if this adapter provides a specific field
     */
    public static function providesField(string $fieldName): bool {
        return in_array($fieldName, self::FIELDS_PROVIDED, true);
    }

    /**
     * Build the URL for fetching data
     *
     * Returns the current UTC hour's HFMETAR bulk file. The station_id is
     * validated here but does not appear in the URL because the file holds
     * every station; the station is extracted at parse time.
     *
     * @param array $config Source configuration with station_id
     * @return string|null URL or null if config is invalid
     */
    public static function buildUrl(array $config): ?string {
        $stationId = $config['station_id'] ?? '';
        if (!is_string($stationId) || trim($stationId) === '') {
            return null;
        }
        if (!preg_match('/^[A-Z0-9]{3,5}$/i', trim($stationId))) {
            return null;
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return 'https://madis-data.ncep.noaa.gov/madisPublic1/data/LDAD/hfmetar/netCDF/'
            . $now->format('Ymd_H') . '00.gz';
    }

    /**
     * Parse an HFMETAR netCDF response into a WeatherSnapshot
     *
     * The response is the gzipped (or raw) netCDF file bytes. When the
     * caller has already sliced the file to disk, config['file_path'] may
     * be set instead; the file is read and parsed the same way.
     *
     * Units converted by madisHfmetarDecodeBytes():
     * - Temperature/dewpoint: celsius
     * - Pressure: inHg
     * - Wind: knots (direction true north, degrees)
     * - Visibility: statute miles
     *
     * @param string $response Raw netCDF file bytes (gzipped or not)
     * @param array $config Source configuration with station_id
     * @return WeatherSnapshot|null
     */
    public static function parseResponse(string $response, array $config = []): ?WeatherSnapshot {
        $stationId = $config['station_id'] ?? '';
        if (!is_string($stationId) || trim($stationId) === '') {
            return WeatherSnapshot::empty(self::SOURCE_TYPE);
        }
        $stationId = strtoupper(trim($stationId));

        $bytes = $response;
        $filePath = $config['file_path'] ?? null;
        if (is_string($filePath) && $filePath !== '') {
            $bytes = @file_get_contents($filePath);
            if ($bytes === false) {
                return WeatherSnapshot::empty(self::SOURCE_TYPE);
            }
        }

        $observations = madisHfmetarDecodeBytes($bytes, $stationId);
        if ($observations === null || $observations === []) {
            return WeatherSnapshot::empty(self::SOURCE_TYPE);
        }

        // Observations are sorted ascending; the latest mark is the snapshot.
        $obs = $observations[count($observations) - 1];
        $obsTime = (int)$obs['obs_time'];
        $source = self::SOURCE_TYPE;

        $temperature = $obs['temperature_c'];
        $dewpoint = $obs['dewpoint_c'];
        $humidity = null;
        if ($temperature !== null && $dewpoint !== null && function_exists('calculateHumidityFromDewpoint')) {
            $humidity = calculateHumidityFromDewpoint($temperature, $dewpoint);
        }

        $windSpeed = $obs['wind_speed_kts'];
        $windDirection = $obs['wind_direction_deg'];
        $gustSpeed = $obs['gust_speed_kts'];
        $hasCompleteWind = $windSpeed !== null && $windDirection !== null;

        return new WeatherSnapshot(
            source: $source,
            fetchTime: time(),
            temperature: WeatherReading::celsius($temperature, $source, $obsTime),
            dewpoint: WeatherReading::celsius($dewpoint, $source, $obsTime),
            humidity: WeatherReading::percent($humidity, $source, $obsTime),
            pressure: WeatherReading::inHg($obs['altimeter_inhg'], $source, $obsTime),
            precipAccum: WeatherReading::inches(null, $source, $obsTime),
            wind: $hasCompleteWind
                ? WindGroup::from($windSpeed, $windDirection, $gustSpeed, $source, $obsTime)
                : WindGroup::empty(),
            visibility: WeatherReading::statuteMiles($obs['visibility_sm'], $source, $obsTime),
            ceiling: WeatherReading::feet(null, $source, $obsTime),
            cloudCover: WeatherReading::text(null, $source, $obsTime),
            rawMetar: null,
            isValid: true,
            metarStationId: null,
            stationId: $stationId,
            metarFieldCompleteness: null
        );
    }
}
