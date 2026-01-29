<?php
/**
 * NOTAM Filter
 * 
 * Filters NOTAMs for aerodrome closures and TFRs relevant to an airport.
 * Safety-critical: Ensures only geographically relevant NOTAMs are shown to pilots.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/../units.php';
require_once __DIR__ . '/../airport-identifiers.php';
require_once __DIR__ . '/../weather/utils.php';

/**
 * Parse coordinates from TFR NOTAM text
 * 
 * Parses the first coordinate pair found in standard aviation format.
 * Note: Only parses single-point TFRs; multi-point polygon TFRs will use only the first point.
 * 
 * Supported formats:
 * - DDMMSSN/DDDMMSSW (e.g., 413900N1122300W)
 * - With optional whitespace between lat/lon
 * 
 * @param string $text NOTAM text to parse (empty string returns null)
 * @return array{lat: float, lon: float}|null Decimal degrees or null if no valid coordinates found
 */
function parseTfrCoordinates(string $text): ?array {
    // Pattern: DDMMSSN/S followed by DDDMMSSW/E
    // Example: 413900N1122300W = 41°39'00"N 112°23'00"W
    $pattern = '/(\d{2})(\d{2})(\d{2})([NS])\s*(\d{2,3})(\d{2})(\d{2})([EW])/i';
    
    if (preg_match($pattern, $text, $matches)) {
        $latDeg = (int)$matches[1];
        $latMin = (int)$matches[2];
        $latSec = (int)$matches[3];
        $latDir = strtoupper($matches[4]);
        
        $lonDeg = (int)$matches[5];
        $lonMin = (int)$matches[6];
        $lonSec = (int)$matches[7];
        $lonDir = strtoupper($matches[8]);
        
        // Convert to decimal degrees
        $lat = $latDeg + ($latMin / 60) + ($latSec / 3600);
        $lon = $lonDeg + ($lonMin / 60) + ($lonSec / 3600);
        
        // Apply direction
        if ($latDir === 'S') {
            $lat = -$lat;
        }
        if ($lonDir === 'W') {
            $lon = -$lon;
        }
        
        // Validate ranges
        if ($lat >= -90 && $lat <= 90 && $lon >= -180 && $lon <= 180) {
            return ['lat' => $lat, 'lon' => $lon];
        }
    }
    
    return null;
}

/**
 * Parse TFR radius from NOTAM text
 * 
 * FAA NOTAMs specify TFR radii in nautical miles (NM).
 * Values outside TFR_RADIUS_MIN_NM to TFR_RADIUS_MAX_NM are rejected as parsing errors.
 * 
 * Supported formats:
 * - "5NM RADIUS" or "5 NM RADIUS"
 * - "5 NAUTICAL MILE RADIUS"
 * - "RADIUS OF 5NM"
 * - "WITHIN 5NM"
 * 
 * @param string $text NOTAM text to parse (empty string returns null)
 * @return float|null Radius in nautical miles, or null if not found/invalid
 */
function parseTfrRadiusNm(string $text): ?float {
    // Pattern: number followed by NM/NAUTICAL MILE(S) and RADIUS
    // Or RADIUS followed by number and NM
    $patterns = [
        '/(\d+(?:\.\d+)?)\s*NM\s+RADIUS/i',
        '/(\d+(?:\.\d+)?)\s*NAUTICAL\s+MILES?\s+RADIUS/i',
        '/RADIUS\s+(?:OF\s+)?(\d+(?:\.\d+)?)\s*NM/i',
        '/(\d+(?:\.\d+)?)\s*NM\s+AREA/i',
        '/WITHIN\s+(\d+(?:\.\d+)?)\s*NM/i',
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $matches)) {
            $radiusNm = (float)$matches[1];
            // Sanity check using constants
            if ($radiusNm >= TFR_RADIUS_MIN_NM && $radiusNm <= TFR_RADIUS_MAX_NM) {
                return $radiusNm;
            }
        }
    }
    
    return null;
}

/**
 * Calculate haversine distance between two points in nautical miles
 * 
 * Uses the standard haversine formula with Earth radius constant from units.php
 * 
 * @param float $lat1 Latitude of point 1 (decimal degrees)
 * @param float $lon1 Longitude of point 1 (decimal degrees)
 * @param float $lat2 Latitude of point 2 (decimal degrees)
 * @param float $lon2 Longitude of point 2 (decimal degrees)
 * @return float Distance in nautical miles
 */
function calculateDistanceNm(float $lat1, float $lon1, float $lat2, float $lon2): float {
    // Convert to radians
    $lat1Rad = deg2rad($lat1);
    $lat2Rad = deg2rad($lat2);
    $deltaLat = deg2rad($lat2 - $lat1);
    $deltaLon = deg2rad($lon2 - $lon1);
    
    // Haversine formula
    $a = sin($deltaLat / 2) * sin($deltaLat / 2) +
         cos($lat1Rad) * cos($lat2Rad) *
         sin($deltaLon / 2) * sin($deltaLon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    
    return EARTH_RADIUS_NAUTICAL_MILES * $c;
}

/**
 * Get all airport identifiers (current and historical)
 * 
 * @param array $airport Airport configuration
 * @return array Array of uppercase identifier strings
 */
function getAirportIdentifiers(array $airport): array {
    $identifiers = [];
    
    // Current identifiers
    if (isset($airport['icao'])) {
        $identifiers[] = strtoupper($airport['icao']);
    }
    if (isset($airport['iata'])) {
        $identifiers[] = strtoupper($airport['iata']);
    }
    if (isset($airport['faa'])) {
        $identifiers[] = strtoupper($airport['faa']);
    }
    
    // Historical identifiers
    if (isset($airport['formerly']) && is_array($airport['formerly'])) {
        foreach ($airport['formerly'] as $former) {
            $identifiers[] = strtoupper($former);
        }
    }
    
    return array_unique($identifiers);
}

/**
 * Check if needle exists in haystack as a whole word (word boundary match)
 * 
 * Prevents false positives like "FIELD" matching "SPRINGFIELD".
 * Uses word boundary regex to ensure the match is not part of a larger word.
 * 
 * @param string $haystack Text to search in (should be uppercase)
 * @param string $needle Word to find (should be uppercase)
 * @return bool True if needle found as a whole word
 */
function isWordMatch(string $haystack, string $needle): bool {
    if (empty($needle) || empty($haystack)) {
        return false;
    }
    // Use word boundary \b to match whole words only
    $pattern = '/\b' . preg_quote($needle, '/') . '\b/';
    return preg_match($pattern, $haystack) === 1;
}

/**
 * Check if NOTAM is a cancellation (type C)
 * 
 * NOTAM types:
 * - N = New
 * - R = Replace
 * - C = Cancel (cancels a previous NOTAM)
 * 
 * Cancel NOTAMs indicate a restriction has been lifted and should not be
 * displayed as warnings (they're "good news" - e.g., "runway closure CANCELED").
 * 
 * @param array $notam Parsed NOTAM data with 'type' and 'text' fields
 * @return bool True if this is a cancellation NOTAM
 */
function isNotamCancellation(array $notam): bool {
    // Check type field from parser (N=New, R=Replace, C=Cancel)
    $type = strtoupper($notam['type'] ?? '');
    if ($type === 'C') {
        return true;
    }
    
    // Also check for NOTAMC in text (backup detection)
    $text = strtoupper($notam['text'] ?? '');
    if (strpos($text, 'NOTAMC') !== false) {
        return true;
    }
    
    // Check for "CANCELED" or "CANCELLED" at end of text (indicates cancellation)
    if (preg_match('/\bCANCEL+ED\s*$/', $text)) {
        return true;
    }
    
    return false;
}

/**
 * Check if NOTAM is a runway or aerodrome closure/hazard
 * 
 * Only matches runway-level and above issues (not taxiway/apron closures):
 * - QMR* = Runway (closed, hazardous, etc.)
 * - QFA* = Aerodrome/airport (closed, services unavailable, etc.)
 * 
 * Excludes:
 * - QMX* = Taxiway closures (less critical)
 * - QMA* = Apron/ramp closures (less critical)
 * - QMP* = Parking area closures (less critical)
 * - Type C (Cancel) NOTAMs (restriction lifted, not a warning)
 * 
 * @param array $notam Parsed NOTAM data
 * @param array $airport Airport configuration
 * @return bool True if runway/aerodrome closure or hazard (and not a cancellation)
 */
function isAerodromeClosure(array $notam, array $airport): bool {
    // Exclude cancellation NOTAMs - they indicate restriction is lifted
    if (isNotamCancellation($notam)) {
        return false;
    }
    
    $code = strtoupper($notam['code'] ?? '');
    $text = strtoupper($notam['text'] ?? '');
    
    // Q-code filter - only runway (QMR) or aerodrome (QFA) level issues
    $isRunway = strpos($code, 'QMR') === 0;
    $isAerodrome = strpos($code, 'QFA') === 0;
    
    if (!$isRunway && !$isAerodrome) {
        return false;
    }
    
    // Text validation - must contain closure or hazard indicators
    $hasClosure = strpos($text, 'CLSD') !== false || strpos($text, 'CLOSED') !== false;
    $hasHazard = strpos($text, 'HAZARD') !== false || strpos($text, 'UNSAFE') !== false;
    
    if (!$hasClosure && !$hasHazard) {
        return false;
    }
    
    // Location match (current or historical identifiers)
    $identifiers = getAirportIdentifiers($airport);
    $notamLocation = strtoupper($notam['location'] ?? '');
    
    if (!in_array($notamLocation, $identifiers)) {
        // Also check airport name matching for geospatial queries (word boundary match)
        $airportName = strtoupper($airport['name'] ?? '');
        $notamAirportName = strtoupper($notam['airport_name'] ?? '');
        
        if (empty($airportName) || empty($notamAirportName)) {
            return false;
        }
        // Use word boundary matching to avoid "FIELD" matching "SPRINGFIELD"
        if (!isWordMatch($notamAirportName, $airportName) && !isWordMatch($airportName, $notamAirportName)) {
            return false;
        }
    }
    
    return true;
}

/**
 * Check if NOTAM is a TFR (Temporary Flight Restriction)
 * 
 * Excludes cancellation NOTAMs (type C) since they indicate the TFR is lifted.
 * 
 * @param array $notam Parsed NOTAM data with 'text' and 'type' fields
 * @return bool True if TFR indicators found and not a cancellation
 */
function isTfr(array $notam): bool {
    // Exclude cancellation NOTAMs - they indicate restriction is lifted
    if (isNotamCancellation($notam)) {
        return false;
    }
    
    $text = strtoupper($notam['text'] ?? '');
    
    // Primary indicators (text already uppercase, use strpos for efficiency)
    if (strpos($text, 'TFR') !== false) {
        return true;
    }
    if (strpos($text, 'TEMPORARY FLIGHT RESTRICTION') !== false) {
        return true;
    }
    
    // Secondary indicators - both must be present
    if (strpos($text, 'RESTRICTED') !== false && strpos($text, 'AIRSPACE') !== false) {
        return true;
    }
    
    return false;
}

/**
 * Check if TFR is relevant to airport based on geographic proximity
 * 
 * A TFR is relevant if any of these conditions are met:
 * 1. The NOTAM location field matches an airport identifier
 * 2. The NOTAM airport_name field matches the airport name (word boundary match)
 * 3. The TFR text explicitly mentions the airport's name or identifier
 * 4. The airport is within the TFR's geographic boundary (radius + buffer)
 * 
 * All distance calculations use nautical miles (standard aviation unit).
 * Conservative approach: excludes TFR when coordinates cannot be parsed.
 * 
 * @param array $tfr Parsed TFR NOTAM data with 'text', 'location', 'airport_name' fields
 * @param array $airport Airport config with 'name', 'lat', 'lon', and identifier fields
 * @return bool True if TFR is relevant to this airport
 */
function isTfrRelevantToAirport(array $tfr, array $airport): bool {
    $text = $tfr['text'] ?? '';
    $textUpper = strtoupper($text);
    $airportName = strtoupper($airport['name'] ?? '');
    $identifiers = getAirportIdentifiers($airport);
    
    // Check if NOTAM location field matches an airport identifier
    $notamLocation = strtoupper($tfr['location'] ?? '');
    if (!empty($notamLocation) && in_array($notamLocation, $identifiers)) {
        return true;
    }
    
    // Check if NOTAM airport_name matches (word boundary to avoid "FIELD" matching "SPRINGFIELD")
    $notamAirportName = strtoupper($tfr['airport_name'] ?? '');
    if (!empty($notamAirportName) && !empty($airportName)) {
        if (isWordMatch($notamAirportName, $airportName) || isWordMatch($airportName, $notamAirportName)) {
            return true;
        }
    }
    
    // Check if TFR text mentions airport name (word boundary match)
    if (!empty($airportName) && isWordMatch($textUpper, $airportName)) {
        return true;
    }
    
    // Check if TFR text mentions any airport identifier (word boundary match)
    foreach ($identifiers as $identifier) {
        if (!empty($identifier) && isWordMatch($textUpper, $identifier)) {
            return true;
        }
    }
    
    // Geographic relevance check - parse TFR coordinates and check distance
    if (!isset($airport['lat']) || !isset($airport['lon'])) {
        // No airport coordinates - be conservative and exclude
        return false;
    }
    
    $airportLat = (float)$airport['lat'];
    $airportLon = (float)$airport['lon'];
    
    // Parse TFR center coordinates from text
    $tfrCoords = parseTfrCoordinates($text);
    if ($tfrCoords === null) {
        // Cannot parse coordinates - be conservative and exclude
        return false;
    }
    
    // Parse TFR radius in nautical miles (or use default)
    $tfrRadiusNm = parseTfrRadiusNm($text) ?? TFR_DEFAULT_RADIUS_NM;
    
    // Calculate distance from airport to TFR center
    $distanceNm = calculateDistanceNm(
        $airportLat,
        $airportLon,
        $tfrCoords['lat'],
        $tfrCoords['lon']
    );
    
    // TFR is relevant if airport is within (TFR radius + buffer)
    return $distanceNm <= ($tfrRadiusNm + TFR_RELEVANCE_BUFFER_NM);
}

/**
 * Determine NOTAM status (active, upcoming_today, expired, upcoming_future)
 * 
 * Uses airport's local timezone to determine "today" for proper classification
 * of upcoming NOTAMs. Without airport context, falls back to UTC.
 * 
 * @param array $notam Parsed NOTAM data with 'start_time_utc' and 'end_time_utc'
 * @param array|null $airport Airport config for timezone (optional, defaults to UTC)
 * @return string One of: 'active', 'upcoming_today', 'upcoming_future', 'expired', 'unknown'
 */
function determineNotamStatus(array $notam, ?array $airport = null): string {
    $now = time();
    
    // Parse start time - strtotime returns false on failure
    $startTimeRaw = !empty($notam['start_time_utc']) ? strtotime($notam['start_time_utc']) : false;
    if ($startTimeRaw === false || $startTimeRaw === 0) {
        return 'unknown';
    }
    $startTime = $startTimeRaw;
    
    // Parse end time (null for permanent NOTAMs)
    $endTimeRaw = !empty($notam['end_time_utc']) ? strtotime($notam['end_time_utc']) : false;
    $endTime = ($endTimeRaw !== false && $endTimeRaw > 0) ? $endTimeRaw : null;
    
    // Expired
    if ($endTime !== null && $now > $endTime) {
        return 'expired';
    }
    
    // Active
    if ($now >= $startTime) {
        return 'active';
    }
    
    // Determine "today" boundaries using airport timezone
    $timezone = $airport !== null ? getAirportTimezone($airport) : 'UTC';
    try {
        $tz = new DateTimeZone($timezone);
        $nowDt = new DateTime('now', $tz);
        $todayStart = (clone $nowDt)->setTime(0, 0, 0)->getTimestamp();
        $todayEnd = (clone $nowDt)->setTime(23, 59, 59)->getTimestamp();
    } catch (Exception $e) {
        // Fallback to server time if timezone is invalid
        $todayStart = strtotime('today');
        $todayEnd = strtotime('tomorrow') - 1;
    }
    
    // Upcoming today (starts later today in airport's local time)
    if ($startTime >= $todayStart && $startTime <= $todayEnd) {
        return 'upcoming_today';
    }
    
    return 'upcoming_future';
}

/**
 * Filter NOTAMs for relevant closures and TFRs
 * 
 * Returns only NOTAMs that are:
 * - Relevant to this airport (location match or geographic proximity)
 * - Currently active or starting later today (in airport's local timezone)
 * 
 * @param array $notams Array of parsed NOTAM data
 * @param array $airport Airport configuration with timezone, coordinates, and identifiers
 * @return array Filtered NOTAMs with 'notam_type' and 'status' fields added
 */
function filterRelevantNotams(array $notams, array $airport): array {
    $relevant = [];
    
    foreach ($notams as $notam) {
        $isClosureNotam = isAerodromeClosure($notam, $airport);
        $isTfrNotam = isTfr($notam);
        
        if ($isClosureNotam) {
            $status = determineNotamStatus($notam, $airport);
            if ($status === 'active' || $status === 'upcoming_today') {
                $notam['notam_type'] = 'aerodrome_closure';
                $notam['status'] = $status;
                $relevant[] = $notam;
            }
        } elseif ($isTfrNotam) {
            if (isTfrRelevantToAirport($notam, $airport)) {
                $status = determineNotamStatus($notam, $airport);
                if ($status === 'active' || $status === 'upcoming_today') {
                    $notam['notam_type'] = 'tfr';
                    $notam['status'] = $status;
                    $relevant[] = $notam;
                }
            }
        }
    }
    
    return $relevant;
}
