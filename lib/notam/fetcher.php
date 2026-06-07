<?php
/**
 * NOTAM Fetcher
 * 
 * Fetches NOTAMs from NMS API for a single airport
 */

require_once __DIR__ . '/../logger.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/parser.php';
require_once __DIR__ . '/filter.php';
require_once __DIR__ . '/schedule.php';

/**
 * Decode NMS JSON; strips illegal ASCII control characters some payloads embed in strings.
 *
 * @param string $response Raw HTTP body
 * @return array|null Decoded array or null on failure
 */
function notamDecodeNmsJsonResponse(string $response): ?array {
    $clean = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', '', $response);
    $data = json_decode($clean, true);
    if (!is_array($data)) {
        return null;
    }
    return $data;
}

/**
 * Rate limit wait - ensures we don't exceed 1 request per second
 * 
 * @param float &$lastRequestTime Last request timestamp (passed by reference)
 * @return void
 */
function rateLimitWait(float &$lastRequestTime): void {
    $now = microtime(true);
    $elapsed = $now - $lastRequestTime;
    if ($elapsed < NOTAM_RATE_LIMIT_SECONDS) {
        $waitTime = NOTAM_RATE_LIMIT_SECONDS - $elapsed;
        usleep((int)($waitTime * 1000000));
        $lastRequestTime = microtime(true);
    } else {
        $lastRequestTime = $now;
    }
}

/**
 * Stable deduplication key for parsed NOTAM rows.
 *
 * @param array<string, mixed> $notam Parsed NOTAM row
 * @return string Dedup key (public id or location/start/text fingerprint)
 */
function notamCanonicalDedupKey(array $notam): string {
    $id = trim((string) ($notam['id'] ?? ''));
    if ($id !== '') {
        return 'id:' . $id;
    }

    $location = strtoupper(trim((string) ($notam['location'] ?? '')));
    $start = trim((string) ($notam['start_time_utc'] ?? ''));
    $text = trim((string) ($notam['text'] ?? ''));

    return 'fp:' . $location . '|' . $start . '|' . substr(sha1($text), 0, 16);
}

/**
 * Query NOTAMs by location (ICAO code)
 *
 * @param string $location ICAO code
 * @param float &$lastRequestTime Last request timestamp (for rate limiting)
 * @param array<string, string> $queryParams Optional NMS query parameters (e.g. feature=RWY)
 * @return array<int, string> Array of AIXM XML strings
 */
function queryNotamsByLocation(string $location, float &$lastRequestTime, array $queryParams = []): array {
    $token = getNotamBearerToken();
    if ($token === null) {
        return [];
    }

    $baseUrl = getNotamApiBaseUrl();
    $params = array_merge(['location' => $location], $queryParams);
    $url = rtrim($baseUrl, '/') . '/nmsapi/v1/notams?' . http_build_query($params);
    
    rateLimitWait($lastRequestTime);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'nmsResponseFormat: AIXM',
            'Accept: application/json'
        ],
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    if ($httpCode !== 200 || $response === false) {
        aviationwx_log('warning', 'notam fetcher: location query failed', [
            'location' => $location,
            'http_code' => $httpCode,
            'error' => $error
        ], 'app');
        return [];
    }
    
    $data = notamDecodeNmsJsonResponse($response);
    if ($data === null || !isset($data['data']['aixm']) || !is_array($data['data']['aixm'])) {
        return [];
    }
    
    return $data['data']['aixm'];
}

/**
 * Query NOTAMs by geospatial coordinates
 * 
 * @param float $latitude Latitude in decimal degrees
 * @param float $longitude Longitude in decimal degrees
 * @param int $radius Radius in nautical miles
 * @param float &$lastRequestTime Last request timestamp (for rate limiting)
 * @return array Array of AIXM XML strings
 */
function queryNotamsByCoordinates(float $latitude, float $longitude, int $radius, float &$lastRequestTime): array {
    $token = getNotamBearerToken();
    if ($token === null) {
        return [];
    }
    
    $baseUrl = getNotamApiBaseUrl();
    $url = rtrim($baseUrl, '/') . '/nmsapi/v1/notams?' . http_build_query([
        'latitude' => $latitude,
        'longitude' => $longitude,
        'radius' => $radius
    ]);
    
    rateLimitWait($lastRequestTime);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'nmsResponseFormat: AIXM',
            'Accept: application/json'
        ],
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    if ($httpCode !== 200 || $response === false) {
        aviationwx_log('warning', 'notam fetcher: geospatial query failed', [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'radius' => $radius,
            'http_code' => $httpCode,
            'error' => $error
        ], 'app');
        return [];
    }
    
    $data = notamDecodeNmsJsonResponse($response);
    if ($data === null || !isset($data['data']['aixm']) || !is_array($data['data']['aixm'])) {
        return [];
    }
    
    return $data['data']['aixm'];
}

/**
 * Deduplicate NOTAMs by ID
 * 
 * Merges duplicate payloads (location + geo queries) so the richest NOTAM text survives
 * for TFR parsing and EFFECTIVE window extraction.
 * 
 * @param array<int, array<string, mixed>> $notams Parsed NOTAM rows (same id may appear from location and geo queries)
 * @return array<int, array<string, mixed>> Deduplicated rows merged by {@see mergeParsedNotamDuplicates()}
 */
function deduplicateNotams(array $notams): array {
    $byKey = [];

    foreach ($notams as $notam) {
        $key = notamCanonicalDedupKey($notam);
        if (!isset($byKey[$key])) {
            $byKey[$key] = $notam;
            continue;
        }
        $byKey[$key] = mergeParsedNotamDuplicates($byKey[$key], $notam);
    }

    return array_values($byKey);
}

/**
 * Fetch and filter NOTAMs for an airport
 * 
 * Uses dual query strategy:
 * 1. Location-based query (if ICAO/IATA available)
 * 2. Geospatial query (for TFRs and fallback for FAA identifiers)
 * 
 * @param string $airportId Airport ID (e.g., 'khio')
 * @param array<string, mixed> $airport Airport configuration
 * @return array<int, array<string, mixed>> Filtered NOTAMs with notam_type and status
 */
function fetchNotamsForAirport(string $airportId, array $airport): array {
    $lastRequestTime = 0.0;
    $allNotams = [];
    
    // 1. Try location-based query (if ICAO/IATA available)
    $icao = $airport['icao'] ?? null;
    $iata = $airport['iata'] ?? null;
    
    if (!empty($icao)) {
        $locationNotams = queryNotamsByLocation($icao, $lastRequestTime);
        $allNotams = array_merge($allNotams, $locationNotams);
    } elseif (!empty($iata)) {
        // IATA codes are auto-resolved to ICAO by API
        $locationNotams = queryNotamsByLocation($iata, $lastRequestTime);
        $allNotams = array_merge($allNotams, $locationNotams);
    }
    
    // 2. Try geospatial query (if coordinates available)
    // Always do this for TFRs, and as fallback for FAA identifiers
    if (isset($airport['lat']) && isset($airport['lon'])) {
        $radius = NOTAM_GEO_RADIUS_DEFAULT;
        $geoNotams = queryNotamsByCoordinates(
            (float)$airport['lat'],
            (float)$airport['lon'],
            $radius,
            $lastRequestTime
        );
        $allNotams = array_merge($allNotams, $geoNotams);
    }
    
    // 3. Parse XML to structured data
    $parsedNotams = parseNotamXmlArray($allNotams);
    
    // 4. Deduplicate by NOTAM ID
    $deduplicated = deduplicateNotams($parsedNotams);
    
    // 5. Filter for relevant closures and TFRs
    $filtered = filterRelevantNotams($deduplicated, $airport);
    
    return $filtered;
}
