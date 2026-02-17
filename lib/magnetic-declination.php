<?php

/**
 * Magnetic Declination Lookup (NOAA NCEI Geomagnetic API)
 *
 * Safety-critical: Declination aligns runway wind diagram with magnetic north.
 * Incorrect values could mislead pilots interpreting wind vs runway orientation.
 *
 * Fetches from NOAA NCEI when geomag_api_key is configured. Falls back to config
 * override or 0. Results cached 7 days (declination ~0.1°/year).
 *
 * API: https://www.ngdc.noaa.gov/geomag-web/calculators/calculateDeclination
 * Registration: https://www.ngdc.noaa.gov/geomag/CalcSurvey.shtml
 */

require_once __DIR__ . '/cache-paths.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/circuit-breaker.php';

/** NOAA NCEI geomag-web API base URL */
const GEOMAG_API_BASE = 'https://www.ngdc.noaa.gov/geomag-web/calculators/calculateDeclination';

/** Valid declination range (degrees). Values outside indicate bad data. */
const GEOMAG_DECLINATION_MIN = -180.0;
const GEOMAG_DECLINATION_MAX = 180.0;

/**
 * Fetch magnetic declination from NOAA NCEI API (with cache)
 *
 * Returns null on failure; caller must fall back to config or 0.
 *
 * @param float  $lat    Latitude (-90 to 90)
 * @param float  $lon    Longitude (-180 to 180)
 * @param string $apiKey NOAA geomag API key (from config geomag_api_key)
 * @return float|null Declination in degrees, or null on failure
 */
function fetchMagneticDeclinationFromApi(float $lat, float $lon, string $apiKey): ?float
{
    if ($apiKey === '' || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
        return null;
    }

    if (shouldMockExternalServices()) {
        return null;
    }

    $cachePath = getGeomagDeclinationCachePath($lat, $lon);
    if (file_exists($cachePath)) {
        $age = time() - filemtime($cachePath);
        if ($age < GEOMAG_CACHE_TTL) {
            $data = @json_decode((string) file_get_contents($cachePath), true);
            if (is_array($data) && isset($data['declination'])) {
                $cached = (float) $data['declination'];
                if ($cached >= GEOMAG_DECLINATION_MIN && $cached <= GEOMAG_DECLINATION_MAX) {
                    return $cached;
                }
            }
        }
    }

    $breaker = checkGeomagCircuitBreaker();
    if ($breaker['skip']) {
        return null;
    }

    $url = GEOMAG_API_BASE . '?' . http_build_query([
        'key' => $apiKey,
        'lat1' => round($lat, 6),
        'lon1' => round($lon, 6),
        'resultFormat' => 'json',
    ]);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'AviationWX/1.0 (magnetic-declination)',
    ]);
    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $httpCode !== 200) {
        $isServerError = $httpCode >= 500 || $httpCode === 0;
        $failureReason = $body === false ? 'cURL failed' : "HTTP {$httpCode}";
        recordGeomagFailure($isServerError ? 'transient' : 'permanent', $httpCode ?: null, $failureReason);
        aviationwx_log($isServerError ? 'error' : 'warning', 'geomag api: fetch failed', [
            'lat' => $lat,
            'lon' => $lon,
            'http_code' => $httpCode,
        ], 'app');
        return null;
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        recordGeomagFailure('transient', 200, 'Invalid JSON response');
        return null;
    }

    $declination = extractDeclinationFromResponse($data);
    if ($declination === null) {
        recordGeomagFailure('transient', 200, 'Unexpected response format');
        aviationwx_log('warning', 'geomag api: unexpected response format', [
            'lat' => $lat,
            'lon' => $lon,
        ], 'app');
        return null;
    }

    if ($declination < GEOMAG_DECLINATION_MIN || $declination > GEOMAG_DECLINATION_MAX) {
        recordGeomagFailure('transient', 200, 'Declination out of bounds');
        aviationwx_log('warning', 'geomag api: declination out of bounds', [
            'lat' => $lat,
            'lon' => $lon,
            'declination' => $declination,
        ], 'app');
        return null;
    }

    recordGeomagSuccess();

    ensureCacheDir(CACHE_GEOMAG_DIR);
    $cacheData = ['declination' => $declination, 'fetched_at' => time()];
    @file_put_contents($cachePath, json_encode($cacheData));

    return $declination;
}

/**
 * Extract declination from NOAA API response (handles multiple formats)
 *
 * @param array $data Parsed JSON response
 * @return float|null Declination in degrees (-180 to 180) or null if not found
 */
function extractDeclinationFromResponse(array $data): ?float
{
    if (isset($data['result']['declination'])) {
        return (float) $data['result']['declination'];
    }
    if (isset($data['declination'])) {
        return (float) $data['declination'];
    }
    if (isset($data['declination_value'])) {
        return (float) $data['declination_value'];
    }
    return null;
}
