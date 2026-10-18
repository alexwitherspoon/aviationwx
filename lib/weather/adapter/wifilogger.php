<?php
/**
 * WiFiLogger cache-backed adapter (direct upload push).
 *
 * Parses provider_meta.raw from the WiFiLogger "PHP & MySQL" HTTP export
 * (`type: rtd`), stored under cache/upload/weather/{airport}/{wid}/latest.json.
 *
 * Units are Davis-native integers, as documented for the WiFiLogger custom
 * export. Every scale below is isolated in a constant and fail-closed by range
 * check so a scaling drift degrades to a null field, never a wrong value. The
 * exact integer scaling must still be confirmed against the first real unit
 * (firmware 2.63 ship surface only documents the two URL fields).
 */

require_once __DIR__ . '/../data/WeatherReading.php';
require_once __DIR__ . '/../data/WindGroup.php';
require_once __DIR__ . '/../data/WeatherSnapshot.php';
require_once __DIR__ . '/../../upload/store.php';
require_once __DIR__ . '/../../upload/ingest.php';
require_once __DIR__ . '/../../logger.php';
require_once __DIR__ . '/../../units.php';

use AviationWX\Weather\Data\WeatherReading;
use AviationWX\Weather\Data\WeatherSnapshot;
use AviationWX\Weather\Data\WindGroup;

/** temperature / dew point: 0.1 F per integer */
const WIFILOGGER_TEMP_SCALE = 10;
/** altimeter / barometer: 0.001 inHg per integer */
const WIFILOGGER_PRESSURE_SCALE = 1000;
/** daily rain: 0.01 in per integer */
const WIFILOGGER_RAIN_SCALE = 100;

/**
 * Resolve a WeatherSnapshot from upload cache for a wifilogger weather_sources row.
 *
 * @param string $airportId Airport id
 * @param array $source weather_sources entry
 * @param array|null $airport Airport config (unused; enable is the row itself)
 * @return WeatherSnapshot|null
 */
function wifiloggerResolveSnapshot(
    string $airportId,
    array $source,
    ?array $airport = null
): ?WeatherSnapshot {
    unset($airport);
    $wid = $source['wid'] ?? null;
    if (!is_string($wid) || $wid === '') {
        return null;
    }

    $latest = uploadLoadWeatherLatest($airportId, $wid);
    if ($latest === null) {
        return null;
    }

    return WifiLoggerAdapter::snapshotFromLatestRecord($latest, $source);
}

/**
 * Cache-backed WiFiLogger adapter.
 */
class WifiLoggerAdapter
{
    public const SOURCE_TYPE = 'wifilogger';

    public const FIELDS_PROVIDED = [
        'temperature',
        'dewpoint',
        'humidity',
        'pressure',
        'wind_speed',
        'wind_direction',
        'gust_speed',
        'precip_accum',
    ];

    /** Typical configured push interval in seconds. */
    public const UPDATE_FREQUENCY = 60;

    /**
     * @return list<string>
     */
    public static function getFieldsProvided(): array
    {
        return self::FIELDS_PROVIDED;
    }

    /**
     * Typical push interval in seconds.
     *
     * @return int
     */
    public static function getTypicalUpdateFrequency(): int
    {
        return self::UPDATE_FREQUENCY;
    }

    /**
     * @return string
     */
    public static function getSourceType(): string
    {
        return self::SOURCE_TYPE;
    }

    /**
     * Build WeatherSnapshot from a stored upload latest.json record.
     *
     * Wind direction is assumed true north (properly installed vane).
     *
     * @param array $record Stored observation (provider_meta.raw required)
     * @param array $config weather_sources row
     * @return WeatherSnapshot|null
     */
    public static function snapshotFromLatestRecord(array $record, array $config = []): ?WeatherSnapshot
    {
        if (($record['provider'] ?? null) !== self::SOURCE_TYPE) {
            return self::reject('provider_mismatch', [
                'provider' => $record['provider'] ?? null,
                'expected' => self::SOURCE_TYPE,
            ]);
        }

        $meta = $record['provider_meta'] ?? null;
        if (!is_array($meta)) {
            return self::reject('missing_provider_meta');
        }
        $raw = $meta['raw'] ?? null;
        if (!is_array($raw) || $raw === []) {
            return self::reject('missing_provider_meta_raw');
        }

        $parsed = self::parseRawData($raw);
        if ($parsed === null) {
            return null;
        }

        $obsTime = $parsed['obs_time'];
        $windDir = $parsed['wind_direction'];

        $source = self::SOURCE_TYPE;
        $stationId = isset($config['station_id']) && is_string($config['station_id']) && $config['station_id'] !== ''
            ? $config['station_id']
            : null;

        $hasCompleteWind = $parsed['wind_speed'] !== null && $windDir !== null;
        $hasAny = $parsed['temperature'] !== null
            || $parsed['humidity'] !== null
            || $parsed['pressure'] !== null
            || $parsed['precip_accum'] !== null
            || $hasCompleteWind;

        if (!$hasAny) {
            return self::reject('no_usable_fields', ['obs_time' => $obsTime]);
        }

        return new WeatherSnapshot(
            source: $source,
            fetchTime: time(),
            temperature: WeatherReading::celsius($parsed['temperature'], $source, $obsTime),
            dewpoint: WeatherReading::celsius($parsed['dewpoint'], $source, $obsTime),
            humidity: WeatherReading::percent($parsed['humidity'], $source, $obsTime),
            pressure: WeatherReading::inHg($parsed['pressure'], $source, $obsTime),
            precipAccum: WeatherReading::inches($parsed['precip_accum'], $source, $obsTime),
            wind: $hasCompleteWind
                ? WindGroup::from(
                    $parsed['wind_speed'],
                    $windDir,
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
            stationId: $stationId
        );
    }

    /**
     * Parse a WiFiLogger `type: rtd` raw object into SI-ish aviation fields.
     *
     * @param array $raw provider_meta.raw (the rtd payload)
     * @return array{
     *   temperature: ?float,
     *   dewpoint: ?float,
     *   humidity: ?float,
     *   pressure: ?float,
     *   wind_speed: ?float,
     *   wind_direction: ?float,
     *   gust_speed: ?float,
     *   precip_accum: ?float,
     *   obs_time: int
     * }|null
     */
    public static function parseRawData(array $raw): ?array
    {
        // Fail closed without station time - wall clock or envelope time would make stale cache look fresh.
        if (!isset($raw['tutc']) || !is_numeric($raw['tutc'])) {
            self::logReject('missing_raw_tutc');
            return null;
        }
        $obsTime = (int) $raw['tutc'];
        if ($obsTime <= 0) {
            self::logReject('invalid_raw_tutc', ['raw_tutc' => $obsTime]);
            return null;
        }
        if ($obsTime > time() + UPLOAD_WEATHER_FUTURE_SKEW_SECONDS) {
            self::logReject('future_raw_tutc', ['raw_tutc' => $obsTime]);
            return null;
        }

        $tempF = self::scaled($raw, 'tempout', WIFILOGGER_TEMP_SCALE);
        $dewF = self::scaled($raw, 'dew', WIFILOGGER_TEMP_SCALE);
        $hum = self::intOrNull($raw, 'humout');
        $windMph = self::intOrNull($raw, 'windspd');
        $windDir = self::intOrNull($raw, 'winddir');
        $gustMph = self::intOrNull($raw, 'gust');
        $rainIn = self::scaled($raw, 'raindly', WIFILOGGER_RAIN_SCALE);

        // Altimeter over raw barometer (altimeter is sea-level reduced, what pilots see).
        // Range-check the altimeter first so an out-of-range value falls back to bar.
        $pressureInHg = self::scaled($raw, 'altime', WIFILOGGER_PRESSURE_SCALE);
        if ($pressureInHg === null || $pressureInHg < 20.0 || $pressureInHg > 45.0) {
            $pressureInHg = self::scaled($raw, 'bar', WIFILOGGER_PRESSURE_SCALE);
        }

        if ($tempF !== null && ($tempF < -80.0 || $tempF > 150.0)) {
            $tempF = null;
        }
        if ($dewF !== null && ($dewF < -80.0 || $dewF > 150.0)) {
            $dewF = null;
        }
        if ($hum !== null && ($hum < 0.0 || $hum > 100.0)) {
            $hum = null;
        }
        if ($windMph !== null && ($windMph < 0.0 || $windMph > 250.0)) {
            $windMph = null;
        }
        if ($gustMph !== null && ($gustMph < 0.0 || $gustMph > 250.0)) {
            $gustMph = null;
        }
        if ($windDir !== null) {
            if ($windDir < 0.0 || $windDir > 360.0) {
                $windDir = null;
            } else {
                $windDir = fmod($windDir, 360.0);
                if ($windDir < 0.0) {
                    $windDir += 360.0;
                }
            }
        }
        if ($pressureInHg !== null && ($pressureInHg < 20.0 || $pressureInHg > 45.0)) {
            $pressureInHg = null;
        }
        if ($rainIn !== null && (!is_finite($rainIn) || $rainIn < 0.0 || $rainIn > 100.0)) {
            $rainIn = null;
        }

        return [
            'temperature' => $tempF !== null ? fahrenheitToCelsius($tempF) : null,
            'dewpoint' => $dewF !== null ? fahrenheitToCelsius($dewF) : null,
            'humidity' => $hum,
            'pressure' => $pressureInHg,
            'wind_speed' => $windMph !== null ? mphToKnots($windMph) : null,
            'wind_direction' => $windDir,
            'gust_speed' => $gustMph !== null ? mphToKnots($gustMph) : null,
            'precip_accum' => $rainIn,
            'obs_time' => $obsTime,
        ];
    }

    /**
     * @param string $reason Stable reject token
     * @param array<string, mixed> $context Extra fields
     * @return null
     */
    private static function reject(string $reason, array $context = []): ?WeatherSnapshot
    {
        self::logReject($reason, $context);
        return null;
    }

    /**
     * @param string $reason Stable reject token
     * @param array<string, mixed> $context Extra fields
     * @return void
     */
    private static function logReject(string $reason, array $context = []): void
    {
        aviationwx_log('warning', 'wifilogger reject', array_merge([
            'reason' => $reason,
        ], $context), 'app');
    }

    /**
     * Read an integer Davis-native field and divide by its unit scale.
     *
     * @param array $row Raw rtd payload
     * @param string $key Field name
     * @param int $scale Divisor (e.g. 10 for 0.1 F)
     * @return float|null
     */
    private static function scaled(array $row, string $key, int $scale): ?float
    {
        $value = self::intOrNull($row, $key);
        return $value === null ? null : $value / $scale;
    }

    /**
     * @param array $row Raw rtd payload
     * @param string $key Field name
     * @return float|null
     */
    private static function intOrNull(array $row, string $key): ?float
    {
        if (!array_key_exists($key, $row) || $row[$key] === null) {
            return null;
        }
        if (!is_numeric($row[$key])) {
            return null;
        }
        $value = (float) $row[$key];
        // Davis-native integers only: a fractional value means the payload is not in
        // the assumed integer scale, so null it rather than divide-and-misinterpret.
        if (floor($value) !== $value) {
            return null;
        }
        return $value;
    }
}