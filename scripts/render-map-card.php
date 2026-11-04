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
const MAP_CARD_LOGO_WIDTH = 231;
const MAP_CARD_LOGO_MARGIN = 14;
const MAP_CARD_CREDIT_HEIGHT = 30;
const MAP_CARD_CREDIT_FONT_SIZE = 10;

/**
 * Render seed. Defaults to the UTC date so every node renders the same view for
 * a given day. MAP_CARD_SEED overrides it for one-off previews; the scheduler
 * never sets it, so production stays date-deterministic.
 *
 * @return int Deterministic render seed
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
 * @return string|null 12-hex radar frame id, or null when unavailable
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
 * Build the grey airplane marker from the committed raster of the client's SVG.
 *
 * public/images/airport-marker.png is the exact dashboard icon path (grey fill,
 * #1a1a1a 1.5-unit round-join stroke) on a transparent background, so
 * compositing it matches the browser instead of approximating it in GD.
 *
 * @param int $size Marker edge length in pixels
 * @return GdImage|null Null when the asset is missing or unreadable.
 */
function mapCardBuildMarker(int $size): ?GdImage
{
    $asset = __DIR__ . '/../public/images/airport-marker.png';
    $src = @imagecreatefrompng($asset);
    if ($src === false) {
        aviationwx_log('warning', 'map card: marker asset missing', ['path' => $asset], 'app');
        return null;
    }

    $marker = imagecreatetruecolor($size, $size);
    imagealphablending($marker, false);
    imagesavealpha($marker, true);
    $blank = imagecolorallocatealpha($marker, 0, 0, 0, 127);
    imagefilledrectangle($marker, 0, 0, $size, $size, $blank);
    imagecopyresampled($marker, $src, 0, 0, 0, 0, $size, $size, imagesx($src), imagesy($src));
    imagedestroy($src);

    return $marker;
}

/**
 * Best available TTF font for card text, or null when none is installed.
 *
 * @return string|null Path to a TTF font, or null when none is installed
 */
function mapCardFontPath(): ?string
{
    foreach ([
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
    ] as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

/**
 * Composite the brand logo into the top-left corner over the map.
 *
 * @param GdImage $im Canvas to draw onto
 * @return void
 */
function mapCardDrawLogo(GdImage $im): void
{
    $logoPath = __DIR__ . '/../public/images/logo.png';
    if (!is_file($logoPath)) {
        return;
    }
    $logo = @imagecreatefrompng($logoPath);
    if ($logo === false) {
        return;
    }

    $srcW = imagesx($logo);
    $srcH = imagesy($logo);
    $w = MAP_CARD_LOGO_WIDTH;
    $h = (int) round($srcH * ($w / $srcW));

    $scaled = imagecreatetruecolor($w, $h);
    imagealphablending($scaled, false);
    imagesavealpha($scaled, true);
    $blank = imagecolorallocatealpha($scaled, 0, 0, 0, 127);
    imagefilledrectangle($scaled, 0, 0, $w, $h, $blank);
    imagecopyresampled($scaled, $logo, 0, 0, 0, 0, $w, $h, $srcW, $srcH);

    imagecopy($im, $scaled, MAP_CARD_LOGO_MARGIN, MAP_CARD_LOGO_MARGIN, 0, 0, $w, $h);
    imagedestroy($scaled);
    imagedestroy($logo);
}

/**
 * Draw the visible credit bar across the bottom: OSM/cloud/radar attribution
 * on the left and the "as of" UTC stamp on the right.
 *
 * @param GdImage $im Canvas to draw onto
 * @param string $stamp Human-readable UTC render stamp
 * @return void
 */
function mapCardDrawCredit(GdImage $im, string $stamp): void
{
    $credit = '© OpenStreetMap contributors · Clouds © OpenWeatherMap · Radar © RainViewer';
    $asOf = 'as of ' . $stamp;

    $w = imagesx($im);
    $h = imagesy($im);
    $barTop = $h - MAP_CARD_CREDIT_HEIGHT;

    // Translucent dark band so the credit stays legible over any map color.
    $band = imagecolorallocatealpha($im, 0, 0, 0, 80);
    imagefilledrectangle($im, 0, $barTop, $w, $h, $band);

    $white = imagecolorallocate($im, 255, 255, 255);
    $size = MAP_CARD_CREDIT_FONT_SIZE;
    $pad = MAP_CARD_LOGO_MARGIN;
    $font = mapCardFontPath();

    if ($font !== null && function_exists('imagettftext')) {
        $baseline = $barTop + $size + (int) round((MAP_CARD_CREDIT_HEIGHT - $size) / 2);
        imagettftext($im, $size, 0, $pad, $baseline, $white, $font, $credit);
        $box = imagettfbbox($size, 0, $font, $asOf);
        $asOfW = (int) ($box[2] - $box[0]);
        imagettftext($im, $size, 0, $w - $pad - $asOfW, $baseline, $white, $font, $asOf);
    } else {
        // Built-in font fallback; © and · drop out, keep the OSM credit so the
        // attribution stays visible even without FreeType.
        imagestring($im, 3, $pad, $barTop + 10, '(c) OpenStreetMap contributors', $white);
    }
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
    if ($marker !== null) {
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
    }

    $stamp = gmdate('Y-m-d H:i') . ' UTC';
    mapCardDrawLogo($im);
    mapCardDrawCredit($im, $stamp);

    ensureCacheDir(CACHE_SOCIAL_DIR);
    $tmpFile = CACHE_SOCIAL_DIR . '/airports-map.png.tmp.' . getmypid();
    if (!imagepng($im, $tmpFile)) {
        aviationwx_log('error', 'map card: PNG encode failed', [], 'app');
        imagedestroy($im);
        @unlink($tmpFile);
        return false;
    }
    imagedestroy($im);

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
