<?php
/**
 * Map Card Math
 *
 * Pure, GD-free helpers for the social-card map renderer: web-mercator tile
 * projection, zoom selection, and the daily random focus that keeps a minimum
 * number of airports in frame.
 */

// RainViewer caps radar tiles at zoom 7, which is the ceiling for the card.
if (!defined('MAP_CARD_MAX_ZOOM')) {
    define('MAP_CARD_MAX_ZOOM', 7);
}

if (!defined('MAP_CARD_MIN_FOCUS_AIRPORTS')) {
    define('MAP_CARD_MIN_FOCUS_AIRPORTS', 3);
}

/** Tile edge length in pixels. */
if (!defined('MAP_CARD_TILE_SIZE')) {
    define('MAP_CARD_TILE_SIZE', 256);
}

/**
 * Great-circle distance in kilometers.
 *
 * @param float $lat1 Latitude of first point in degrees
 * @param float $lon1 Longitude of first point in degrees
 * @param float $lat2 Latitude of second point in degrees
 * @param float $lon2 Longitude of second point in degrees
 * @return float Great-circle distance in kilometers
 */
function mapCardDistanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $toRad = M_PI / 180.0;
    $dLat = ($lat2 - $lat1) * $toRad;
    $dLon = ($lon2 - $lon1) * $toRad;
    $a = sin($dLat / 2) * sin($dLat / 2)
        + cos($lat1 * $toRad) * cos($lat2 * $toRad)
        * sin($dLon / 2) * sin($dLon / 2);

    return 6371.0 * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/**
 * Fractional tile X for a longitude at a zoom (web mercator, EPSG:3857).
 *
 * @param float $lon Longitude in degrees
 * @param int $z Zoom level
 * @return float Fractional tile X, unscaled to pixels
 */
function mapCardLonToTileX(float $lon, int $z): float
{
    $n = (float) pow(2, $z);
    return (($lon + 180.0) / 360.0) * $n;
}

/**
 * Fractional tile Y for a latitude at a zoom (web mercator).
 *
 * @param float $lat Latitude in degrees
 * @param int $z Zoom level
 * @return float Fractional tile Y, unscaled to pixels
 */
function mapCardLatToTileY(float $lat, int $z): float
{
    $clamped = max(-85.05112878, min(85.05112878, $lat));
    $latRad = deg2rad($clamped);
    $n = (float) pow(2, $z);

    return (1.0 - log(tan($latRad) + 1 / cos($latRad)) / M_PI) / 2.0 * $n;
}

/**
 * Pixel coordinate within the global tile grid for a point at a zoom.
 *
 * @param float $lat Latitude in degrees
 * @param float $lon Longitude in degrees
 * @param int $z Zoom level
 * @return array{0: float, 1: float} Global pixel coordinate [px, py]
 */
function mapCardPointPixel(float $lat, float $lon, int $z): array
{
    return [
        mapCardLonToTileX($lon, $z) * MAP_CARD_TILE_SIZE,
        mapCardLatToTileY($lat, $z) * MAP_CARD_TILE_SIZE,
    ];
}

/**
 * Global pixel bounding box over a set of points at a zoom.
 *
 * @param list<array{lat: float, lon: float}> $points Points to bound
 * @param int $z Zoom level
 * @return array{minX: float, minY: float, maxX: float, maxY: float} Global pixel bounding box
 */
function mapCardPixelBounds(array $points, int $z): array
{
    $minX = INF; $maxX = -INF; $minY = INF; $maxY = -INF;
    foreach ($points as $p) {
        [$px, $py] = mapCardPointPixel((float) $p['lat'], (float) $p['lon'], $z);
        $minX = min($minX, $px);
        $maxX = max($maxX, $px);
        $minY = min($minY, $py);
        $maxY = max($maxY, $py);
    }

    return ['minX' => $minX, 'minY' => $minY, 'maxX' => $maxX, 'maxY' => $maxY];
}

/**
 * Deterministic pseudo-random integer in [0, $upperBound).
 *
 * Date-seeded and index-keyed, so every node renders the same view for a day
 * without sharing RNG state.
 *
 * @param int $seed Deterministic seed
 * @param int $index Draw index within the seed
 * @param int $upperBound Exclusive upper bound
 * @return int Value in [0, $upperBound)
 */
function mapCardPseudoRandom(int $seed, int $index, int $upperBound): int
{
    if ($upperBound <= 1) {
        return 0;
    }

    return (int) sprintf('%u', crc32($seed . ':' . $index)) % $upperBound;
}

/**
 * Choose a focus: at least $minCount airports, biased toward dense coverage.
 *
 * The seed airport is picked with weight proportional to how many airports sit
 * near it, then the nearest neighbors join it until $minCount is reached (or the
 * whole list when there are fewer available).
 *
 * @param list<array{id: string, lat: float, lon: float}> $points Configured airports with coordinates
 * @param int $seed Deterministic seed
 * @param int $densityKm Radius used to score how dense an airport's neighborhood is
 * @return list<array{id: string, lat: float, lon: float}> Chosen focus airports
 */
function mapCardSelectFocus(array $points, int $seed, int $densityKm = 60): array
{
    $n = count($points);
    if ($n === 0) {
        return [];
    }
    if ($n <= MAP_CARD_MIN_FOCUS_AIRPORTS) {
        return $points;
    }

    // Weighted seed selection: score each airport by its neighborhood density.
    $weights = [];
    foreach ($points as $i => $p) {
        $nearby = 1.0 + count(array_filter($points, static function (array $q) use ($p, $densityKm): bool {
            return mapCardDistanceKm($p['lat'], $p['lon'], $q['lat'], $q['lon']) <= $densityKm;
        }));
        $weights[$i] = $nearby;
    }

    $draw = mapCardPseudoRandom($seed, 0, 100000) / 100000.0;
    $total = array_sum($weights);
    $cumulative = 0.0;
    $seedIndex = array_key_first($points);
    foreach ($weights as $i => $w) {
        $cumulative += $w / $total;
        if ($draw <= $cumulative) {
            $seedIndex = $i;
            break;
        }
    }
    if (!isset($points[$seedIndex])) {
        $seedIndex = array_key_first($points);
    }

    $seedPoint = $points[$seedIndex];

    // Order the rest by distance to the seed and take the closest.
    $others = [];
    foreach ($points as $i => $p) {
        if ($i === $seedIndex) {
            continue;
        }
        $others[$i] = mapCardDistanceKm(
            $seedPoint['lat'], $seedPoint['lon'], $p['lat'], $p['lon']
        );
    }
    asort($others);

    $chosenKeys = [$seedIndex => true];
    foreach (array_keys($others) as $i) {
        $chosenKeys[$i] = true;
        if (count($chosenKeys) >= MAP_CARD_MIN_FOCUS_AIRPORTS) {
            break;
        }
    }

    $chosen = [];
    foreach (array_keys($chosenKeys) as $i) {
        $chosen[] = $points[$i];
    }

    return $chosen;
}

/**
 * Highest zoom (<= $maxZoom) where the given points still fit the canvas.
 *
 * Iterates from $maxZoom down and returns the first zoom at which every point's
 * pixel span, plus a margin, stays inside the canvas. Higher zoom spreads points
 * further apart, so the first (highest) fitting zoom is the most zoomed-in
 * "regional" frame that still shows them all.
 *
 * @param list<array{lat: float, lon: float}> $points
 * @param int $width Canvas width in pixels
 * @param int $height Canvas height in pixels
 * @param int $maxZoom Highest zoom level to try
 * @param int $marginPx Pixel margin added around the point span
 * @return int Highest zoom level that still fits
 */
function mapCardChooseZoom(array $points, int $width, int $height, int $maxZoom, int $marginPx = 80): int
{
    for ($z = $maxZoom; $z >= 0; $z--) {
        $b = mapCardPixelBounds($points, $z);

        if (($b['maxX'] - $b['minX'] + 2 * $marginPx) <= $width && ($b['maxY'] - $b['minY'] + 2 * $marginPx) <= $height) {
            return $z;
        }
    }

    return 0;
}