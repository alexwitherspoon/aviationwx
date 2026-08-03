<?php
/**
 * Bridge weather ingest: latest + observation ring + 60s receipt buckets.
 *
 * Wire contract: provider-tagged observations with provider_meta.raw (station-native).
 * Keyed POSTs are always stored for diagnostics. Public weather requires an explicit
 * weather_sources enable row (Option B); adapters parse raw cache, not this store.
 */

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/config.php';

if (!defined('BRIDGE_WEATHER_SAMPLES_MAX_LINES')) {
    define('BRIDGE_WEATHER_SAMPLES_MAX_LINES', 7200);
}

if (!defined('BRIDGE_WEATHER_BUCKETS_MAX')) {
    define('BRIDGE_WEATHER_BUCKETS_MAX', 120);
}

if (!defined('BRIDGE_WEATHER_BATCH_MAX')) {
    define('BRIDGE_WEATHER_BATCH_MAX', 60);
}

/**
 * Validate a wire provider token (e.g. davis_weatherlink_live).
 *
 * @param string $provider Candidate provider id
 * @return bool
 */
function isValidBridgeProviderToken(string $provider): bool
{
    if ($provider === '' || strlen($provider) > 64) {
        return false;
    }
    return (bool) preg_match('/^[a-z][a-z0-9_]*$/', $provider);
}

/**
 * Parse one weather POST observation into a cache record.
 *
 * Requires observed_at, source_id, provider, and provider_meta.raw (object).
 * A legacy "sample" key is ignored and never stored.
 *
 * @param array $item Decoded item
 * @return array{ok: bool, record?: array, error?: string, code?: string}
 */
function bridgeNormalizeWeatherItem(array $item): array
{
    if (!isset($item['observed_at']) || !is_string($item['observed_at']) || $item['observed_at'] === '') {
        return ['ok' => false, 'code' => 'INVALID_REQUEST', 'error' => 'observed_at is required'];
    }
    $observedTs = strtotime($item['observed_at']);
    if ($observedTs === false) {
        return ['ok' => false, 'code' => 'INVALID_REQUEST', 'error' => 'observed_at must be a valid timestamp'];
    }

    if (!isset($item['source_id']) || !is_string($item['source_id']) || $item['source_id'] === '') {
        return ['ok' => false, 'code' => 'INVALID_REQUEST', 'error' => 'source_id is required'];
    }
    if (!isValidBridgeResourceId($item['source_id'])) {
        return ['ok' => false, 'code' => 'INVALID_REQUEST', 'error' => 'source_id is invalid'];
    }

    if (!isset($item['provider']) || !is_string($item['provider']) || $item['provider'] === '') {
        return ['ok' => false, 'code' => 'INVALID_REQUEST', 'error' => 'provider is required'];
    }
    if (!isValidBridgeProviderToken($item['provider'])) {
        return ['ok' => false, 'code' => 'INVALID_REQUEST', 'error' => 'provider is invalid'];
    }

    if (!isset($item['provider_meta']) || !is_array($item['provider_meta'])) {
        return ['ok' => false, 'code' => 'INVALID_REQUEST', 'error' => 'provider_meta object is required'];
    }
    if (!isset($item['provider_meta']['raw']) || !is_array($item['provider_meta']['raw'])) {
        return [
            'ok' => false,
            'code' => 'INVALID_REQUEST',
            'error' => 'provider_meta.raw object is required',
        ];
    }
    if ($item['provider_meta']['raw'] === []) {
        return [
            'ok' => false,
            'code' => 'INVALID_REQUEST',
            'error' => 'provider_meta.raw must not be empty',
        ];
    }

    $record = [
        'observed_at' => gmdate('c', $observedTs),
        'observed_unix' => $observedTs,
        'source_id' => $item['source_id'],
        'provider' => $item['provider'],
        'provider_meta' => bridgeScrubValue($item['provider_meta']),
    ];
    if (isset($item['bridge_id']) && is_string($item['bridge_id'])) {
        $record['body_bridge_id'] = $item['bridge_id'];
    }

    return ['ok' => true, 'record' => $record];
}

/**
 * Extract weather items from a POST body (single observation or samples[] batch).
 *
 * @param array $body Decoded JSON body
 * @return array{ok: bool, items?: list<array>, error?: string, code?: string}
 */
function bridgeExtractWeatherItems(array $body): array
{
    if (isset($body['samples']) && is_array($body['samples'])) {
        if (count($body['samples']) > BRIDGE_WEATHER_BATCH_MAX) {
            return [
                'ok' => false,
                'code' => 'INVALID_REQUEST',
                'error' => 'samples batch exceeds max of ' . BRIDGE_WEATHER_BATCH_MAX,
            ];
        }
        if ($body['samples'] === []) {
            return ['ok' => false, 'code' => 'INVALID_REQUEST', 'error' => 'samples array is empty'];
        }
        $items = [];
        foreach ($body['samples'] as $idx => $sampleItem) {
            if (!is_array($sampleItem)) {
                return ['ok' => false, 'code' => 'INVALID_REQUEST', 'error' => "samples[{$idx}] must be an object"];
            }
            // Inherit top-level identity fields when omitted per item
            foreach (['bridge_id', 'source_id', 'provider'] as $inheritKey) {
                if (!isset($sampleItem[$inheritKey]) && isset($body[$inheritKey])) {
                    $sampleItem[$inheritKey] = $body[$inheritKey];
                }
            }
            $items[] = $sampleItem;
        }
        return ['ok' => true, 'items' => $items];
    }

    return ['ok' => true, 'items' => [$body]];
}

/**
 * Floor unix time to UTC 60-second bucket start.
 *
 * @param int $unix Observed time
 * @return int Bucket start unix
 */
function bridgeWeatherBucketStartUnix(int $unix): int
{
    return intdiv(max(0, $unix), 60) * 60;
}

/**
 * Merge one observation into buckets.json as a receipt count window (no unit conversion).
 *
 * Numeric weather aggregates belong in provider adapters after parsing provider_meta.raw.
 *
 * @param string $path buckets.json path
 * @param array $record Normalized weather record
 * @return bool
 */
function bridgeWeatherUpdateBuckets(string $path, array $record): bool
{
    $data = bridgeReadJsonFile($path);
    if ($data === null || !isset($data['buckets']) || !is_array($data['buckets'])) {
        $data = ['buckets' => []];
    }

    $start = bridgeWeatherBucketStartUnix((int) $record['observed_unix']);
    $key = (string) $start;
    if (!isset($data['buckets'][$key]) || !is_array($data['buckets'][$key])) {
        $data['buckets'][$key] = [
            'start_unix' => $start,
            'start_at' => gmdate('c', $start),
            'count' => 0,
        ];
    }
    $bucket = &$data['buckets'][$key];
    $bucket['count'] = (int) ($bucket['count'] ?? 0) + 1;
    $bucket['last_observed_at'] = $record['observed_at'] ?? null;
    $bucket['last_provider'] = $record['provider'] ?? null;
    $bucket['last_source_id'] = $record['source_id'] ?? null;

    if (count($data['buckets']) > BRIDGE_WEATHER_BUCKETS_MAX) {
        ksort($data['buckets'], SORT_NUMERIC);
        $data['buckets'] = array_slice($data['buckets'], -BRIDGE_WEATHER_BUCKETS_MAX, null, true);
    }

    return bridgeWriteJsonFile($path, $data);
}

/**
 * Append one line to samples.jsonl and trim to the ring bound.
 *
 * @param string $path samples.jsonl path
 * @param array $record Normalized record
 * @return void
 */
function bridgeWeatherAppendObservationLine(string $path, array $record): void
{
    $dir = dirname($path);
    if (!bridgeEnsureCacheDir($dir)) {
        return;
    }
    $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($line === false) {
        return;
    }
    @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);

    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return;
    }
    $lines = preg_split("/\r\n|\n|\r/", trim($raw)) ?: [];
    $lines = array_values(array_filter($lines, static fn ($l) => $l !== ''));
    if (count($lines) <= BRIDGE_WEATHER_SAMPLES_MAX_LINES) {
        return;
    }
    $lines = array_slice($lines, -BRIDGE_WEATHER_SAMPLES_MAX_LINES);
    $tmp = $path . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, implode("\n", $lines) . "\n", LOCK_EX) !== false) {
        @rename($tmp, $path);
    } else {
        @unlink($tmp);
    }
}

/**
 * Persist one accepted weather observation (diagnostics always; enable gate is separate).
 *
 * @param string $airportId Airport id
 * @param string $bridgeId Bridge id
 * @param array $record Normalized record from bridgeNormalizeWeatherItem
 * @param int|null $receivedAt Receive time
 * @return bool
 */
function bridgeStoreWeatherObservation(
    string $airportId,
    string $bridgeId,
    array $record,
    ?int $receivedAt = null
): bool {
    $receivedAt = $receivedAt ?? time();
    $sourceId = (string) $record['source_id'];
    $latestPath = getBridgeWeatherLatestCachePath($airportId, $bridgeId, $sourceId);
    $samplesPath = getBridgeWeatherSamplesCachePath($airportId, $bridgeId, $sourceId);
    $bucketsPath = getBridgeWeatherBucketsCachePath($airportId, $bridgeId, $sourceId);
    if ($latestPath === '' || $samplesPath === '' || $bucketsPath === '') {
        return false;
    }

    $stored = $record;
    $stored['received_at'] = gmdate('c', $receivedAt);
    $stored['airport_id'] = $airportId;
    $stored['bridge_id'] = $bridgeId;

    if (!bridgeWriteJsonFile($latestPath, $stored)) {
        return false;
    }
    bridgeWeatherAppendObservationLine($samplesPath, $stored);
    bridgeWeatherUpdateBuckets($bucketsPath, $stored);
    bridgeTouchMeta($airportId, $bridgeId, 'weather', $receivedAt);
    return true;
}

/**
 * Load latest weather observation for a bridge source.
 *
 * @param string $airportId Airport id
 * @param string $bridgeId Bridge id
 * @param string $sourceId Bridge-local station id
 * @return array|null
 */
function bridgeLoadWeatherLatest(string $airportId, string $bridgeId, string $sourceId): ?array
{
    $path = getBridgeWeatherLatestCachePath($airportId, $bridgeId, $sourceId);
    return $path !== '' ? bridgeReadJsonFile($path) : null;
}

/**
 * Load buckets document for a bridge source.
 *
 * @param string $airportId Airport id
 * @param string $bridgeId Bridge id
 * @param string $sourceId Bridge-local station id
 * @return array|null
 */
function bridgeLoadWeatherBuckets(string $airportId, string $bridgeId, string $sourceId): ?array
{
    $path = getBridgeWeatherBucketsCachePath($airportId, $bridgeId, $sourceId);
    return $path !== '' ? bridgeReadJsonFile($path) : null;
}
