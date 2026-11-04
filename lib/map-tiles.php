<?php
/**
 * Map tile fetch (shared by the browser tile proxy and the map card renderer).
 *
 * Caches tiles to disk and fetches from upstream on a miss. One code path means
 * the renderer and the proxy share the same cache and provider handling.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/cache-paths.php';

if (!defined('MAP_TILE_CACHE_TTL')) {
    define('MAP_TILE_CACHE_TTL', 3600);
}
if (!defined('MAP_TILE_RAIN_CACHE_TTL')) {
    define('MAP_TILE_RAIN_CACHE_TTL', 900);
}

/**
 * OpenWeatherMap layers the proxy allows.
 *
 * @return list<string> OpenWeatherMap layer keys the proxy allows
 */
function mapTilesOwmLayers(): array
{
    return [
        'clouds_new',
        'precipitation_new',
        'temp_new',
        'wind_new',
        'pressure_new',
    ];
}

/**
 * Upstream tile URL for a provider, or null when the layer is unsupported.
 *
 * @param string $layer osm, rainviewer, or an OpenWeatherMap layer
 * @param int $z Zoom level
 * @param int $x Tile x
 * @param int $y Tile y
 * @param string $owmApiKey OpenWeatherMap API key (required for OWM layers)
 * @param string|null $rainFrameId RainViewer 12-hex radar frame id
 * @return string|null Upstream URL, or null when the layer is unsupported
 */
function mapTilesUpstreamUrl(string $layer, int $z, int $x, int $y, string $owmApiKey = '', ?string $rainFrameId = null): ?string
{
    if ($layer === 'osm') {
        return "https://tile.openstreetmap.org/{$z}/{$x}/{$y}.png";
    }

    if ($layer === 'rainviewer') {
        if ($rainFrameId === null) {
            return null;
        }
        return "https://tilecache.rainviewer.com/v2/radar/{$rainFrameId}/256/{$z}/{$x}/{$y}/6/1_1.png";
    }

    if (in_array($layer, mapTilesOwmLayers(), true)) {
        return "https://tile.openweathermap.org/map/{$layer}/{$z}/{$x}/{$y}.png?appid={$owmApiKey}";
    }

    return null;
}

/**
 * Fetch a tile, caching it to disk.
 *
 * @param string $layer osm, rainviewer, or an OpenWeatherMap layer
 * @param int $z Zoom level
 * @param int $x Tile x
 * @param int $y Tile y
 * @param string|null $rainFrameId RainViewer radar frame id
 * @return array{ok: bool, bytes: ?string, from_cache: bool, http_code: ?int, error: ?string, cache_path: string} Result with tile bytes and cache/error state
 */
function fetchMapTile(string $layer, int $z, int $x, int $y, ?string $rainFrameId = null): array
{
    $isRain = $layer === 'rainviewer';
    $isOsm = $layer === 'osm';
    $config = loadConfig();

    $apiKey = '';
    if (!$isRain && !$isOsm) {
        $apiKey = $config['config']['openweathermap_api_key'] ?? '';
        if ($apiKey === '') {
            return [
                'ok' => false, 'bytes' => null, 'from_cache' => false,
                'http_code' => 503, 'error' => 'OpenWeatherMap API key not configured',
                'cache_path' => '',
            ];
        }
    }

    $cacheLayer = $isRain ? "rainviewer_{$rainFrameId}" : $layer;
    $cachePath = getMapTileCachePath($cacheLayer, $z, $x, $y);
    $ttl = $isRain ? MAP_TILE_RAIN_CACHE_TTL : MAP_TILE_CACHE_TTL;

    if (file_exists($cachePath) && (time() - filemtime($cachePath)) < $ttl) {
        $bytes = file_get_contents($cachePath);
        if ($bytes !== false && strlen($bytes) >= 100) {
            return [
                'ok' => true, 'bytes' => $bytes, 'from_cache' => true,
                'http_code' => null, 'error' => null, 'cache_path' => $cachePath,
            ];
        }
    }

    $url = mapTilesUpstreamUrl($layer, $z, $x, $y, $apiKey, $rainFrameId);
    if ($url === null) {
        return [
            'ok' => false, 'bytes' => null, 'from_cache' => false,
            'http_code' => 400, 'error' => 'Unsupported layer', 'cache_path' => $cachePath,
        ];
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'AviationWX/1.0 (https://aviationwx.org)');
    $bytes = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200 || !is_string($bytes) || strlen($bytes) < 100 || substr($bytes, 0, 4) !== "\x89PNG") {
        return [
            'ok' => false, 'bytes' => null, 'from_cache' => false,
            // cURL reports transport failures (timeout, DNS, refusal, TLS) as 0, and
            // http_response_code(0) would not set a real status, so normalize to 502.
            'http_code' => ($httpCode >= 100 && $httpCode !== 200) ? $httpCode : 502,
            'error' => $httpCode !== 200 ? ($error ?: 'Upstream error') : 'Invalid tile data',
            'cache_path' => $cachePath,
        ];
    }

    // Best-effort cache write: suppress errors and serve the tile even if the
    // disk write fails rather than failing the request.
    $cacheDir = dirname($cachePath);
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    if (is_dir($cacheDir) && is_writable($cacheDir)) {
        @file_put_contents($cachePath, $bytes);
    }

    return [
        'ok' => true, 'bytes' => $bytes, 'from_cache' => false,
        'http_code' => null, 'error' => null, 'cache_path' => $cachePath,
    ];
}