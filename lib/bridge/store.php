<?php
/**
 * Bridge fleet ops cache read/write (health, meta, history).
 *
 * Never stores API keys. Scrubs obvious secrets from error strings.
 */

require_once __DIR__ . '/../cache-paths.php';
require_once __DIR__ . '/../logger.php';

if (!defined('BRIDGE_HEALTH_HISTORY_MAX_ENTRIES')) {
    define('BRIDGE_HEALTH_HISTORY_MAX_ENTRIES', 120);
}

if (!defined('BRIDGE_HEARTBEAT_INTERVAL_SECONDS')) {
    define('BRIDGE_HEARTBEAT_INTERVAL_SECONDS', 60);
}

/**
 * Ensure a bridge cache directory exists.
 *
 * @param string $dir Absolute directory path
 * @return bool True on success
 */
function bridgeEnsureCacheDir(string $dir): bool
{
    if ($dir === '') {
        return false;
    }
    if (is_dir($dir)) {
        return true;
    }
    return @mkdir($dir, 0755, true) || is_dir($dir);
}

/**
 * Atomically write JSON to a cache file.
 *
 * @param string $path Absolute file path
 * @param array $data Data to encode
 * @return bool True on success
 */
function bridgeWriteJsonFile(string $path, array $data): bool
{
    $dir = dirname($path);
    if (!bridgeEnsureCacheDir($dir)) {
        aviationwx_log('error', 'bridge cache dir create failed', ['dir' => $dir], 'bridge');
        return false;
    }
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        aviationwx_log('error', 'bridge cache json encode failed', ['path' => $path], 'bridge');
        return false;
    }
    $tmp = $path . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        aviationwx_log('error', 'bridge cache write failed', ['path' => $tmp], 'bridge');
        return false;
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        aviationwx_log('error', 'bridge cache rename failed', ['path' => $path], 'bridge');
        return false;
    }
    return true;
}

/**
 * Read a JSON cache file.
 *
 * @param string $path Absolute file path
 * @return array|null Decoded array or null
 */
function bridgeReadJsonFile(string $path): ?array
{
    if ($path === '' || !is_readable($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

/**
 * Scrub a string that may contain secrets (passwords, tokens, keys).
 *
 * @param string $value Raw string
 * @return string Scrubbed string
 */
function bridgeScrubSecretString(string $value): string
{
    $out = preg_replace('#(https?://[^\s]+)\?[^\s]+#', '$1?[redacted]', $value) ?? $value;
    $out = preg_replace('#(?i)(api[_-]?key|token|secret|password|bearer)\s*[:=]\s*\S+#', '$1=[redacted]', $out) ?? $out;
    return $out;
}

/**
 * Recursively scrub arrays for secret-like keys and string values.
 *
 * @param mixed $value Input
 * @param int $depth Recursion depth
 * @return mixed Scrubbed value
 */
function bridgeScrubValue(mixed $value, int $depth = 0): mixed
{
    if ($depth > 8) {
        return '[truncated]';
    }
    if (is_string($value)) {
        return bridgeScrubSecretString($value);
    }
    if (!is_array($value)) {
        return $value;
    }
    $out = [];
    foreach ($value as $k => $v) {
        $key = is_string($k) ? strtolower($k) : '';
        if (
            str_contains($key, 'password')
            || str_contains($key, 'secret')
            || str_contains($key, 'token')
            || $key === 'api_key'
            || $key === 'apikey'
            || str_contains($key, 'authorization')
        ) {
            $out[$k] = '[redacted]';
            continue;
        }
        $out[$k] = bridgeScrubValue($v, $depth + 1);
    }
    return $out;
}

/**
 * Load bridge meta.json (or empty defaults).
 *
 * @param string $airportId Airport id
 * @param string $bridgeId Bridge id
 * @return array<string, mixed>
 */
function bridgeLoadMeta(string $airportId, string $bridgeId): array
{
    $path = getBridgeMetaCachePath($airportId, $bridgeId);
    $data = $path !== '' ? bridgeReadJsonFile($path) : null;
    if ($data === null) {
        return [
            'first_seen_at' => null,
            'last_bootstrap_at' => null,
            'last_health_at' => null,
            'last_weather_at' => null,
            'bootstrap_count' => 0,
            'health_count' => 0,
            'weather_count' => 0,
        ];
    }
    return $data;
}

/**
 * Persist bridge meta.json.
 *
 * @param string $airportId Airport id
 * @param string $bridgeId Bridge id
 * @param array $meta Meta payload
 * @return bool
 */
function bridgeSaveMeta(string $airportId, string $bridgeId, array $meta): bool
{
    $path = getBridgeMetaCachePath($airportId, $bridgeId);
    if ($path === '') {
        return false;
    }
    return bridgeWriteJsonFile($path, $meta);
}

/**
 * Touch meta timestamps/counters for an event type.
 *
 * @param string $airportId Airport id
 * @param string $bridgeId Bridge id
 * @param string $event One of bootstrap|health|weather
 * @param int|null $now Unix time
 * @return void
 */
function bridgeTouchMeta(string $airportId, string $bridgeId, string $event, ?int $now = null): void
{
    $now = $now ?? time();
    $iso = gmdate('c', $now);
    $meta = bridgeLoadMeta($airportId, $bridgeId);
    if (empty($meta['first_seen_at'])) {
        $meta['first_seen_at'] = $iso;
    }
    if ($event === 'bootstrap') {
        $meta['last_bootstrap_at'] = $iso;
        $meta['bootstrap_count'] = (int) ($meta['bootstrap_count'] ?? 0) + 1;
    } elseif ($event === 'health') {
        $meta['last_health_at'] = $iso;
        $meta['health_count'] = (int) ($meta['health_count'] ?? 0) + 1;
    } elseif ($event === 'weather') {
        $meta['last_weather_at'] = $iso;
        $meta['weather_count'] = (int) ($meta['weather_count'] ?? 0) + 1;
    }
    bridgeSaveMeta($airportId, $bridgeId, $meta);
}

/**
 * Load latest health.json for a bridge.
 *
 * @param string $airportId Airport id
 * @param string $bridgeId Bridge id
 * @return array|null
 */
function bridgeLoadHealth(string $airportId, string $bridgeId): ?array
{
    $path = getBridgeHealthCachePath($airportId, $bridgeId);
    return $path !== '' ? bridgeReadJsonFile($path) : null;
}

/**
 * Append one line to health_history.jsonl and trim to max entries.
 *
 * @param string $path History file path
 * @param array $entry Scrubbed health summary entry
 * @return void
 */
function bridgeAppendHealthHistory(string $path, array $entry): void
{
    $dir = dirname($path);
    if (!bridgeEnsureCacheDir($dir)) {
        return;
    }
    $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
    if (count($lines) <= BRIDGE_HEALTH_HISTORY_MAX_ENTRIES) {
        return;
    }
    $lines = array_slice($lines, -BRIDGE_HEALTH_HISTORY_MAX_ENTRIES);
    $tmp = $path . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, implode("\n", $lines) . "\n", LOCK_EX) !== false) {
        @rename($tmp, $path);
    } else {
        @unlink($tmp);
    }
}

/**
 * Persist a normalized health heartbeat (latest + history + meta).
 *
 * @param string $airportId Airport id
 * @param string $bridgeId Bridge id
 * @param array $health Normalized health document (already scrubbed)
 * @param int|null $receivedAt Unix receive time
 * @return bool
 */
function bridgeStoreHealth(string $airportId, string $bridgeId, array $health, ?int $receivedAt = null): bool
{
    $receivedAt = $receivedAt ?? time();
    $health['received_at'] = gmdate('c', $receivedAt);
    $health['airport_id'] = $airportId;
    $health['bridge_id'] = $bridgeId;

    $path = getBridgeHealthCachePath($airportId, $bridgeId);
    if ($path === '' || !bridgeWriteJsonFile($path, $health)) {
        return false;
    }

    $historyPath = getBridgeHealthHistoryCachePath($airportId, $bridgeId);
    if ($historyPath !== '') {
        bridgeAppendHealthHistory($historyPath, [
            'received_at' => $health['received_at'],
            'observed_at' => $health['observed_at'] ?? null,
            'host_status' => $health['host']['status'] ?? null,
            'ntp_ok' => $health['host']['ntp_ok'] ?? null,
            'ntp_failure_seconds' => $health['host']['ntp_failure_seconds'] ?? null,
            'inventory_stations' => isset($health['inventory']['stations']) && is_array($health['inventory']['stations'])
                ? count($health['inventory']['stations'])
                : 0,
            'inventory_cameras' => isset($health['inventory']['cameras']) && is_array($health['inventory']['cameras'])
                ? count($health['inventory']['cameras'])
                : 0,
            'error_count' => isset($health['errors']) && is_array($health['errors']) ? count($health['errors']) : 0,
        ]);
    }

    bridgeTouchMeta($airportId, $bridgeId, 'health', $receivedAt);
    return true;
}
