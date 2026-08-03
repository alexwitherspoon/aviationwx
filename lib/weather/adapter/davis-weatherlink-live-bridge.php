<?php
/**
 * Davis WeatherLink Live cache-backed adapter (bridge LAN push).
 *
 * Parses provider_meta.raw from WeatherLink Live Local API `/v1/current_conditions`
 * `data` objects stored under cache/bridges/.../weather/{source_id}/latest.json.
 *
 * Local data_structure_type (not cloud v2 numbering):
 *   1 = ISS, 2 = leaf/soil (ignored), 3 = barometer, 4 = inside T/H (ignored for outdoor)
 *
 * @see https://weatherlink.github.io/weatherlink-live-local-api/
 */

require_once __DIR__ . '/../data/WeatherReading.php';
require_once __DIR__ . '/../data/WindGroup.php';
require_once __DIR__ . '/../data/WeatherSnapshot.php';
require_once __DIR__ . '/../../bridge/weather-store.php';
require_once __DIR__ . '/../../bridge/config.php';
require_once __DIR__ . '/../../logger.php';
require_once __DIR__ . '/../../units.php';

use AviationWX\Weather\Data\WeatherReading;
use AviationWX\Weather\Data\WeatherSnapshot;
use AviationWX\Weather\Data\WindGroup;

const DAVIS_WLL_STRUCT_ISS = 1;
/** Leaf/soil packet - ignored for outdoor aviation snapshot */
const DAVIS_WLL_STRUCT_LEAF_SOIL = 2;
const DAVIS_WLL_STRUCT_BAR = 3;
/** Inside temp/hum packet - ignored for outdoor aviation snapshot */
const DAVIS_WLL_STRUCT_TEMP_HUM_IN = 4;

/**
 * Resolve a WeatherSnapshot from bridge cache for a davis_weatherlink_live enable row.
 *
 * @param string $airportId Airport id
 * @param array $source weather_sources entry
 * @param array|null $airport Airport config (enable re-check)
 * @return WeatherSnapshot|null
 */
function davisWeatherlinkLiveResolveSnapshot(
    string $airportId,
    array $source,
    ?array $airport = null
): ?WeatherSnapshot {
    $bridgeId = $source['bridge_id'] ?? null;
    $sourceId = $source['bridge_source_id'] ?? null;
    if (!is_string($bridgeId) || $bridgeId === '' || !is_string($sourceId) || $sourceId === '') {
        return null;
    }

    if (is_array($airport) && !isBridgeWeatherSourceEnabled($airport, $bridgeId, $sourceId)) {
        return null;
    }

    $latest = bridgeLoadWeatherLatest($airportId, $bridgeId, $sourceId);
    if ($latest === null) {
        return null;
    }

    return DavisWeatherlinkLiveBridgeAdapter::snapshotFromLatestRecord($latest, $source);
}

/**
 * Cache-backed Davis WeatherLink Live Local API adapter.
 */
class DavisWeatherlinkLiveBridgeAdapter
{
    public const SOURCE_TYPE = 'davis_weatherlink_live';

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

    /** Typical LAN poll interval (Davis Local API minimum) */
    public const UPDATE_FREQUENCY = 10;

    /**
     * @return list<string>
     */
    public static function getFieldsProvided(): array
    {
        return self::FIELDS_PROVIDED;
    }

    /**
     * Typical LAN poll interval in seconds (Davis Local API minimum).
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
     * Build WeatherSnapshot from a stored bridge latest.json record.
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

        $parsed = self::parseRawData($raw, $meta, $config);
        if ($parsed === null) {
            // parseRawData already logged the concrete reject reason
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
     * Parse WeatherLink Live Local API `data` object into SI-ish aviation fields.
     *
     * @param array $raw provider_meta.raw (did, ts, conditions[])
     * @param array $meta Full provider_meta
     * @param array $config weather_sources row (optional txid)
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
    public static function parseRawData(array $raw, array $meta = [], array $config = []): ?array
    {
        $conditions = $raw['conditions'] ?? null;
        if (!is_array($conditions) || $conditions === []) {
            self::logReject('empty_conditions');
            return null;
        }

        $issByTxid = [];
        $bar = null;
        foreach ($conditions as $row) {
            if (!is_array($row) || !isset($row['data_structure_type'])) {
                continue;
            }
            $type = (int) $row['data_structure_type'];
            if ($type === DAVIS_WLL_STRUCT_ISS) {
                $txid = isset($row['txid']) && is_numeric($row['txid']) ? (int) $row['txid'] : null;
                if ($txid === null) {
                    continue;
                }
                $issByTxid[$txid] = $row;
            } elseif ($type === DAVIS_WLL_STRUCT_BAR) {
                $bar = $row;
            }
        }

        if ($issByTxid === []) {
            self::logReject('no_iss_packets');
            return null;
        }

        $wantedTxid = self::resolveTxid($meta, $config, $issByTxid);
        if ($wantedTxid === null || !isset($issByTxid[$wantedTxid])) {
            self::logReject('txid_unresolved', [
                'wanted_txid' => $wantedTxid,
                'available_txids' => array_keys($issByTxid),
                'config_txid' => $config['txid'] ?? null,
                'meta_txid' => $meta['txid'] ?? null,
            ]);
            return null;
        }
        $iss = $issByTxid[$wantedTxid];

        // Fail closed without station ts - wall clock would make stale cache look fresh
        if (!isset($raw['ts']) || !is_numeric($raw['ts'])) {
            self::logReject('missing_raw_ts');
            return null;
        }
        $obsTime = (int) $raw['ts'];
        if ($obsTime <= 0) {
            self::logReject('invalid_raw_ts', ['raw_ts' => $obsTime]);
            return null;
        }
        // Future station ts bypasses WeatherReading staleness (negative age looks fresh)
        if ($obsTime > time() + BRIDGE_WEATHER_FUTURE_SKEW_SECONDS) {
            self::logReject('future_raw_ts', ['raw_ts' => $obsTime]);
            return null;
        }

        $tempF = self::numericOrNull($iss, 'temp');
        $hum = self::numericOrNull($iss, 'hum');
        $dewF = self::numericOrNull($iss, 'dew_point');

        // Prefer last; fall back to short averages only when last is missing
        $windMph = self::firstNumeric($iss, [
            'wind_speed_last',
            'wind_speed_avg_last_1_min',
            'wind_speed_avg_last_2_min',
            'wind_speed_avg_last_10_min',
        ]);
        $windDir = self::firstNumeric($iss, [
            'wind_dir_last',
            'wind_dir_scalar_avg_last_1_min',
            'wind_dir_scalar_avg_last_2_min',
            'wind_dir_scalar_avg_last_10_min',
        ]);
        $gustMph = self::firstNumeric($iss, [
            'wind_speed_hi_last_10_min',
            'wind_speed_hi_last_2_min',
        ]);

        $pressure = null;
        if (is_array($bar)) {
            // bar_absolute is station pressure - never use as altimeter/sea-level substitute
            $pressure = self::numericOrNull($bar, 'bar_sea_level');
        }

        $precip = self::rainfallDailyInches($iss);

        // Reject absurd Local API placeholder averages / sensor garbage
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
        if ($hum !== null) {
            if ($hum < 0.0 || $hum > 100.0) {
                $hum = null;
            }
        }
        if ($tempF !== null && ($tempF < -80.0 || $tempF > 150.0)) {
            $tempF = null;
        }
        if ($dewF !== null && ($dewF < -80.0 || $dewF > 150.0)) {
            $dewF = null;
        }
        if ($pressure !== null && ($pressure < 10.0 || $pressure > 70.0)) {
            $pressure = null;
        }

        return [
            'temperature' => $tempF !== null ? fahrenheitToCelsius($tempF) : null,
            'dewpoint' => $dewF !== null ? fahrenheitToCelsius($dewF) : null,
            'humidity' => $hum,
            'pressure' => $pressure,
            'wind_speed' => $windMph !== null ? mphToKnots($windMph) : null,
            'wind_direction' => $windDir,
            'gust_speed' => $gustMph !== null ? mphToKnots($gustMph) : null,
            'precip_accum' => $precip,
            'obs_time' => $obsTime,
        ];
    }

    /**
     * Convert rainfall_daily tip counts to inches using rain_size.
     *
     * rain_size: 1=0.01", 2=0.2mm, 3=0.1mm, 4=0.001"
     *
     * @param array $iss ISS condition row
     * @return float|null
     */
    public static function rainfallDailyInches(array $iss): ?float
    {
        $clicks = self::numericOrNull($iss, 'rainfall_daily');
        if ($clicks === null) {
            return null;
        }
        $size = isset($iss['rain_size']) && is_numeric($iss['rain_size'])
            ? (int) $iss['rain_size']
            : 0;
        $inchesPerClick = match ($size) {
            1 => 0.01,
            2 => mmToInches(0.2),
            3 => mmToInches(0.1),
            4 => 0.001,
            default => null,
        };
        if ($inchesPerClick === null) {
            return null;
        }
        return $clicks * $inchesPerClick;
    }

    /**
     * Log a Davis adapter reject and return null (snapshot callers).
     *
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
        aviationwx_log('warning', 'davis_weatherlink_live reject', array_merge([
            'reason' => $reason,
        ], $context), 'app');
    }

    /**
     * @param array $meta provider_meta
     * @param array $config weather_sources
     * @param array<int, array> $issByTxid
     * @return int|null
     */
    private static function resolveTxid(array $meta, array $config, array $issByTxid): ?int
    {
        foreach ([$config['txid'] ?? null, $meta['txid'] ?? null] as $candidate) {
            if (is_numeric($candidate)) {
                return (int) $candidate;
            }
        }
        if (count($issByTxid) === 1) {
            return (int) array_key_first($issByTxid);
        }
        return null;
    }

    /**
     * @param array $row Condition object
     * @param string $key Field
     * @return float|null
     */
    private static function numericOrNull(array $row, string $key): ?float
    {
        if (!array_key_exists($key, $row) || $row[$key] === null) {
            return null;
        }
        if (!is_numeric($row[$key])) {
            return null;
        }
        return (float) $row[$key];
    }

    /**
     * @param array $row Condition object
     * @param list<string> $keys Priority order
     * @return float|null
     */
    private static function firstNumeric(array $row, array $keys): ?float
    {
        foreach ($keys as $key) {
            $v = self::numericOrNull($row, $key);
            if ($v !== null) {
                return $v;
            }
        }
        return null;
    }
}
