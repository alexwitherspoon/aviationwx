<?php
/**
 * Render the airport directory social card.
 *
 * Daily CLI job. Stitches the OSM base map with OpenWeatherMap cloud and
 * RainViewer radar overlays over a date-seeded random regional focus (at least
 * three airports in frame), composites a grey airplane marker per airport, and
 * atomically writes CACHE_SOCIAL_MAP_CARD_FILE. Vendor credit and an "as of"
 * UTC stamp go into EXIF, never into visible pixels. A failed render leaves the
 * prior image in place.
 *
 * Usage: php scripts/render-map-card.php
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/constants.php';
require_once __DIR__ . '/../lib/cache-paths.php';
require_once __DIR__ . '/../lib/map-card.php';
require_once __DIR__ . '/../lib/map-tiles.php';
require_once __DIR__ . '/../lib/exif-utils.php';
require_once __DIR__ . '/../lib/logger.php';

const MAP_CARD_CANVAS_WIDTH = 1200;
const MAP_CARD_CANVAS_HEIGHT = 630;
const MAP_CARD_MARGIN_PX = 80;
const MAP_CARD_MARKER_SIZE = 32;
const MAP_CARD_RADAR_CACHE_FILE = CACHE_BASE_DIR . '/rainviewer/weather-maps.json';
const MAP_CARD_RADAR_CACHE_TTL = 300;

/**
 * Render seed. Defaults to the UTC date so every node renders the same view for
 * a given day. MAP_CARD_SEED overrides it for one-off previews; the scheduler
 * never sets it, so production stays date-deterministic.
 *
 * @return int
 */
function mapCardSeed(): int
{
    $forced = getenv('MAP_CARD_SEED');
    if ($forced !== false && $forced !== '') {
        return (int) $forced;
    }

    return (int) crc32(gmdate('Y-m-d'));
}

/**
 * Resolve the current RainViewer radar frame id, mirroring the client.
 *
 * Reads the cached weather-maps.json when fresh, otherwise refreshes it from
 * upstream, then returns the last radar.past[].path basename as a 12-hex id.
 *
 * @return string|null
 */
function mapCardResolveRadarFrameId(): ?string
{
    $jar = null;
    if (is_file(MAP_CARD_RADAR_CACHE_FILE)) {
        $decoded = json_decode((string) @file_get_contents(MAP_CARD_RADAR_CACHE_FILE), true);
        if (is_array($decoded)) {
            $jar = $decoded;
        }
    }

    $fresh = is_file(MAP_CARD_RADAR_CACHE_FILE)
        && (time() - filemtime(MAP_CARD_RADAR_CACHE_FILE)) < MAP_CARD_RADAR_CACHE_TTL;

    if ($jar === null || !$fresh) {
        $body = false;
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://api.rainviewer.com/public/weather-maps.json',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'AviationWX/1.0 (Map Renderer; https://aviationwx.org)',
        ]);
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && is_string($body)) {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $jar = $decoded;
                $dir = dirname(MAP_CARD_RADAR_CACHE_FILE);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }
                // Best-effort; this cache is shared with the browser weather-maps proxy.
                if (is_dir($dir) && is_writable($dir)) {
                    @file_put_contents(MAP_CARD_RADAR_CACHE_FILE, $body);
                }
            }
        }
    }

    if (!is_array($jar)) {
        return null;
    }

    $past = $jar['radar']['past'] ?? [];
    if (!is_array($past) || $past === []) {
        return null;
    }

    $last = end($past);
    $path = is_array($last) ? ($last['path'] ?? '') : '';
    $parts = array_values(array_filter(explode('/', (string) $path), static fn (string $p) => $p !== ''));
    $frameId = strtolower((string) end($parts));

    return preg_match('/^[0-9a-f]{12}$/', $frameId) ? $frameId : null;
}

/**
 * The client airplane marker as an ordered list of [x, y] points in its 24-unit
 * viewBox. The nose arc (SVG A commands) is expanded into a smooth semicircle.
 *
 * @return list<array{0: float, 1: float}>
 */
function mapCardPlaneOutline(): array
{
    $pts = [[21.0, 16.0], [21.0, 14.0], [13.0, 9.0], [13.0, 3.5]];

    $cx = 11.5;
    $cy = 3.5;
    $r = 1.5;
    $segments = 12;
    for ($i = 0; $i <= $segments; $i++) {
        $theta = M_PI * $i / $segments;
        $pts[] = [$cx + $r * cos($theta), $cy - $r * sin($theta)];
    }

    return array_merge($pts, [
        [10.0, 9.0], [2.0, 14.0], [2.0, 16.0], [10.0, 13.5], [10.0, 19.0],
        [8.0, 20.5], [8.0, 22.0], [11.5, 21.0], [15.0, 22.0], [15.0, 20.5],
        [13.0, 19.0], [13.0, 13.5],
    ]);
}

/**
 * Build the grey airplane marker once as an anti-aliased RGBA image.
 *
 * Rasterized at a supersampled resolution then resampled down so the marker
 * edges and nose arc stay smooth at 32px. The background is transparent so the
 * marker composites over the map.
 *
 * @param int $size
 * @param int $supersample
 * @return GdImage
 */
function mapCardBuildMarker(int $size, int $supersample = 4): GdImage
{
    $big = $size * $supersample;
    $k = $big / 24.0;

    $bigIm = imagecreatetruecolor($big, $big);
    imagealphablending($bigIm, false);
    imagesavealpha($bigIm, true);
    $blank = imagecolorallocatealpha($bigIm, 0, 0, 0, 127);
    imagefilledrectangle($bigIm, 0, 0, $big, $big, $blank);

    $strokePoints = [];
    foreach (mapCardPlaneOutline() as [$ux, $uy]) {
        $strokePoints[] = (int) round($ux * $k);
        $strokePoints[] = (int) round($uy * $k);
    }

    // Insets the solid grey fill toward the shape centroid so a thin dark rim
    // remains, matching the client marker's stroke. The 4x downsample then
    // anti-aliases the rim and the nose arc.
    $cx = 11.5;
    $cy = 12.0;
    $inset = 0.85;
    $fillPoints = [];
    foreach (mapCardPlaneOutline() as [$ux, $uy]) {
        $fillPoints[] = (int) round((($ux - $cx) * $inset + $cx) * $k);
        $fillPoints[] = (int) round((($uy - $cy) * $inset + $cy) * $k);
    }

    imagealphablending($bigIm, true);
    $fill = imagecolorallocate($bigIm, 158, 158, 158);
    $stroke = imagecolorallocate($bigIm, 26, 26, 26);
    imagefilledpolygon($bigIm, $strokePoints, $stroke);
    imagefilledpolygon($bigIm, $fillPoints, $fill);

    $small = imagecreatetruecolor($size, $size);
    imagealphablending($small, false);
    imagesavealpha($small, true);
    $sblank = imagecolorallocatealpha($small, 0, 0, 0, 127);
    imagefilledrectangle($small, 0, 0, $size, $size, $sblank);
    imagecopyresampled($small, $bigIm, 0, 0, 0, 0, $size, $size, $big, $big);
    imagedestroy($bigIm);

    return $small;
}

/**
 * Render the social card and atomically publish it.
 *
 * @return bool True on success
 */
function renderMapCard(): bool
{
    // Serialize renders so a slow or retried run never overlaps the next tick's
    // spawn. The lock releases when this process exits.
    ensureCacheDir(CACHE_SOCIAL_DIR);
    $lockFp = @fopen(CACHE_SOCIAL_DIR . '/render.lock', 'c');
    if ($lockFp === false) {
        aviationwx_log('error', 'map card: cannot open render lock', [], 'app');
        return false;
    }
    if (!@flock($lockFp, LOCK_EX | LOCK_NB)) {
        fclose($lockFp);
        aviationwx_log('info', 'map card: render already in progress, skipping', [], 'app');
        return false;
    }

    if (!function_exists('imagecreatetruecolor')) {
        aviationwx_log('error', 'map card: GD extension unavailable', [], 'app');
        return false;
    }

    $config = loadConfig();
    if (!is_array($config)) {
        aviationwx_log('error', 'map card: config unavailable', [], 'app');
        return false;
    }

    $points = [];
    foreach (getListedAirports($config) as $airportId => $airport) {
        $lat = $airport['lat'] ?? null;
        $lon = $airport['lon'] ?? null;
        if (is_numeric($lat) && is_numeric($lon)) {
            $points[] = ['id' => (string) $airportId, 'lat' => (float) $lat, 'lon' => (float) $lon];
        }
    }

    if (count($points) < MAP_CARD_MIN_FOCUS_AIRPORTS) {
        aviationwx_log('warning', 'map card: too few airports with coordinates', [
            'count' => count($points),
        ], 'app');
        return false;
    }

    $seed = mapCardSeed();
    $focus = mapCardSelectFocus($points, $seed);
    $zoom = mapCardChooseZoom($focus, MAP_CARD_CANVAS_WIDTH, MAP_CARD_CANVAS_HEIGHT, MAP_CARD_MAX_ZOOM, MAP_CARD_MARGIN_PX);

    $bounds = mapCardPixelBounds($focus, $zoom);
    $centerX = ($bounds['minX'] + $bounds['maxX']) / 2.0;
    $centerY = ($bounds['minY'] + $bounds['maxY']) / 2.0;
    $originX = $centerX - MAP_CARD_CANVAS_WIDTH / 2.0;
    $originY = $centerY - MAP_CARD_CANVAS_HEIGHT / 2.0;

    $tileMin = (int) floor($originX / MAP_CARD_TILE_SIZE);
    $tileMinY = (int) floor($originY / MAP_CARD_TILE_SIZE);
    $tileMax = (int) floor(($originX + MAP_CARD_CANVAS_WIDTH - 1) / MAP_CARD_TILE_SIZE);
    $tileMaxY = (int) floor(($originY + MAP_CARD_CANVAS_HEIGHT - 1) / MAP_CARD_TILE_SIZE);
    $gridMax = (int) pow(2, $zoom) - 1;

    $radarFrameId = mapCardResolveRadarFrameId();

    $im = imagecreatetruecolor(MAP_CARD_CANVAS_WIDTH, MAP_CARD_CANVAS_HEIGHT);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    $bg = imagecolorallocatealpha($im, 220, 224, 230, 0);
    imagefilledrectangle($im, 0, 0, MAP_CARD_CANVAS_WIDTH, MAP_CARD_CANVAS_HEIGHT, $bg);
    // Blend source alpha over the base so transparent cloud/radar pixels do not
    // erase the OSM map underneath.
    imagealphablending($im, true);

    $baseTiles = 0;
    for ($ty = $tileMinY; $ty <= $tileMaxY; $ty++) {
        for ($tx = $tileMin; $tx <= $tileMax; $tx++) {
            if ($tx < 0 || $tx > $gridMax || $ty < 0 || $ty > $gridMax) {
                continue;
            }
            $dstX = $tx * MAP_CARD_TILE_SIZE - (int) $originX;
            $dstY = $ty * MAP_CARD_TILE_SIZE - (int) $originY;

            foreach (['osm', 'clouds_new', 'rainviewer'] as $layer) {
                $result = fetchMapTile($layer, $zoom, $tx, $ty, $layer === 'rainviewer' ? $radarFrameId : null);
                if (!$result['ok'] || $result['bytes'] === null) {
                    continue;
                }
                $tile = @imagecreatefromstring($result['bytes']);
                if ($tile === false) {
                    continue;
                }
                imagecopy($im, $tile, $dstX, $dstY, 0, 0, MAP_CARD_TILE_SIZE, MAP_CARD_TILE_SIZE);
                imagedestroy($tile);
                if ($layer === 'osm') {
                    $baseTiles++;
                }
            }
        }
    }

    if ($baseTiles === 0) {
        aviationwx_log('error', 'map card: no OSM base tiles rendered', [
            'zoom' => $zoom,
        ], 'app');
        imagedestroy($im);
        return false;
    }

    $marker = mapCardBuildMarker(MAP_CARD_MARKER_SIZE);

    $markerCount = 0;
    $halfMarker = (int) (MAP_CARD_MARKER_SIZE / 2);
    foreach ($points as $p) {
        [$gx, $gy] = mapCardPointPixel($p['lat'], $p['lon'], $zoom);
        $x = (int) round($gx - $originX);
        $y = (int) round($gy - $originY);
        if ($x >= 0 && $x < MAP_CARD_CANVAS_WIDTH && $y >= 0 && $y < MAP_CARD_CANVAS_HEIGHT) {
            imagecopy($im, $marker, $x - $halfMarker, $y - $halfMarker, 0, 0, MAP_CARD_MARKER_SIZE, MAP_CARD_MARKER_SIZE);
            $markerCount++;
        }
    }
    imagedestroy($marker);

    ensureCacheDir(CACHE_SOCIAL_DIR);
    $tmpFile = CACHE_SOCIAL_DIR . '/airports-map.png.tmp.' . getmypid();
    if (!imagepng($im, $tmpFile)) {
        aviationwx_log('error', 'map card: PNG encode failed', [], 'app');
        imagedestroy($im);
        @unlink($tmpFile);
        return false;
    }
    imagedestroy($im);

    $stamp = gmdate('Y-m-d H:i\Z');
    $argv = [
        'exiftool', '-overwrite_original', '-q', '-P',
        '-Copyright=© OpenStreetMap contributors · Cloud data © OpenWeatherMap · Radar © RainViewer · AviationWX.org',
        '-Artist=AviationWX.org',
        '-ImageDescription=' . "Airport directory weather map rendered {$stamp}.",
        '-UserComment=' . "© OpenStreetMap contributors · Cloud data © OpenWeatherMap · Radar © RainViewer · as of {$stamp}",
        $tmpFile,
    ];
    if (!runExiftoolWithRetry($argv)['success']) {
        aviationwx_log('warning', 'map card: exiftool credit write failed, keeping uncredited image', [], 'app');
    }

    if (!@rename($tmpFile, CACHE_SOCIAL_MAP_CARD_FILE)) {
        aviationwx_log('error', 'map card: atomic rename failed', [], 'app');
        @unlink($tmpFile);
        return false;
    }

    aviationwx_log('info', 'map card: rendered', [
        'zoom' => $zoom,
        'markers' => $markerCount,
        'radar_frame' => $radarFrameId ?? 'none',
    ], 'app');

    return true;
}

exit(renderMapCard() ? 0 : 1);
