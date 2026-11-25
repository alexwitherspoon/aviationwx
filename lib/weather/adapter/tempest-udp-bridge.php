<?php
/**
 * WeatherFlow Tempest UDP bridge adapter (bridge LAN push).
 *
 * Parses provider_meta.raw from a Tempest UDP broadcast datagram, as stored
 * under cache/bridges/.../weather/{source_id}/latest.json. The bridge
 * forwards only the full obs_st report, verbatim; partial sensor datagrams
 * (obs_air, obs_sky, rapid_wind, evt_*) are not forwarded.
 *
 * Field layouts per v171 of the WeatherFlow UDP reference
 * (https://weatherflow.github.io/Tempest/api/udp/): obs_st is the
 * station's full report (pressure, temperature, humidity, wind) and the
 * only type parsed here. obs_air carries temperature/humidity/pressure and
 * obs_sky and rapid_wind carry wind only; they are rejected below even if
 * one lands in the store, because the store keeps one latest record per
 * source and a partial record would publish a snapshot missing the other
 * fields until the next full report. All values are metric-native
 * (m/s, C, MB, mm).
 *
 * Fail-closed notes:
 * - Station pressure (MB) is raw sensor pressure, not sea-level. It is only
 *   reported as a snapshot pressure when the airport provides elevation_ft,
 *   via stationPressureToAltimeterSettingInHg() (US standard reduction).
 * - Dewpoint is not broadcast over UDP (no field), and daily rain
 *   accumulation is a REST/cloud field, so neither is reported here.
 */

require_once __DIR__ . '/../data/WeatherReading.php';
require_once __DIR__ . '/../data/WindGroup.php';
require_once __DIR__ . '/../data/WeatherSnapshot.php';
require_once __DIR__ . '/../../bridge/weather-store.php';
require_once __DIR__ . '/../../bridge/config.php';
require_once __DIR__ . '/../../logger.php';
require_once __DIR__ . '/../../units.php';
require_once __DIR__ . '/../calculator.php';
require_once __DIR__ . '/../station-clock.php';

use AviationWX\Weather\Data\WeatherReading;
use AviationWX\Weather\Data\WeatherSnapshot;
use AviationWX\Weather\Data\WindGroup;

/** Sane instrument bounds for UDP metric values (absurdity filtering). */
const TEMPEST_UDP_WIND_MAX_MPS = 90.0;   // ~200 mph; any real gust is far below
const TEMPEST_UDP_TEMP_MIN_C = -80.0;
const TEMPEST_UDP_TEMP_MAX_C = 65.0;
const TEMPEST_UDP_HUM_MAX_PCT = 100.0;
const TEMPEST_UDP_PRESSURE_MIN_MB = 500.0;   // high-elevation stations read low
const TEMPEST_UDP_PRESSURE_MAX_MB = 1100.0;

/**
 * Resolve a WeatherSnapshot from bridge cache for a tempest_udp enable row.
 *
 * @param string $airportId Airport id
 * @param array $source weather_sources entry
 * @param array|null $airport Airport config (enable re-check and elevation_ft)
 * @return WeatherSnapshot|null
 */
function tempestUDPResolveSnapshot(
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

    return TempestUDPBridgeAdapter::snapshotFromLatestRecord($latest, $source, $airport);
}

/**
 * Cache-backed WeatherFlow Tempest UDP adapter.
 */
class TempestUDPBridgeAdapter
{
    public const SOURCE_TYPE = 'tempest_udp';

    public const FIELDS_PROVIDED = [
        'temperature',
        'humidity',
        'pressure',
        'wind_speed',
        'wind_direction',
        'gust_speed',
    ];

    /** Tempest default report interval (obs_st ships once per report). */
    public const UPDATE_FREQUENCY = 60;

    /**
     * @return list<string>
     */
    public static function getFieldsProvided(): array
    {
        return self::FIELDS_PROVIDED;
    }

    /**
     * Typical report interval in seconds.
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
     * @param array|null $airport Airport config (elevation_ft for pressure)
     * @return WeatherSnapshot|null
     */
    public static function snapshotFromLatestRecord(array $record, array $config = [], ?array $airport = null): ?WeatherSnapshot
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

        $parsed = self::parseRawData($raw, $meta, $config, $airport);
        if ($parsed === null) {
            return null;
        }

        // The Tempest UDP spec defines the datagram epoch as UTC, so the
        // deterministic recency guard applies rather than framing: a stale or
        // future device clock fails closed instead of publishing aged cache.
        $clockOk = stationClockPlausibleUtc($parsed['obs_time'], time());
        if (!$clockOk['ok']) {
            self::logReject($clockOk['reason'], ['epoch' => $parsed['obs_time']]);
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
            precipAccum: WeatherReading::null($source),
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
     * Parse one Tempest UDP datagram into SI-ish aviation fields.
     *
     * @param array $raw provider_meta.raw (the broadcast datagram JSON)
     * @param array $meta Full provider_meta
     * @param array $config weather_sources row
     * @param array|null $airport Airport config (elevation_ft for pressure)
     * @return array{
     *   temperature: ?float,
     *   humidity: ?float,
     *   pressure: ?float,
     *   wind_speed: ?float,
     *   wind_direction: ?float,
     *   gust_speed: ?float,
     *   obs_time: int
     * }|null
     */
    public static function parseRawData(array $raw, array $meta = [], array $config = [], ?array $airport = null): ?array
    {
        $type = $raw['type'] ?? null;
        if (!is_string($type) || $type === '') {
            self::logReject('missing_datagram_type');
            return null;
        }
        if ($type !== 'obs_st') {
            // obs_air, obs_sky, rapid_wind, and evt_* are partial sensor
            // feeds. The store keeps one latest record per source, so a
            // partial record here would publish a snapshot missing the
            // fields it does not carry until the next full report. Only
            // the full station report is a publishable observation.
            self::logReject('partial_datagram_type', ['type' => $type]);
            return null;
        }

        $fields = self::datagramFields($type, $raw);
        if ($fields === null) {
            // datagramFields already logged the concrete reject reason
            return null;
        }

        $obsTime = $fields['obs_time'];
        if ($obsTime > time() + BRIDGE_WEATHER_FUTURE_SKEW_SECONDS) {
            // Future datagram epoch would bypass WeatherReading staleness
            self::logReject('future_epoch', ['epoch' => $obsTime]);
            return null;
        }

        $tempC = self::boundOrNull($fields['temperature'], TEMPEST_UDP_TEMP_MIN_C, TEMPEST_UDP_TEMP_MAX_C);
        $hum = self::boundOrNull($fields['humidity'], 0.0, TEMPEST_UDP_HUM_MAX_PCT);
        $windMps = self::boundOrNull($fields['wind_speed'], 0.0, TEMPEST_UDP_WIND_MAX_MPS);
        $gustMps = self::boundOrNull($fields['gust_speed'], 0.0, TEMPEST_UDP_WIND_MAX_MPS);
        $windDir = null;
        if ($fields['wind_direction'] !== null) {
            $windDir = (float) $fields['wind_direction'];
            if ($windDir < 0.0 || $windDir > 360.0) {
                $windDir = null;
            } else {
                $windDir = fmod($windDir, 360.0);
                if ($windDir < 0.0) {
                    $windDir += 360.0;
                }
            }
        }

        $pressure = null;
        if ($fields['pressure_mb'] !== null) {
            $mb = self::boundOrNull($fields['pressure_mb'], TEMPEST_UDP_PRESSURE_MIN_MB, TEMPEST_UDP_PRESSURE_MAX_MB);
            if ($mb !== null) {
                // Station pressure only becomes a snapshot value with a real
                // field elevation; otherwise it is not an altimeter setting.
                $elevationFt = null;
                if (is_array($airport) && isset($airport['elevation_ft'])) {
                    $elevationFt = is_numeric($airport['elevation_ft']) ? (float) $airport['elevation_ft'] : null;
                }
                if ($elevationFt !== null) {
                    $pressure = stationPressureToAltimeterSettingInHg(hpaToInhg($mb), $elevationFt);
                    if ($pressure === null) {
                        self::logReject('pressure_conversion_failed', ['mb' => $mb, 'elevation_ft' => $elevationFt]);
                    }
                }
            }
        }

        return [
            'temperature' => $tempC,
            'humidity' => $hum,
            'pressure' => $pressure,
            'wind_speed' => $windMps !== null ? mpsToKnots($windMps) : null,
            'wind_direction' => $windDir,
            'gust_speed' => $gustMps !== null ? mpsToKnots($gustMps) : null,
            'obs_time' => $obsTime,
        ];
    }

    /**
     * Extract fields from the full obs_st report. Partial sensor types are
     * rejected before this point (see parseRawData).
     *
     * @param string $type Datagram type (obs_st)
     * @param array $raw provider_meta.raw
     * @return array{
     *   obs_time: int,
     *   temperature: ?float,
     *   humidity: ?float,
     *   pressure_mb: ?float,
     *   wind_speed: ?float,
     *   wind_direction: ?float,
     *   gust_speed: ?float
     * }|null
     */
    private static function datagramFields(string $type, array $raw): ?array
    {
        $out = [
            'obs_time' => 0,
            'temperature' => null,
            'humidity' => null,
            'pressure_mb' => null,
            'wind_speed' => null,
            'wind_direction' => null,
            'gust_speed' => null,
        ];

        // [epoch, lull, avg, gust, dir, sample_int, pressure, temp, hum, ...]
        $arr = self::firstArray($raw, 'obs');
        if ($arr !== null) {
            $out['obs_time'] = self::epochAt($arr, 0);
            $out['pressure_mb'] = self::floatAt($arr, 6);
            $out['temperature'] = self::floatAt($arr, 7);
            $out['humidity'] = self::floatAt($arr, 8);
            $out['wind_speed'] = self::floatAt($arr, 2);
            $out['gust_speed'] = self::floatAt($arr, 3);
            $out['wind_direction'] = self::floatAt($arr, 4);
        }
        if ($out['obs_time'] <= 0) {
            self::logReject('invalid_epoch', ['type' => $type]);
            return null;
        }
        return $out;
    }

    /**
     * @param array $raw Datagram
     * @param string $key Observation key (obs)
     * @return array|null First observation array
     */
    private static function firstArray(array $raw, string $key): ?array
    {
        $rows = $raw[$key] ?? null;
        if (!is_array($rows) || $rows === []) {
            return null;
        }
        $first = $rows[0];
        return is_array($first) ? $first : null;
    }

    /**
     * @param array $arr Observation array
     * @param int $i Index
     * @return int 0 when missing
     */
    private static function epochAt(array $arr, int $i): int
    {
        $v = $arr[$i] ?? null;
        return is_numeric($v) ? (int) round((float) $v) : 0;
    }

    /**
     * @param array $arr Observation array
     * @param int $i Index
     * @return float|null
     */
    private static function floatAt(array $arr, int $i): ?float
    {
        $v = $arr[$i] ?? null;
        return is_numeric($v) ? (float) $v : null;
    }

    /**
     * @param mixed $v Value
     * @param float $min Inclusive lower bound
     * @param float $max Inclusive upper bound
     * @return float|null
     */
    private static function boundOrNull(mixed $v, float $min, float $max): ?float
    {
        if ($v === null) {
            return null;
        }
        $f = (float) $v;
        return ($f >= $min && $f <= $max) ? $f : null;
    }

    /**
     * Log a Tempest adapter reject and return null (snapshot callers).
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
        aviationwx_log('warning', 'tempest_udp reject', array_merge([
            'reason' => $reason,
        ], $context), 'app');
    }
}