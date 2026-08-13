<?php
/**
 * Bridge weather ingest: publish latest, observation ring, 60s count buckets.
 *
 * Usable rows (no provider_meta.error) update latest. Diagnostic rows append the
 * ring only so they cannot win WeatherSnapshot. Adapters parse cache; this store
 * does not publish.
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

/** Reject observed_at more than this many seconds in the future (clock skew headroom). */
if (!defined('BRIDGE_WEATHER_FUTURE_SKEW_SECONDS')) {
    define('BRIDGE_WEATHER_FUTURE_SKEW_SECONDS', 60);
}

if (!defined('BRIDGE_WEATHER_ERROR_MISSING_STATION_TIME')) {
    define('BRIDGE_WEATHER_ERROR_MISSING_STATION_TIME', 'missing_station_time');
}
if (!defined('BRIDGE_WEATHER_ERROR_IDENTITY_UNMATCHED')) {
    define('BRIDGE_WEATHER_ERROR_IDENTITY_UNMATCHED', 'identity_unmatched');
}
if (!defined('BRIDGE_WEATHER_ERROR_DECODE_FAILED')) {
    define('BRIDGE_WEATHER_ERROR_DECODE_FAILED', 'decode_failed');
}

/**
 * Known provider_meta.error values (bridge WeatherError* constants).
 *
 * @return list<string> Stable diagnostic codes
 */
function bridgeWeatherKnownDiagnosticErrorCodes(): array
{
    return [
        BRIDGE_WEATHER_ERROR_MISSING_STATION_TIME,
        BRIDGE_WEATHER_ERROR_IDENTITY_UNMATCHED,
        BRIDGE_WEATHER_ERROR_DECODE_FAILED,
    ];
}

/**
 * Classify provider_meta.error for ingest.
 *
 * @param mixed $errorField Wire error value
 * @return array{ok: true, diagnostic: bool, code: ?string}|array{ok: false, error: string}
 */
function bridgeWeatherClassifyProviderMetaError(mixed $errorField): array
{
    if ($errorField === null || $errorField === '') {
        return ['ok' => true, 'diagnostic' => false, 'code' => null];
    }
    if (!is_string($errorField)) {
        return ['ok' => false, 'error' => 'provider_meta.error must be a string'];
    }
    return ['ok' => true, 'diagnostic' => true, 'code' => $errorField];
}

/**
 * Diagnostic error code from a stored record or wire item, or null for a usable row.
 *
 * @param array $record Normalized record or wire item with provider_meta
 * @return string|null
 */
function bridgeWeatherDiagnosticError(array $record): ?string
{
    $meta = $record['provider_meta'] ?? null;
    if (!is_array($meta)) {
        return null;
    }
    $classified = bridgeWeatherClassifyProviderMetaError($meta['error'] ?? null);
    if (!$classified['ok'] || !$classified['diagnostic']) {
        return null;
    }
    return $classified['code'];
}

/**
 * Whether a stored record is a diagnostic row (must not become WeatherSnapshot).
 *
 * @param array $record Normalized record or wire item
 * @return bool
 */
function bridgeWeatherRecordIsDiagnostic(array $record): bool
{
    return bridgeWeatherDiagnosticError($record) !== null;
}

/**
 * Log an unknown provider_meta.error string once per process.
 *
 * @param string $code Unknown error token
 * @return void
 */
function bridgeWeatherLogUnknownDiagnosticError(string $code): void
{
    static $logged = [];
    if (isset($logged[$code])) {
        return;
    }
    $logged[$code] = true;
    aviationwx_log('warning', 'bridge weather unknown diagnostic error code', [
        'error' => $code,
    ], 'bridge');
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
 * Usable rows require non-empty provider_meta.raw. Diagnostic decode_failed may omit raw.
 * A legacy "sample" key is ignored and never stored. raw is not rewritten.
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
    // Future timestamps would bypass WeatherReading staleness (negative age looks fresh)
    if ($observedTs > time() + BRIDGE_WEATHER_FUTURE_SKEW_SECONDS) {
        return [
            'ok' => false,
            'code' => 'INVALID_REQUEST',
            'error' => 'observed_at is too far in the future',
        ];
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

    $classified = bridgeWeatherClassifyProviderMetaError($item['provider_meta']['error'] ?? null);
    if (!$classified['ok']) {
        return [
            'ok' => false,
            'code' => 'INVALID_REQUEST',
            'error' => $classified['error'],
        ];
    }
    $isDiagnostic = $classified['diagnostic'];
    $allowOmitRaw = $isDiagnostic && $classified['code'] === BRIDGE_WEATHER_ERROR_DECODE_FAILED;

    if (!$allowOmitRaw) {
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
    }

    if (!$isDiagnostic) {
        $raw = $item['provider_meta']['raw'] ?? null;
        if (is_array($raw) && isset($raw['ts']) && is_numeric($raw['ts'])) {
            $rawTs = (int) $raw['ts'];
            if ($rawTs <= 0) {
                return [
                    'ok' => false,
                    'code' => 'INVALID_REQUEST',
                    'error' => 'provider_meta.raw.ts must be a positive unix timestamp when set',
                ];
            }
            if ($rawTs > time() + BRIDGE_WEATHER_FUTURE_SKEW_SECONDS) {
                return [
                    'ok' => false,
                    'code' => 'INVALID_REQUEST',
                    'error' => 'provider_meta.raw.ts is too far in the future',
                ];
            }
            if (abs($rawTs - $observedTs) > BRIDGE_WEATHER_FUTURE_SKEW_SECONDS) {
                return [
                    'ok' => false,
                    'code' => 'INVALID_REQUEST',
                    'error' => 'observed_at and provider_meta.raw.ts disagree beyond allowed skew',
                ];
            }
        }
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

    if ($isDiagnostic && is_string($classified['code'])
        && !in_array($classified['code'], bridgeWeatherKnownDiagnosticErrorCodes(), true)
    ) {
        bridgeWeatherLogUnknownDiagnosticError($classified['code']);
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
 * Merge one observation into buckets.json as an observation-time count window.
 *
 * Buckets are keyed by observed_at (not receive time). Numeric weather aggregates
 * belong in provider adapters after parsing provider_meta.raw.
 *
 * @param string $path buckets.json path
 * @param array $record Normalized weather record
 * @return bool
 */
function bridgeWeatherUpdateBuckets(string $path, array $record): bool
{
    return bridgeUpdateJsonFileLocked($path, static function (?array $data) use ($record): array {
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

        return $data;
    });
}

/**
 * Append one line to samples.jsonl and trim to the ring bound.
 *
 * @param string $path samples.jsonl path
 * @param array $record Normalized record
 * @return bool
 */
function bridgeWeatherAppendObservationLine(string $path, array $record): bool
{
    return bridgeAppendJsonlRing($path, $record, BRIDGE_WEATHER_SAMPLES_MAX_LINES);
}

/**
 * Whether a POST provider is allowed for a source given its enable type.
 *
 * Unenabled sources (null) accept any valid provider for installer diagnostics.
 * Enabled sources require an exact match so latest cannot be overwritten by the wrong adapter family.
 *
 * @param string|null $enabledType weather_sources.type or null when not enabled
 * @param string $provider Wire provider token
 * @return bool
 */
function bridgeWeatherProviderMatchesEnable(?string $enabledType, string $provider): bool
{
    return $enabledType === null || $enabledType === $provider;
}

/**
 * Normalize and enable-check extracted weather items before any cache write.
 *
 * @param list<array> $items Items from bridgeExtractWeatherItems()
 * @param string $bridgeId Authenticated bridge id
 * @param array|null $airport Airport config (for enable lookup)
 * @return array{
 *   ok: bool,
 *   pending?: list<array{record: array, enabled: bool}>,
 *   code?: string,
 *   error?: string
 * }
 */
function bridgePrepareWeatherIngestBatch(array $items, string $bridgeId, ?array $airport): array
{
    $pending = [];
    foreach ($items as $item) {
        $normalized = bridgeNormalizeWeatherItem($item);
        if (!$normalized['ok']) {
            return [
                'ok' => false,
                'code' => $normalized['code'] ?? 'INVALID_REQUEST',
                'error' => $normalized['error'] ?? 'Invalid weather item',
            ];
        }
        $record = $normalized['record'];
        $enabledType = is_array($airport)
            ? getBridgeEnabledWeatherSourceType($airport, $bridgeId, $record['source_id'])
            : null;
        if (!bridgeWeatherProviderMatchesEnable($enabledType, $record['provider'])) {
            return [
                'ok' => false,
                'code' => 'PROVIDER_MISMATCH',
                'error' => 'provider must match weather_sources.type for enabled source '
                    . $record['source_id'] . ' (expected ' . $enabledType . ')',
            ];
        }
        $pending[] = ['record' => $record, 'enabled' => $enabledType !== null];
    }
    return ['ok' => true, 'pending' => $pending];
}

/**
 * Persist one accepted weather observation (enable gate is separate).
 *
 * Diagnostic rows append the observation ring only so they cannot replace
 * publish latest or fold into 60s count buckets.
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

    $isDiagnostic = bridgeWeatherRecordIsDiagnostic($stored);

    if (!$isDiagnostic) {
        $newObs = isset($stored['observed_unix']) && is_numeric($stored['observed_unix'])
            ? (int) $stored['observed_unix']
            : 0;
        if (!bridgeUpdateJsonFileLocked($latestPath, static function (?array $existing) use ($stored, $newObs): ?array {
            $existingIsDiagnostic = is_array($existing) && bridgeWeatherRecordIsDiagnostic($existing);
            $existingObs = is_array($existing) && isset($existing['observed_unix']) && is_numeric($existing['observed_unix'])
                ? (int) $existing['observed_unix']
                : 0;
            // First-wins at equal observed_unix so same-second garbage cannot replace a good sample.
            // Diagnostic latest is transport-time ranked; a usable station clock must still win publish.
            if ($existing !== null && !$existingIsDiagnostic && $newObs <= $existingObs) {
                return null;
            }
            return $stored;
        })) {
            return false;
        }
    }

    if (!bridgeWeatherAppendObservationLine($samplesPath, $stored)) {
        return false;
    }
    if (!$isDiagnostic && !bridgeWeatherUpdateBuckets($bucketsPath, $stored)) {
        return false;
    }
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
