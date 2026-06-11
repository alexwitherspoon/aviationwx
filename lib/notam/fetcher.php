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
require_once __DIR__ . '/geo-prefilter.php';
require_once __DIR__ . '/rate-limit.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/../notam-health.php';

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
 * Whether decoded NMS JSON reports a successful query.
 *
 * @param array<string, mixed>|null $data Decoded NMS JSON body
 */
function notamNmsResponseIndicatesSuccess(?array $data): bool
{
    if ($data === null) {
        return false;
    }

    $status = $data['status'] ?? null;
    if (!is_string($status)) {
        return false;
    }

    return strcasecmp(trim($status), 'Success') === 0;
}

/**
 * Extract AIXM XML rows from decoded NMS JSON.
 *
 * NMS omits `data.aixm` when there are no NOTAMs (`{"status":"Success","data":{}}`).
 *
 * @param array<string, mixed>|null $data Decoded NMS JSON body
 * @return array<int, string>|null Row list; empty when NMS reports no NOTAMs; null when payload is invalid
 */
function notamExtractAixmRowsFromNmsResponse(?array $data): ?array
{
    if (!notamNmsResponseIndicatesSuccess($data)) {
        return null;
    }

    if (!isset($data['data']) || !is_array($data['data'])) {
        return null;
    }

    if (!array_key_exists('aixm', $data['data'])) {
        return [];
    }

    $aixm = $data['data']['aixm'];
    if ($aixm === null) {
        return [];
    }

    if (!is_array($aixm)) {
        return null;
    }

    return $aixm;
}

/**
 * Block until the shared NMS credential may issue another NOTAM API request.
 *
 * Coordinates across scheduler worker processes (not just this fetch). The
 * $lastRequestTime parameter is retained for backward compatibility.
 *
 * @param float &$lastRequestTime Updated to microtime after a token is acquired
 */
function rateLimitWait(float &$lastRequestTime): void {
    notamRateLimitAcquire();
    $lastRequestTime = microtime(true);
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
 * Build NMS location-query parameters; caller location always wins over extras.
 *
 * @param string $location NMS location query code (merged last so it cannot be overridden)
 * @param array<string, string> $queryParams Optional NMS query parameters
 * @return array<string, string>
 */
function notamBuildLocationQueryParams(string $location, array $queryParams = []): array
{
    return array_merge($queryParams, ['location' => $location]);
}

/**
 * Resolve NMS location-query code from airport config (ICAO, IATA, then FAA).
 *
 * @param array<string, mixed> $airport Airport configuration
 * @return string|null NMS location code or null when no identifier is configured
 */
function notamResolveLocationQueryCode(array $airport): ?string
{
    foreach (['icao', 'iata', 'faa'] as $key) {
        $value = $airport[$key] ?? null;
        if ($value === null || $value === '') {
            continue;
        }
        $code = trim((string) $value);
        if ($code !== '') {
            return $code;
        }
    }

    return null;
}

/**
 * Record a failed NMS query attempt for health metrics and logs.
 *
 * @param string $endpoint Health endpoint key (location, geo)
 * @param string $logMessage Structured log message
 * @param array<string, mixed> $logContext Log context fields
 * @param array{deferred?: bool, http_code?: int|null, error?: string} $queryResult Result from notamExecuteNmsQuery()
 * @param bool $reportQueryOutcome Whether to update $querySucceeded
 * @param bool|null $querySucceeded Outcome flag passed by caller (true success, false hard failure, null deferred)
 */
function notamRecordNmsQueryFailure(
    string $endpoint,
    string $logMessage,
    array $logContext,
    array $queryResult,
    bool $reportQueryOutcome,
    ?bool &$querySucceeded,
): void {
    if (($queryResult['deferred'] ?? false) === true) {
        if ($reportQueryOutcome) {
            $querySucceeded = null;
        }

        return;
    }

    if ($reportQueryOutcome) {
        $querySucceeded = false;
    }

    $httpCode = $queryResult['http_code'] ?? null;
    notamHealthTrackRequest($endpoint, false, is_int($httpCode) ? $httpCode : null);
    aviationwx_log('warning', $logMessage, array_merge($logContext, [
        'http_code' => $httpCode,
        'error' => $queryResult['error'] ?? '',
    ]), 'app');
}

/**
 * Query NOTAMs by NMS location code (ICAO, IATA, or FAA identifier).
 *
 * @param string $location NMS location query code from {@see notamResolveLocationQueryCode()}
 * @param float &$lastRequestTime Last request timestamp (for rate limiting)
 * @param array<string, string> $queryParams Optional NMS query parameters (e.g. feature=RWY)
 * @param bool|null $querySucceeded When passed, true on HTTP 200 with a valid NMS payload (including an empty NOTAM list); false on credential, transport, or payload errors; null when deferred by global backoff
 * @return array<int, string> Array of AIXM XML strings
 */
function queryNotamsByLocation(
    string $location,
    float &$lastRequestTime,
    array $queryParams = [],
    ?bool &$querySucceeded = null,
): array {
    $reportQueryOutcome = func_num_args() >= 4;
    $token = getNotamBearerToken();
    if ($token === null) {
        if ($reportQueryOutcome) {
            $querySucceeded = false;
        }

        return [];
    }

    $baseUrl = getNotamApiBaseUrl();
    $params = notamBuildLocationQueryParams($location, $queryParams);
    $url = rtrim($baseUrl, '/') . '/nmsapi/v1/notams?' . http_build_query($params);

    $queryResult = notamExecuteNmsQuery($url, 'location', $lastRequestTime, $token);
    if (!$queryResult['ok']) {
        notamRecordNmsQueryFailure(
            'location',
            'notam fetcher: location query failed',
            ['location' => $location],
            $queryResult,
            $reportQueryOutcome,
            $querySucceeded,
        );

        return [];
    }

    $response = $queryResult['body'];
    $httpCode = (int) ($queryResult['http_code'] ?? 0);

    $data = notamDecodeNmsJsonResponse($response);
    $aixmRows = notamExtractAixmRowsFromNmsResponse($data);
    if ($aixmRows === null) {
        notamHealthTrackRequest('location', false, $httpCode);
        if ($reportQueryOutcome) {
            $querySucceeded = false;
        }

        return [];
    }

    notamHealthTrackRequest('location', true, $httpCode);

    if ($reportQueryOutcome) {
        $querySucceeded = true;
    }

    return $aixmRows;
}

/**
 * Query NOTAMs by geospatial coordinates
 * 
 * @param float $latitude Latitude in decimal degrees
 * @param float $longitude Longitude in decimal degrees
 * @param int $radius Radius in nautical miles
 * @param float &$lastRequestTime Last request timestamp (for rate limiting)
 * @param bool|null $querySucceeded When passed, true on HTTP 200 with a valid NMS payload (including an empty NOTAM list); false on credential, transport, or payload errors; null when deferred by global backoff
 * @return array Array of AIXM XML strings
 */
function queryNotamsByCoordinates(
    float $latitude,
    float $longitude,
    int $radius,
    float &$lastRequestTime,
    ?bool &$querySucceeded = null,
): array {
    $reportQueryOutcome = func_num_args() >= 5;

    $token = getNotamBearerToken();
    if ($token === null) {
        if ($reportQueryOutcome) {
            $querySucceeded = false;
        }

        return [];
    }
    
    $baseUrl = getNotamApiBaseUrl();
    $url = rtrim($baseUrl, '/') . '/nmsapi/v1/notams?' . http_build_query(
        notamBuildGeoQueryParams($latitude, $longitude, $radius),
    );

    $queryResult = notamExecuteNmsQuery($url, 'geo', $lastRequestTime, $token);
    if (!$queryResult['ok']) {
        notamRecordNmsQueryFailure(
            'geo',
            'notam fetcher: geospatial query failed',
            [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'radius' => $radius,
            ],
            $queryResult,
            $reportQueryOutcome,
            $querySucceeded,
        );

        return [];
    }

    $response = $queryResult['body'];
    $httpCode = (int) ($queryResult['http_code'] ?? 0);

    $data = notamDecodeNmsJsonResponse($response);
    $aixmRows = notamExtractAixmRowsFromNmsResponse($data);
    if ($aixmRows === null) {
        notamHealthTrackRequest('geo', false, $httpCode);
        if ($reportQueryOutcome) {
            $querySucceeded = false;
        }

        return [];
    }

    notamHealthTrackRequest('geo', true, $httpCode);

    if ($reportQueryOutcome) {
        $querySucceeded = true;
    }

    return $aixmRows;
}

/**
 * Deduplicate NOTAMs by canonical key ({@see notamCanonicalDedupKey()}).
 *
 * Merges duplicate payloads (location + geo queries) so the richest NOTAM text survives
 * for TFR parsing and EFFECTIVE window extraction.
 *
 * @param array<int, array<string, mixed>> $notams Parsed NOTAM rows (duplicates may share a public id or fingerprint)
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
 * Aggregate per-query NMS outcomes for dual location + geo fetches.
 *
 * @param list<bool|null> $queryOutcomes Per-query outcome (true success, false hard failure, null deferred)
 * @return array{attempted: bool, fetchSucceeded: bool, allDeferred: bool}
 */
function notamSummarizeFetchQueryOutcomes(array $queryOutcomes): array
{
    $attempted = $queryOutcomes !== [];
    $anySucceeded = in_array(true, $queryOutcomes, true);
    $anyHardFailure = in_array(false, $queryOutcomes, true);
    $allDeferred = $attempted && !$anySucceeded && !$anyHardFailure;

    return [
        'attempted' => $attempted,
        'fetchSucceeded' => $attempted && $anySucceeded,
        'allDeferred' => $allDeferred,
    ];
}

/**
 * Fetch and filter NOTAMs for an airport
 * 
 * Uses dual query strategy:
 * 1. Location-based query (ICAO, IATA, or FAA identifier)
 * 2. Geospatial query for TFRs (AIRSPACE feature; XML pre-filtered before parse)
 * 
 * @param string $airportId Airport ID (e.g., 'khio')
 * @param array<string, mixed> $airport Airport configuration
 * @param bool|null $fetchSucceeded When passed, true when at least one NMS query succeeds; false when no query runs or every attempted query fails
 * @param bool|null $fetchAllDeferred When passed, true when every attempted query was deferred by global NMS backoff
 * @return array<int, array<string, mixed>> Filtered NOTAMs with notam_type and status
 */
function fetchNotamsForAirport(
    string $airportId,
    array $airport,
    ?bool &$fetchSucceeded = null,
    ?bool &$fetchAllDeferred = null,
): array {
    $reportFetchOutcome = func_num_args() >= 3;
    $reportAllDeferred = func_num_args() >= 4;
    $lastRequestTime = 0.0;
    $allNotams = [];
    $queryOutcomes = [];
    
    // 1. Location query via first configured identifier (ICAO, then IATA, then FAA)
    $locationCode = notamResolveLocationQueryCode($airport);
    if ($locationCode !== null) {
        $locationOk = false;
        $locationNotams = queryNotamsByLocation($locationCode, $lastRequestTime, [], $locationOk);
        $queryOutcomes[] = $locationOk;
        $allNotams = array_merge($allNotams, $locationNotams);
    }
    
    // 2. Geospatial query for TFRs (if coordinates available)
    if (isset($airport['lat']) && isset($airport['lon'])) {
        $geoOk = false;
        $radius = NOTAM_GEO_RADIUS_DEFAULT;
        $geoNotams = queryNotamsByCoordinates(
            (float)$airport['lat'],
            (float)$airport['lon'],
            $radius,
            $lastRequestTime,
            $geoOk,
        );
        $queryOutcomes[] = $geoOk;
        // Geo payload is TFR-only after pre-filter; aerodrome closures come from the location query above.
        $filteredGeo = notamFilterGeoXmlForTfrParsing($geoNotams);
        if (count($geoNotams) > 0 && count($filteredGeo) === 0) {
            aviationwx_log('info', 'notam fetcher: geo prefilter dropped all AIXM rows', [
                'airport' => $airportId,
                'raw_count' => count($geoNotams),
            ], 'app');
        }
        $allNotams = array_merge($allNotams, $filteredGeo);
    }

    $fetchSummary = notamSummarizeFetchQueryOutcomes($queryOutcomes);
    if ($reportFetchOutcome) {
        $fetchSucceeded = $fetchSummary['fetchSucceeded'];
    }
    if ($reportAllDeferred) {
        $fetchAllDeferred = $fetchSummary['allDeferred'];
    }
    
    // 3. Parse XML to structured data
    $parsedNotams = parseNotamXmlArray($allNotams);
    
    // 4. Deduplicate by NOTAM ID
    $deduplicated = deduplicateNotams($parsedNotams);
    
    // 5. Filter for relevant closures and TFRs
    $filtered = filterRelevantNotams($deduplicated, $airport);
    
    return $filtered;
}
