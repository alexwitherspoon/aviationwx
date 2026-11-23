<?php
/**
 * Cache-backed MQTT push adapter (bridge LAN subscriber).
 *
 * Parses provider_meta.raw from an MQTT weather publish, as stored under
 * cache/bridges/.../weather/{source_id}/latest.json. The bridge forwards the
 * publisher payload verbatim, so this adapter applies a documented canonical
 * schema: operators publishing MQTT weather use these exact keys and units.
 *
 * Canonical schema (all fields optional; unknown keys are ignored):
 *
 *   {
 *     "temperature_c": 21.5,    # outdoor air temperature, C
 *     "humidity": 55,           # relative humidity, %
 *     "pressure_inhg": 30.01,   # sea-level barometer, inHg (altimeter-style)
 *     "wind_kt": 12,            # wind speed, knots
 *     "gust_kt": 18,            # gust speed, knots
 *     "wind_dir_deg": 270,      # true-north wind direction, degrees
 *     "precip_in": 0.03         # daily precipitation accumulation, inches
 *   }
 *
 * observed_at is the bridge's message arrival time (the MQTT push exception),
 * read from the stored record. Units are already aviation-native, so no
 * conversion happens here; the adapter's job is provider identity, range
 * validation, and snapshot composition. Absurd values and future timestamps
 * reject with stable tokens.
 */

require_once __DIR__ . '/../data/WeatherReading.php';
require_once __DIR__ . '/../data/WindGroup.php';
require_once __DIR__ . '/../data/WeatherSnapshot.php';
require_once __DIR__ . '/../../bridge/weather-store.php';
require_once __DIR__ . '/../../bridge/config.php';
require_once __DIR__ . '/../../logger.php';

use AviationWX\Weather\Data\WeatherReading;
use AviationWX\Weather\Data\WeatherSnapshot;
use AviationWX\Weather\Data\WindGroup;

/** Sane instrument bounds for the canonical schema (aviation-native units). */
const MQTT_TEMP_MIN_C = -80.0;
const MQTT_TEMP_MAX_C = 65.0;
const MQTT_HUM_MAX_PCT = 100.0;
const MQTT_PRESSURE_MIN_INHG = 10.0;
const MQTT_PRESSURE_MAX_INHG = 32.0;
const MQTT_WIND_MAX_KT = 217.0;  // ~250 mph
const MQTT_PRECIP_MAX_IN = 20.0;

/**
 * Resolve a WeatherSnapshot from bridge cache for an mqtt enable row.
 *
 * @param string $airportId Airport id
 * @param array $source weather_sources entry
 * @param array|null $airport Airport config (enable re-check)
 * @return WeatherSnapshot|null
 */
function mqttResolveSnapshot(
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

    return MqttBridgeAdapter::snapshotFromLatestRecord($latest, $source);
}

/**
 * Cache-backed MQTT adapter applying the canonical weather schema.
 */
class MqttBridgeAdapter
{
    public const SOURCE_TYPE = 'mqtt';

    public const FIELDS_PROVIDED = [
        'temperature',
        'humidity',
        'pressure',
        'wind_speed',
        'wind_direction',
        'gust_speed',
        'precip_accum',
    ];

    /** MQTT is a low-latency push; emits are thinned to 1 Hz bridge-side. */
    public const UPDATE_FREQUENCY = 10;

    /**
     * @return list<string>
     */
    public static function getFieldsProvided(): array
    {
        return self::FIELDS_PROVIDED;
    }

    /**
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
     * Wind direction is true north per the canonical schema.
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
        $diagnosticError = bridgeWeatherDiagnosticError($record);
        if ($diagnosticError !== null) {
            return self::reject('bridge_diagnostic', ['error' => $diagnosticError]);
        }
        $raw = $meta['raw'] ?? null;
        if (!is_array($raw) || $raw === []) {
            return self::reject('missing_provider_meta_raw');
        }

        $obsTime = self::observedTimeFromRecord($record);
        if ($obsTime === null) {
            return null;
        }

        $parsed = self::parseRawData($raw);
        if ($parsed === null) {
            return null;
        }

        $source = self::SOURCE_TYPE;
        $stationId = isset($config['station_id']) && is_string($config['station_id']) && $config['station_id'] !== ''
            ? $config['station_id']
            : null;

        $hasCompleteWind = $parsed['wind_speed'] !== null && $parsed['wind_direction'] !== null;
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
            dewpoint: WeatherReading::null($source),
            humidity: WeatherReading::percent($parsed['humidity'], $source, $obsTime),
            pressure: WeatherReading::inHg($parsed['pressure'], $source, $obsTime),
            precipAccum: WeatherReading::inches($parsed['precip_accum'], $source, $obsTime),
            wind: $hasCompleteWind
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
            stationId: $stationId
        );
    }

    /**
     * Parse a canonical-schema payload into aviation-native fields.
     *
     * Unknown keys are ignored so the schema can grow without breaking ingest.
     *
     * @param array $raw provider_meta.raw (the forwarded MQTT JSON object)
     * @return array{
     *   temperature: ?float,
     *   humidity: ?float,
     *   pressure: ?float,
     *   wind_speed: ?float,
     *   wind_direction: ?float,
     *   gust_speed: ?float,
     *   precip_accum: ?float
     * }|null
     */
    public static function parseRawData(array $raw): ?array
    {
        $tempC = self::boundOrNull($raw['temperature_c'] ?? null, MQTT_TEMP_MIN_C, MQTT_TEMP_MAX_C);
        $hum = self::boundOrNull($raw['humidity'] ?? null, 0.0, MQTT_HUM_MAX_PCT);
        $pressure = self::boundOrNull($raw['pressure_inhg'] ?? null, MQTT_PRESSURE_MIN_INHG, MQTT_PRESSURE_MAX_INHG);
        $windKt = self::boundOrNull($raw['wind_kt'] ?? null, 0.0, MQTT_WIND_MAX_KT);
        $gustKt = self::boundOrNull($raw['gust_kt'] ?? null, 0.0, MQTT_WIND_MAX_KT);
        $precip = self::boundOrNull($raw['precip_in'] ?? null, 0.0, MQTT_PRECIP_MAX_IN);

        $direction = null;
        if (isset($raw['wind_dir_deg']) && is_numeric($raw['wind_dir_deg'])) {
            $direction = (float) $raw['wind_dir_deg'];
            if ($direction === 360.0) {
                $direction = 0.0;
            }
            if ($direction < 0.0 || $direction > 360.0) {
                $direction = null;
            }
        }

        return [
            'temperature' => $tempC,
            'humidity' => $hum,
            'pressure' => $pressure,
            'wind_speed' => $windKt,
            'wind_direction' => $direction,
            'gust_speed' => $gustKt,
            'precip_accum' => $precip,
        ];
    }

    /**
     * Derive the observation epoch from the stored record (bridge arrival).
     *
     * @param array $record Stored record
     * @return int|null
     */
    private static function observedTimeFromRecord(array $record): ?int
    {
        $observedAt = $record['observed_at'] ?? null;
        if (!is_string($observedAt) || $observedAt === '') {
            self::logReject('missing_observed_at');
            return null;
        }
        $ts = strtotime($observedAt);
        if ($ts === false || $ts <= 0) {
            self::logReject('invalid_observed_at', ['observed_at' => $observedAt]);
            return null;
        }
        if ($ts > time() + BRIDGE_WEATHER_FUTURE_SKEW_SECONDS) {
            self::logReject('future_observed_at', ['epoch' => $ts]);
            return null;
        }
        return $ts;
    }

    /**
     * @param mixed $v Value
     * @param float $min Inclusive lower bound
     * @param float $max Inclusive upper bound
     * @return float|null
     */
    private static function boundOrNull(mixed $v, float $min, float $max): ?float
    {
        if (!is_numeric($v)) {
            return null;
        }
        $f = (float) $v;
        return ($f >= $min && $f <= $max) ? $f : null;
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
        aviationwx_log('warning', 'mqtt reject', array_merge([
            'reason' => $reason,
        ], $context), 'app');
    }
}