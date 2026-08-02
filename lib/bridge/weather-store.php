<?php
/**
 * Bridge weather sample ingest: latest + samples ring + 60s buckets.
 *
 * Keyed POSTs are always stored for diagnostics. Public weather uses only
 * weather_sources type aviationwx_bridge (Option B enable gate).
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
 * Normalize one weather sample object from the wire (°C, kt, inHg).
 *
 * @param array $sample Raw sample object
 * @return array|null Normalized sample fields or null if empty/invalid
 */
function bridgeNormalizeWeatherSampleFields(array $sample): ?array
{
    $out = [];
    $map = [
        'temp_c' => 'temp_c',
        'humidity_pct' => 'humidity_pct',
        'wind_speed_kt' => 'wind_speed_kt',
        'wind_gust_kt' => 'wind_gust_kt',
        'wind_dir_deg' => 'wind_dir_deg',
        'pressure_inhg' => 'pressure_inhg',
        'rain_in' => 'rain_in',
    ];
    foreach ($map as $wire => $canon) {
        if (!array_key_exists($wire, $sample)) {
            continue;
        }
        if (!is_numeric($sample[$wire])) {
            continue;
        }
        $out[$canon] = (float) $sample[$wire];
    }
    if (isset($out['humidity_pct'])) {
        $out['humidity_pct'] = max(0.0, min(100.0, $out['humidity_pct']));
    }
    if (isset($out['wind_dir_deg'])) {
        $dir = fmod($out['wind_dir_deg'], 360.0);
        if ($dir < 0) {
            $dir += 360.0;
        }
        $out['wind_dir_deg'] = $dir;
    }
    return $out === [] ? null : $out;
}

/**
 * Parse a single weather POST item (top-level fields + sample).
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

    if (!isset($item['sample']) || !is_array($item['sample'])) {
        return ['ok' => false, 'code' => 'INVALID_REQUEST', 'error' => 'sample object is required'];
    }
    $fields = bridgeNormalizeWeatherSampleFields($item['sample']);
    if ($fields === null) {
        return ['ok' => false, 'code' => 'INVALID_REQUEST', 'error' => 'sample must include at least one numeric field'];
    }

    $record = [
        'observed_at' => gmdate('c', $observedTs),
        'observed_unix' => $observedTs,
        'source_id' => $item['source_id'],
        'provider' => isset($item['provider']) && is_string($item['provider']) ? $item['provider'] : null,
        'sample' => $fields,
    ];
    if (isset($item['provider_meta']) && is_array($item['provider_meta'])) {
        $record['provider_meta'] = bridgeScrubValue($item['provider_meta']);
    }
    if (isset($item['bridge_id']) && is_string($item['bridge_id'])) {
        $record['body_bridge_id'] = $item['bridge_id'];
    }

    return ['ok' => true, 'record' => $record];
}

/**
 * Extract weather items from a POST body (single or samples[] batch).
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
            // Inherit top-level bridge_id / source_id / provider when omitted per item
            if (!isset($sampleItem['bridge_id']) && isset($body['bridge_id'])) {
                $sampleItem['bridge_id'] = $body['bridge_id'];
            }
            if (!isset($sampleItem['source_id']) && isset($body['source_id'])) {
                $sampleItem['source_id'] = $body['source_id'];
            }
            if (!isset($sampleItem['provider']) && isset($body['provider'])) {
                $sampleItem['provider'] = $body['provider'];
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
 * Update min/max/sum/count stats for a numeric field on a bucket.
 *
 * @param array $bucket Bucket array (by ref)
 * @param string $field Field name
 * @param float $value Sample value
 * @return void
 */
function bridgeWeatherBucketAddField(array &$bucket, string $field, float $value): void
{
    if (!isset($bucket[$field]) || !is_array($bucket[$field])) {
        $bucket[$field] = [
            'min' => $value,
            'max' => $value,
            'sum' => $value,
            'count' => 1,
            'mean' => $value,
            'last' => $value,
        ];
        return;
    }
    $stats = &$bucket[$field];
    $stats['min'] = min((float) $stats['min'], $value);
    $stats['max'] = max((float) $stats['max'], $value);
    $stats['sum'] = (float) $stats['sum'] + $value;
    $stats['count'] = (int) $stats['count'] + 1;
    $stats['mean'] = $stats['sum'] / $stats['count'];
    $stats['last'] = $value;
}

/**
 * Merge one sample into buckets.json (bounded).
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
    foreach ($record['sample'] as $field => $value) {
        if (is_float($value) || is_int($value)) {
            bridgeWeatherBucketAddField($bucket, $field, (float) $value);
        }
    }

    // Keep newest buckets only
    if (count($data['buckets']) > BRIDGE_WEATHER_BUCKETS_MAX) {
        ksort($data['buckets'], SORT_NUMERIC);
        $data['buckets'] = array_slice($data['buckets'], -BRIDGE_WEATHER_BUCKETS_MAX, null, true);
    }

    return bridgeWriteJsonFile($path, $data);
}

/**
 * Append one line to samples.jsonl and trim.
 *
 * @param string $path samples.jsonl path
 * @param array $record Normalized record
 * @return void
 */
function bridgeWeatherAppendSampleLine(string $path, array $record): void
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
 * Persist one accepted weather sample (diagnostics always; enable gate is separate).
 *
 * @param string $airportId Airport id
 * @param string $bridgeId Bridge id
 * @param array $record Normalized record from bridgeNormalizeWeatherItem
 * @param int|null $receivedAt Receive time
 * @return bool
 */
function bridgeStoreWeatherSample(
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
    bridgeWeatherAppendSampleLine($samplesPath, $stored);
    bridgeWeatherUpdateBuckets($bucketsPath, $stored);
    bridgeTouchMeta($airportId, $bridgeId, 'weather', $receivedAt);
    return true;
}

/**
 * Load latest weather sample for a bridge source.
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
