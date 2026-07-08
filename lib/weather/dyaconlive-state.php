<?php
/**
 * DyaconLive per-source state (last ingested bucket + cached snapshot for skip path).
 */

require_once __DIR__ . '/../cache-paths.php';
require_once __DIR__ . '/../logger.php';
require_once __DIR__ . '/data/WeatherReading.php';
require_once __DIR__ . '/data/WindGroup.php';
require_once __DIR__ . '/data/WeatherSnapshot.php';
require_once __DIR__ . '/adapter/dyaconlive-v1.php';

use AviationWX\Weather\Data\WeatherReading;
use AviationWX\Weather\Data\WindGroup;
use AviationWX\Weather\Data\WeatherSnapshot;

/**
 * Default state file path for an airport weather_sources index.
 *
 * @param string $airportId Airport identifier
 * @param int $sourceIndex Index in weather_sources[]
 * @return string Absolute path
 */
function getDyaconLiveSourceStatePath(string $airportId, int $sourceIndex): string
{
    if (isset($GLOBALS['dyaconliveTestStateDir'])
        && is_string($GLOBALS['dyaconliveTestStateDir'])
        && $GLOBALS['dyaconliveTestStateDir'] !== ''
    ) {
        return rtrim($GLOBALS['dyaconliveTestStateDir'], '/') . '/'
            . strtolower($airportId) . '_' . $sourceIndex . '.json';
    }

    return CACHE_WEATHER_DIR . '/dyaconlive/' . strtolower($airportId) . '_' . $sourceIndex . '.json';
}

/**
 * @return array{last_bucket_unix: int, last_bucket_iso: string, snapshot: array<string, mixed>}|null
 */
function dyaconliveReadSourceState(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }

    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }

    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['last_bucket_unix'], $data['snapshot']) || !is_array($data['snapshot'])) {
        return null;
    }

    return $data;
}

/**
 * @param WeatherSnapshot $snapshot Parsed snapshot to cache for skip reuse
 */
function dyaconliveWriteSourceState(
    string $path,
    int $lastBucketUnix,
    string $lastBucketIso,
    WeatherSnapshot $snapshot
): bool {
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        aviationwx_log('error', 'dyaconlive state mkdir failed', ['dir' => $dir], 'app');
        return false;
    }

    $payload = [
        'last_bucket_unix' => $lastBucketUnix,
        'last_bucket_iso' => $lastBucketIso,
        'snapshot' => dyaconliveSnapshotToStateArray($snapshot),
        'written_at' => time(),
    ];

    try {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        aviationwx_log('error', 'dyaconlive state encode failed', [
            'path' => $path,
            'error' => $e->getMessage(),
        ], 'app');

        return false;
    }

    $tmp = $path . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        @unlink($tmp);
        return false;
    }

    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }

    return true;
}

/**
 * @return array<string, mixed>
 */
function dyaconliveSnapshotToStateArray(WeatherSnapshot $snapshot): array
{
    $obsTime = $snapshot->getFieldObservationTime('temperature')
        ?? $snapshot->getFieldObservationTime('wind_speed')
        ?? time();

    return [
        'is_valid' => $snapshot->isValid,
        'obs_time' => $obsTime,
        'fetch_time' => $snapshot->fetchTime,
        'temperature_c' => $snapshot->temperature->value,
        'humidity' => $snapshot->humidity->value,
        'pressure_inhg' => $snapshot->pressure->value,
        'precip_accum_in' => $snapshot->precipAccum->value,
        'wind_speed_kt' => $snapshot->wind->speed->value,
        'wind_direction' => $snapshot->wind->direction->value,
        'gust_speed_kt' => $snapshot->wind->gust->value,
        'station_id' => $snapshot->stationId,
    ];
}

function dyaconliveSnapshotFromStateArray(array $data): ?WeatherSnapshot
{
    if (empty($data['is_valid'])) {
        return WeatherSnapshot::empty(DyaconLiveAdapter::SOURCE_TYPE);
    }

    $source = DyaconLiveAdapter::SOURCE_TYPE;
    $obsTime = isset($data['obs_time']) ? (int) $data['obs_time'] : time();
    $fetchTime = isset($data['fetch_time']) ? (int) $data['fetch_time'] : time();
    $windSpeedKt = isset($data['wind_speed_kt']) && is_numeric($data['wind_speed_kt'])
        ? (float) $data['wind_speed_kt']
        : null;
    $windDirection = isset($data['wind_direction']) && is_numeric($data['wind_direction'])
        ? (float) $data['wind_direction']
        : null;
    $hasWind = $windSpeedKt !== null && $windDirection !== null;

    return new WeatherSnapshot(
        source: $source,
        fetchTime: $fetchTime,
        temperature: WeatherReading::celsius(
            isset($data['temperature_c']) ? (float) $data['temperature_c'] : null,
            $source,
            $obsTime
        ),
        dewpoint: WeatherReading::null($source),
        humidity: WeatherReading::percent(
            isset($data['humidity']) ? (float) $data['humidity'] : null,
            $source,
            $obsTime
        ),
        pressure: WeatherReading::inHg(
            isset($data['pressure_inhg']) ? (float) $data['pressure_inhg'] : null,
            $source,
            $obsTime
        ),
        precipAccum: WeatherReading::inches(
            isset($data['precip_accum_in']) ? (float) $data['precip_accum_in'] : null,
            $source,
            $obsTime
        ),
        wind: $hasWind
            ? WindGroup::from(
                $windSpeedKt,
                $windDirection,
                isset($data['gust_speed_kt']) && is_numeric($data['gust_speed_kt'])
                    ? (float) $data['gust_speed_kt']
                    : null,
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
        stationId: isset($data['station_id']) ? (string) $data['station_id'] : null,
        metarFieldCompleteness: null
    );
}

/**
 * Resolve cached snapshot for skip path, if state is current.
 *
 * @param array<string, mixed> $source weather_sources entry
 * @param array<string, mixed> $airport Airport config
 * @param int $sourceIndex Index in weather_sources
 * @return WeatherSnapshot|null Snapshot to reuse, or null when fetch is required
 */
function dyaconliveResolveSkippedSnapshot(
    string $airportId,
    array $source,
    array $airport,
    int $sourceIndex
): ?WeatherSnapshot {
    require_once __DIR__ . '/dyaconlive-bucket.php';
    require_once __DIR__ . '/../config.php';

    $timezone = dyaconliveResolveTimezone($source, $airport);
    $statePath = getDyaconLiveSourceStatePath($airportId, $sourceIndex);
    $state = dyaconliveReadSourceState($statePath);
    if ($state === null) {
        return null;
    }

    $nowUnix = dyaconliveCurrentUnixTime();
    $lastBucket = isset($state['last_bucket_unix']) ? (int) $state['last_bucket_unix'] : null;
    if (!dyaconliveShouldSkipUpstreamFetch($lastBucket, $nowUnix, $timezone)) {
        return null;
    }

    $snapshot = dyaconliveSnapshotFromStateArray($state['snapshot']);
    if ($snapshot === null || !$snapshot->isValid) {
        return null;
    }

    aviationwx_log('debug', 'dyaconlive upstream skip', [
        'airport_id' => $airportId,
        'last_bucket_iso' => $state['last_bucket_iso'] ?? null,
        'station_id' => $source['station_id'] ?? null,
    ], 'app');

    return $snapshot;
}

/**
 * @param array<string, mixed> $source
 * @param array<string, mixed> $airport
 */
function dyaconliveResolveTimezone(array $source, array $airport): string
{
    if (isset($source['timezone']) && is_string($source['timezone']) && trim($source['timezone']) !== '') {
        return trim($source['timezone']);
    }

    return getAirportTimezone($airport);
}

/**
 * Persist state after a successful fetch/parse.
 *
 * @param array<string, mixed> $source
 * @param array<string, mixed> $airport
 */
function dyaconlivePersistSourceStateAfterParse(
    string $airportId,
    array $source,
    array $airport,
    int $sourceIndex,
    WeatherSnapshot $snapshot,
    string $lastBucketIso
): void {
    $timezone = dyaconliveResolveTimezone($source, $airport);
    $bucketUnix = dyaconliveParseBucketIsoToUnix($lastBucketIso, $timezone);
    if ($bucketUnix === null || !$snapshot->isValid) {
        return;
    }

    dyaconliveWriteSourceState(
        getDyaconLiveSourceStatePath($airportId, $sourceIndex),
        $bucketUnix,
        $lastBucketIso,
        $snapshot
    );
}

/**
 * Current Unix time (override in tests via $GLOBALS['dyaconliveTestNowUnix']).
 */
function dyaconliveCurrentUnixTime(): int
{
    if (isset($GLOBALS['dyaconliveTestNowUnix']) && is_int($GLOBALS['dyaconliveTestNowUnix'])) {
        return $GLOBALS['dyaconliveTestNowUnix'];
    }

    return time();
}
