<?php
/**
 * Davis WeatherLinkIP / legacy Vantage LOOP adapter (bridge LAN pull).
 *
 * Parses provider_meta.raw from a legacy Davis IP logger LOOP1 packet, as
 * stored under cache/bridges/.../weather/{source_id}/latest.json. The bridge
 * forwards the raw 99-byte LOOP1 packet as loop_base64 / loop_hex, CRC already
 * verified at the bridge; this adapter re-verifies the CRC and decodes the
 * 94-byte record per the weewx vantage LOOP1 layout.
 *
 * LOOP1 carries barometer (inHg * 1000), outside temp (F * 10), wind speed
 * (mph), wind dir (degrees), outside humidity (%), and day rain (inches * 100).
 * There is no gust field in LOOP1 (that is LOOP2), so gust stays unset rather
 * than invented. Dewpoint is also LOOP2-only.
 *
 * Unit requirement: the console must run its US-unit configuration (inHg, F,
 * mph, 0.01in rain bucket). The LOOP packet ships raw console-unit values, and
 * there is no per-packet unit tag. A metric-configured console would return
 * garbage and the absurdity bounds below reject it.
 *
 * observed_at comes from the console clock via GETTIME: a naive wall-clock
 * value with no timezone. The clock is framed by stationClockFrameNaive():
 * aligned to UTC it is accepted as UTC, aligned to the site timezone it is
 * identified as local and normalized to UTC; a value matching no frame is
 * unidentifiable and fails closed until the console is corrected.
 */

require_once __DIR__ . '/../data/WeatherReading.php';
require_once __DIR__ . '/../data/WindGroup.php';
require_once __DIR__ . '/../data/WeatherSnapshot.php';
require_once __DIR__ . '/../station-clock.php';
require_once __DIR__ . '/../../bridge/weather-store.php';
require_once __DIR__ . '/../../bridge/config.php';
require_once __DIR__ . '/../../logger.php';
require_once __DIR__ . '/../../units.php';

use AviationWX\Weather\Data\WeatherReading;
use AviationWX\Weather\Data\WeatherSnapshot;
use AviationWX\Weather\Data\WindGroup;

/** Legacy WeatherLinkIP LOOP1 packet is 99 bytes (94 record + 2 CRC + 3 spare). */
const DAVIS_LOOP_PACKET_BYTES = 99;
/** Absent-marker values used by the console for LOOP1 fields. */
const DAVIS_LOOP_U8_NONE = 0xff;
const DAVIS_LOOP_I16_NONE = 0x7fff;
const DAVIS_LOOP_U16_NONE = 0xffff;
/** Sane instrument bounds (US units as shipped on the wire). */
const DAVIS_LOOP_BARO_MIN_INHG = 10.0;
const DAVIS_LOOP_BARO_MAX_INHG = 32.0;
const DAVIS_LOOP_TEMP_MIN_F = -80.0;
const DAVIS_LOOP_TEMP_MAX_F = 150.0;
const DAVIS_LOOP_WIND_MAX_MPH = 250.0;

/**
 * Resolve a WeatherSnapshot from bridge cache for a davis_weatherlink_ip row.
 *
 * @param string $airportId Airport id
 * @param array $source weather_sources entry
 * @param array|null $airport Airport config (enable re-check)
 * @return WeatherSnapshot|null
 */
function davisWeatherlinkIPResolveSnapshot(
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

    return DavisWeatherlinkIPBridgeAdapter::snapshotFromLatestRecord($latest, $source, $airport);
}

/**
 * Cache-backed legacy Davis WeatherLinkIP LOOP adapter.
 */
class DavisWeatherlinkIPBridgeAdapter
{
    /** One-time local-framing notice per process (avoids per-poll log spam). */
    private static bool $localFramingNoted = false;
    public const SOURCE_TYPE = 'davis_weatherlink_ip';

    public const FIELDS_PROVIDED = [
        'temperature',
        'humidity',
        'pressure',
        'wind_speed',
        'wind_direction',
        'precip_accum',
    ];

    /** Davis poll floor for the legacy IP logger (bridge enforces 60s). */
    public const UPDATE_FREQUENCY = 60;

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
     * Wind direction is assumed true north (properly installed vane).
     *
     * @param array $record Stored observation (provider_meta.raw required)
     * @param array $config weather_sources row
     * @param array|null $airport Airport config (site timezone for clock framing)
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

        $obsTime = self::observedTimeFromRecord($record, $airport);
        if ($obsTime === null) {
            return null;
        }

        $parsed = self::parseRawData($raw, $meta, $config);
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
                    null,
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
     * Parse a LOOP1 packet into SI-ish aviation fields.
     *
     * @param array $raw provider_meta.raw (loop_base64 / loop_hex)
     * @param array $meta Full provider_meta
     * @param array $config weather_sources row
     * @return array{
     *   temperature: ?float,
     *   humidity: ?float,
     *   pressure: ?float,
     *   wind_speed: ?float,
     *   wind_direction: ?float,
     *   precip_accum: ?float
     * }|null
     */
    public static function parseRawData(array $raw, array $meta = [], array $config = []): ?array
    {
        $packet = self::decodePacket($raw);
        if ($packet === null) {
            return null; // decodePacket logged the concrete reject reason
        }

        $barometer = self::le16($packet, 7);
        $outTempRaw = self::le16($packet, 12);
        $outHumidity = $packet[33] ?? DAVIS_LOOP_U8_NONE;
        $windSpeed = $packet[14] ?? DAVIS_LOOP_U8_NONE;
        $windDir = self::le16($packet, 16);
        $dayRain = self::le16($packet, 50);

        $pressure = null;
        if ($barometer !== DAVIS_LOOP_U16_NONE) {
            $pressure = $barometer / 1000.0;
            if ($pressure < DAVIS_LOOP_BARO_MIN_INHG || $pressure > DAVIS_LOOP_BARO_MAX_INHG) {
                $pressure = null;
            }
        }

        $tempF = null;
        if ($outTempRaw !== DAVIS_LOOP_I16_NONE) {
            $signed = $outTempRaw;
            if ($signed >= 0x8000) {
                $signed -= 0x10000;
            }
            $tempF = $signed / 10.0;
            if ($tempF < DAVIS_LOOP_TEMP_MIN_F || $tempF > DAVIS_LOOP_TEMP_MAX_F) {
                $tempF = null;
            }
        }

        $hum = $outHumidity === DAVIS_LOOP_U8_NONE ? null : (float) $outHumidity;
        if ($hum !== null && ($hum < 0.0 || $hum > 100.0)) {
            $hum = null;
        }

        $windMph = $windSpeed === DAVIS_LOOP_U8_NONE ? null : (float) $windSpeed;
        if ($windMph !== null && ($windMph < 0.0 || $windMph > DAVIS_LOOP_WIND_MAX_MPH)) {
            $windMph = null;
        }

        $direction = null;
        if ($windDir !== DAVIS_LOOP_U16_NONE && $windDir !== 0) {
            $direction = (float) $windDir;
            if ($direction === 360.0) {
                $direction = 0.0;
            }
            if ($direction < 0.0 || $direction > 360.0) {
                $direction = null;
            }
        }

        $precip = null;
        if ($dayRain !== DAVIS_LOOP_U16_NONE) {
            $precip = $dayRain / 100.0;
            if ($precip < 0.0 || $precip > 20.0) {
                // 20 inches of day rain is far beyond any sane station
                $precip = null;
            }
        }

        return [
            'temperature' => $tempF !== null ? fahrenheitToCelsius($tempF) : null,
            'humidity' => $hum,
            'pressure' => $pressure,
            'wind_speed' => $windMph !== null ? mphToKnots($windMph) : null,
            'wind_direction' => $direction,
            'precip_accum' => $precip,
        ];
    }

    /**
     * Decode and CRC-validate the 99-byte LOOP1 packet.
     *
     * @param array $raw provider_meta.raw
     * @return array<int, int>|null Byte array, or null with a logged reason
     */
    private static function decodePacket(array $raw): ?array
    {
        $b64 = $raw['loop_base64'] ?? null;
        if (!is_string($b64) || $b64 === '') {
            self::logReject('missing_loop_base64');
            return null;
        }
        $binary = base64_decode($b64, true);
        if ($binary === false || strlen($binary) !== DAVIS_LOOP_PACKET_BYTES) {
            self::logReject('loop_packet_length', [
                'len' => is_string($binary) ? strlen($binary) : -1,
                'want' => DAVIS_LOOP_PACKET_BYTES,
            ]);
            return null;
        }

        if (self::crc16($binary) !== 0) {
            self::logReject('loop_crc_mismatch');
            return null;
        }

        if (!self::hasLoopHeader($binary)) {
            self::logReject('loop_header_mismatch');
            return null;
        }

        return array_values(unpack('C*', $binary));
    }

    /**
     * Davis CRC-CCITT (poly 0x1021). A valid packet (record + trailing CRC)
     * yields zero. Same algorithm the bridge uses on the wire.
     *
     * @param string $data Binary data
     * @return int
     */
    private static function crc16(string $data): int
    {
        $crc = 0;
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $crc ^= ord($data[$i]) << 8;
            for ($bit = 0; $bit < 8; $bit++) {
                if ($crc & 0x8000) {
                    $crc = (($crc << 1) ^ 0x1021) & 0xffff;
                } else {
                    $crc = ($crc << 1) & 0xffff;
                }
            }
        }
        return $crc;
    }

    /**
     * @param string $binary Packet bytes
     * @return bool
     */
    private static function hasLoopHeader(string $binary): bool
    {
        // 99-byte LOOP1: bytes 0-2 are 'LOO', byte 4 is packet_type 0.
        return strlen($binary) >= 5
            && ord($binary[0]) === 0x4c
            && ord($binary[1]) === 0x4f
            && ord($binary[2]) === 0x4f
            && ord($binary[4]) === 0;
    }

    /**
     * Little-endian uint16.
     *
     * @param array<int, int> $packet Byte array
     * @param int $offset Byte offset
     * @return int
     */
    private static function le16(array $packet, int $offset): int
    {
        $lo = $packet[$offset] ?? 0;
        $hi = $packet[$offset + 1] ?? 0;
        return $lo | ($hi << 8);
    }

    /**
     * Frame the console clock from the stored record and normalize to UTC.
     *
     * @param array $record Stored record
     * @param array|null $airport Airport config (site timezone)
     * @return int|null Normalized UTC epoch, or null with a logged reason
     */
    private static function observedTimeFromRecord(array $record, ?array $airport = null): ?int
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

        $siteTz = null;
        if (is_array($airport) && isset($airport['timezone']) && is_string($airport['timezone']) && $airport['timezone'] !== '') {
            $siteTz = $airport['timezone'];
        }

        $framed = stationClockFrameNaive($ts, time(), ['site_timezone' => $siteTz]);
        if (!$framed['ok']) {
            self::logReject('station_clock_unidentifiable', [
                'reason' => $framed['reason'],
            ]);
            return null;
        }
        if ($framed['framing'] === 'local') {
            // One-time note so a local console is visible without spamming the
            // per-poll log; the normalized epoch still flows every observation.
            if (!self::$localFramingNoted) {
                self::$localFramingNoted = true;
                aviationwx_log('info', 'davis_weatherlink_ip clock framed as local', [
                    'tz' => $siteTz,
                    'epoch_utc' => $framed['epoch_utc'],
                ], 'app');
            }
        }
        return $framed['epoch_utc'];
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
        aviationwx_log('warning', 'davis_weatherlink_ip reject', array_merge([
            'reason' => $reason,
        ], $context), 'app');
    }
}