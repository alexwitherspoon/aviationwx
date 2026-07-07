<?php
/**
 * DyaconLive API Adapter v1
 *
 * API documentation: https://api.dyacon.net/docs
 *
 * Authentication: OAuth2 password grant (DyaconLive web login); bearer on data endpoints.
 * Required config: station_id (integer), username, password
 *
 * Reports align to 10-minute clock boundaries in station timezone. Upstream skip logic
 * lives in dyaconlive-state.php (scheduler cadence unchanged).
 */

require_once __DIR__ . '/../../constants.php';
require_once __DIR__ . '/../../test-mocks.php';
require_once __DIR__ . '/../../logger.php';
require_once __DIR__ . '/../dyaconlive-auth.php';
require_once __DIR__ . '/../dyaconlive-bucket.php';
require_once __DIR__ . '/../calculator.php';
require_once __DIR__ . '/../data/WeatherReading.php';
require_once __DIR__ . '/../data/WindGroup.php';
require_once __DIR__ . '/../data/WeatherSnapshot.php';

use AviationWX\Weather\Data\WeatherReading;
use AviationWX\Weather\Data\WindGroup;
use AviationWX\Weather\Data\WeatherSnapshot;

class DyaconLiveAdapter
{
    /** Fields this adapter can provide */
    public const FIELDS_PROVIDED = [
        'temperature',
        'humidity',
        'pressure',
        'wind_speed',
        'wind_direction',
        'gust_speed',
        'precip_accum',
    ];

    public const UPDATE_FREQUENCY = 600;

    public const MAX_ACCEPTABLE_AGE = 1200;

    public const SOURCE_TYPE = 'dyaconlive';

    /** API variable query params (unit overrides via pipe syntax) */
    public const DATA_VARIABLES = [
        'air_temp|F',
        'humidity',
        'air_pressure|inHg',
        'wind10m_speed|mph',
        'wind10m_direction',
        'wind_gust|mph',
        'rainday_cumul',
    ];

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
     * @param array<string, mixed> $config Source configuration
     */
    public static function buildUrl(array $config, string $timezone = 'UTC'): ?string
    {
        $stationId = self::normalizeStationId($config['station_id'] ?? null);
        if ($stationId === null) {
            return null;
        }
        if (!isset($config['username'], $config['password'])
            || !is_string($config['username'])
            || !is_string($config['password'])
            || trim($config['username']) === ''
            || $config['password'] === ''
        ) {
            return null;
        }

        try {
            $tz = new DateTimeZone($timezone);
        } catch (Exception $e) {
            $tz = new DateTimeZone('UTC');
        }

        $today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
        $params = [
            'startdate' => $today,
            'enddate' => $today,
            'timezone' => $timezone,
        ];

        $query = [];
        foreach ($params as $key => $value) {
            $query[] = rawurlencode($key) . '=' . rawurlencode($value);
        }
        foreach (self::DATA_VARIABLES as $variable) {
            $query[] = 'variable=' . rawurlencode($variable);
        }

        $base = rtrim(DYACONLIVE_API_BASE_URL, '/');

        return $base . '/data/' . $stationId . '?' . implode('&', $query);
    }

    /**
     * @param array<string, mixed> $config
     * @return list<string>
     */
    public static function getHeaders(array $config): array
    {
        $username = isset($config['username']) && is_string($config['username']) ? trim($config['username']) : '';
        $password = isset($config['password']) && is_string($config['password']) ? $config['password'] : '';
        $token = dyaconliveGetBearerToken($username, $password);
        if ($token === null) {
            return ['Accept: application/json'];
        }

        return [
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function parseToSnapshot(string $response, array $config = []): ?WeatherSnapshot
    {
        $timezone = isset($config['timezone']) && is_string($config['timezone']) && $config['timezone'] !== ''
            ? $config['timezone']
            : 'UTC';
        $stationId = self::normalizeStationId($config['station_id'] ?? null);

        $parsed = parseDyaconLiveDataResponse($response, $timezone);
        if ($parsed === null) {
            return null;
        }

        if ($parsed['obs_time'] === null) {
            return WeatherSnapshot::empty(self::SOURCE_TYPE);
        }

        $pressure = self::normalizeStationPressureToAltimeter(
            $parsed['pressure'],
            $config
        );

        $obsTime = $parsed['obs_time'];
        $source = self::SOURCE_TYPE;
        $hasWind = $parsed['wind_speed'] !== null && $parsed['wind_direction'] !== null;

        return new WeatherSnapshot(
            source: $source,
            fetchTime: time(),
            temperature: WeatherReading::celsius($parsed['temperature'], $source, $obsTime),
            dewpoint: WeatherReading::null($source),
            humidity: WeatherReading::percent($parsed['humidity'], $source, $obsTime),
            pressure: WeatherReading::inHg($pressure, $source, $obsTime),
            precipAccum: WeatherReading::inches($parsed['precip_accum'], $source, $obsTime),
            wind: $hasWind
                ? WindGroup::from(
                    $parsed['wind_speed'],
                    $parsed['wind_direction'],
                    $parsed['gust_speed'],
                    $source,
                    $obsTime
                )
                : WindGroup::empty(),
            visibility: WeatherReading::null($source),
            ceiling: WeatherReading::null($source),
            cloudCover: WeatherReading::null($source),
            rawMetar: null,
            isValid: true,
            metarStationId: null,
            stationId: $stationId !== null ? (string) $stationId : null,
            metarFieldCompleteness: null
        );
    }

    /**
     * Last bucket ISO from parse (for state persistence).
     *
     * @param array<string, mixed> $config
     */
    public static function extractLastBucketIso(string $response, array $config = []): ?string
    {
        $timezone = isset($config['timezone']) && is_string($config['timezone']) && $config['timezone'] !== ''
            ? $config['timezone']
            : 'UTC';

        $parsed = parseDyaconLiveDataResponse($response, $timezone);

        return $parsed['last_bucket_iso'] ?? null;
    }

    public static function normalizeStationId(mixed $stationId): ?int
    {
        if (is_int($stationId)) {
            return $stationId > 0 ? $stationId : null;
        }
        if (is_string($stationId) && ctype_digit(trim($stationId))) {
            $id = (int) trim($stationId);
            return $id > 0 ? $id : null;
        }

        return null;
    }

    /**
     * Station pressure from Dyacon is reduced to sea-level altimeter for global pipeline.
     *
     * @param array<string, mixed> $config May include elevation_ft from airport config
     */
    private static function normalizeStationPressureToAltimeter(?float $stationPressureInHg, array $config): ?float
    {
        if ($stationPressureInHg === null) {
            return null;
        }

        if (!isset($config['elevation_ft']) || !is_numeric($config['elevation_ft'])) {
            aviationwx_log('warning', 'dyaconlive pressure skipped: missing elevation_ft for sea-level conversion', [
                'station_id' => $config['station_id'] ?? null,
            ], 'app');
            return null;
        }

        return stationPressureToAltimeterSettingInHg($stationPressureInHg, (float) $config['elevation_ft']);
    }
}

/**
 * @return array{
 *   temperature: float|null,
 *   humidity: float|null,
 *   pressure: float|null,
 *   wind_speed: float|null,
 *   wind_direction: float|int|null,
 *   gust_speed: float|null,
 *   precip_accum: float|null,
 *   obs_time: int|null,
 *   last_bucket_iso: string|null
 * }|null
 */
function parseDyaconLiveDataResponse(string $response, string $timezone): ?array
{
    if (!is_string($response) || $response === '') {
        return null;
    }

    $seriesList = json_decode($response, true);
    if (!is_array($seriesList)) {
        return null;
    }

    $byName = [];
    foreach ($seriesList as $series) {
        if (!is_array($series) || !isset($series['variable_name'])) {
            continue;
        }
        $name = (string) $series['variable_name'];
        $byName[$name] = $series;
    }

    $anchor = $byName['air_temp'] ?? $byName['wind10m_speed'] ?? null;
    if (!is_array($anchor)) {
        return [
            'temperature' => null,
            'humidity' => null,
            'pressure' => null,
            'wind_speed' => null,
            'wind_direction' => null,
            'gust_speed' => null,
            'precip_accum' => null,
            'obs_time' => null,
            'last_bucket_iso' => null,
        ];
    }

    $datetimes = $anchor['datetimes'] ?? [];
    $values = $anchor['values'] ?? [];
    if (!is_array($datetimes) || !is_array($values) || count($datetimes) === 0 || count($values) === 0) {
        return [
            'temperature' => null,
            'humidity' => null,
            'pressure' => null,
            'wind_speed' => null,
            'wind_direction' => null,
            'gust_speed' => null,
            'precip_accum' => null,
            'obs_time' => null,
            'last_bucket_iso' => null,
        ];
    }

    $lastIndex = min(count($datetimes), count($values)) - 1;
    $lastBucketIso = is_string($datetimes[$lastIndex] ?? null) ? $datetimes[$lastIndex] : null;
    $seriesTimezone = is_string($anchor['timezone'] ?? null) && $anchor['timezone'] !== ''
        ? $anchor['timezone']
        : $timezone;
    $obsTime = $lastBucketIso !== null
        ? dyaconliveParseBucketIsoToUnix($lastBucketIso, $seriesTimezone)
        : null;

    $tempF = dyaconliveSeriesValueAtBucket($byName['air_temp'] ?? null, $lastIndex, $lastBucketIso);
    $temperature = $tempF !== null ? ((float) $tempF - 32.0) / 1.8 : null;
    $humidity = dyaconliveSeriesValueAtBucket($byName['humidity'] ?? null, $lastIndex, $lastBucketIso);
    $pressure = dyaconliveSeriesValueAtBucket($byName['air_pressure'] ?? null, $lastIndex, $lastBucketIso);
    $windMph = dyaconliveSeriesValueAtBucket($byName['wind10m_speed'] ?? null, $lastIndex, $lastBucketIso);
    $windDir = dyaconliveSeriesValueAtBucket($byName['wind10m_direction'] ?? null, $lastIndex, $lastBucketIso);
    $gustMph = dyaconliveSeriesValueAtBucket($byName['wind_gust'] ?? null, $lastIndex, $lastBucketIso);
    $precip = dyaconliveSeriesValueAtBucket($byName['rainday_cumul'] ?? null, $lastIndex, $lastBucketIso);

    $windKt = $windMph !== null ? round((float) $windMph * 0.868976, 0) : null;
    $gustKt = ($gustMph !== null && (float) $gustMph > 0)
        ? round((float) $gustMph * 0.868976, 0)
        : null;

    return [
        'temperature' => $temperature,
        'humidity' => $humidity !== null ? (float) $humidity : null,
        'pressure' => $pressure !== null ? (float) $pressure : null,
        'wind_speed' => $windKt !== null ? (float) $windKt : null,
        'wind_direction' => $windDir !== null ? round((float) $windDir) : null,
        'gust_speed' => $gustKt,
        'precip_accum' => $precip !== null ? (float) $precip : null,
        'obs_time' => $obsTime,
        'last_bucket_iso' => $lastBucketIso,
    ];
}

/**
 * Read a numeric value from a Dyacon series at the anchor bucket (ISO match, then index).
 *
 * @param array<string, mixed>|null $series
 */
function dyaconliveSeriesValueAtBucket(?array $series, int $anchorIndex, ?string $bucketIso): ?float
{
    if (!is_array($series)) {
        return null;
    }

    $datetimes = $series['datetimes'] ?? null;
    $values = $series['values'] ?? null;
    if (!is_array($datetimes) || !is_array($values) || count($values) === 0) {
        return null;
    }

    if ($bucketIso !== null && $bucketIso !== '') {
        $matchedIndex = array_search($bucketIso, $datetimes, true);
        if ($matchedIndex !== false && isset($values[$matchedIndex]) && is_numeric($values[$matchedIndex])) {
            return (float) $values[$matchedIndex];
        }
    }

    if ($anchorIndex >= 0 && $anchorIndex < count($values) && is_numeric($values[$anchorIndex])) {
        return (float) $values[$anchorIndex];
    }

    return null;
}
