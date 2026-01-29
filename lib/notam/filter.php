<?php
/**
 * NOTAM Filter
 * 
 * Filters NOTAMs for aerodrome closures and TFRs relevant to an airport
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/../units.php';
require_once __DIR__ . '/../airport-identifiers.php';

/**
 * Parse coordinates from TFR NOTAM text
 * 
 * Supports formats:
 * - DDMMSSN/DDDMMSSW (e.g., 413900N1122300W)
 * - DDMMSSNDDDDMMSSW (e.g., 413900N1122300W without separator)
 * - DD-MM-SS N/S, DDD-MM-SS E/W variants
 * 
 * @param string $text NOTAM text
 * @return array|null ['lat' => float, 'lon' => float] or null if not found
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
 * 
 * Supports formats:
 * - XNM RADIUS (e.g., "5NM RADIUS")
 * - X NM RADIUS (e.g., "5 NM RADIUS")
 * - X NAUTICAL MILE RADIUS
 * - RADIUS OF XNM
 * 
 * @param string $text NOTAM text
 * @return float|null Radius in nautical miles or null if not found
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
 * @return array Array of identifier strings
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
 * Check if NOTAM is a runway or aerodrome closure/hazard
 * 
 * Only matches runway-level and above issues (not taxiway/apron closures):
 * - QMR* = Runway (closed, hazardous, etc.)
 * - QFA* = Aerodrome/airport (closed, services unavailable, etc.)
 * 
 * Excludes less critical closures:
 * - QMX* = Taxiway closures
 * - QMA* = Apron/ramp closures
 * - QMP* = Parking area closures
 * 
 * @param array $notam Parsed NOTAM data
 * @param array $airport Airport configuration
 * @return bool True if runway/aerodrome closure or hazard
 */
function isAerodromeClosure(array $notam, array $airport): bool {
    $code = strtoupper($notam['code'] ?? '');
    $text = strtoupper($notam['text'] ?? '');
    
    // Q-code filter - only runway (QMR) or aerodrome (QFA) level issues
    $isRunway = strpos($code, 'QMR') === 0;
    $isAerodrome = strpos($code, 'QFA') === 0;
    
    if (!$isRunway && !$isAerodrome) {
        return false;
    }
    
    // Text validation - must contain closure or hazard indicators
    $hasClosure = stripos($text, 'CLSD') !== false || stripos($text, 'CLOSED') !== false;
    $hasHazard = stripos($text, 'HAZARD') !== false || stripos($text, 'UNSAFE') !== false;
    
    if (!$hasClosure && !$hasHazard) {
        return false;
    }
    
    // Location match (current or historical identifiers)
    $identifiers = getAirportIdentifiers($airport);
    $notamLocation = strtoupper($notam['location'] ?? '');
    
    if (!in_array($notamLocation, $identifiers)) {
        // Also check airport name matching for geospatial queries
        $airportName = strtoupper($airport['name'] ?? '');
        $notamAirportName = strtoupper($notam['airport_name'] ?? '');
        
        if (empty($airportName) || empty($notamAirportName) || 
            stripos($notamAirportName, $airportName) === false) {
            return false;
        }
    }
    
    return true;
}

/**
 * Check if NOTAM is a TFR (Temporary Flight Restriction)
 * 
 * @param array $notam Parsed NOTAM data
 * @return bool True if TFR
 */
function isTfr(array $notam): bool {
    $text = strtoupper($notam['text'] ?? '');
    
    // Primary indicators
    if (stripos($text, 'TFR') !== false) {
        return true;
    }
    if (stripos($text, 'TEMPORARY FLIGHT RESTRICTION') !== false) {
        return true;
    }
    
    // Secondary indicators
    $hasRestricted = stripos($text, 'RESTRICTED') !== false;
    $hasAirspace = stripos($text, 'AIRSPACE') !== false;
    if ($hasRestricted && $hasAirspace) {
        return true;
    }
    
    return false;
}

/**
 * Check if TFR is relevant to airport based on geographic proximity
 * 
 * A TFR is relevant if:
 * 1. The NOTAM location field matches an airport identifier, OR
 * 2. The TFR text explicitly mentions the airport's name or identifier, OR
 * 3. The airport is within the TFR's radius + buffer distance
 * 
 * All distance calculations use nautical miles (standard aviation unit).
 * 
 * @param array $tfr Parsed TFR NOTAM data (with 'text', 'location', 'airport_name' fields)
 * @param array $airport Airport configuration (must have 'lat' and 'lon')
 * @return bool True if relevant
 */
function isTfrRelevantToAirport(array $tfr, array $airport): bool {
    $text = $tfr['text'] ?? '';
    $textUpper = strtoupper($text);
    $airportName = strtoupper($airport['name'] ?? '');
    $identifiers = getAirportIdentifiers($airport);
    
    // Check if NOTAM location field matches an airport identifier
    // This is reliable when the NOTAM was issued for a specific airport
    $notamLocation = strtoupper($tfr['location'] ?? '');
    if (!empty($notamLocation) && in_array($notamLocation, $identifiers)) {
        return true;
    }
    
    // Check if NOTAM airport_name matches
    $notamAirportName = strtoupper($tfr['airport_name'] ?? '');
    if (!empty($notamAirportName) && !empty($airportName)) {
        if (stripos($notamAirportName, $airportName) !== false ||
            stripos($airportName, $notamAirportName) !== false) {
            return true;
        }
    }
    
    // Check if TFR text mentions airport name
    if (!empty($airportName) && stripos($textUpper, $airportName) !== false) {
        return true;
    }
    
    // Check if TFR text mentions any airport identifier
    foreach ($identifiers as $identifier) {
        if (!empty($identifier) && stripos($textUpper, $identifier) !== false) {
            return true;
        }
    }
    
    // Geographic relevance check - parse TFR coordinates and check distance
    if (!isset($airport['lat']) || !isset($airport['lon'])) {
        // No airport coordinates available, cannot check distance
        // Be conservative and exclude TFR to avoid false positives
        return false;
    }
    
    $airportLat = (float)$airport['lat'];
    $airportLon = (float)$airport['lon'];
    
    // Parse TFR center coordinates from text
    $tfrCoords = parseTfrCoordinates($text);
    if ($tfrCoords === null) {
        // Cannot parse TFR coordinates - be conservative and exclude
        // This prevents showing distant TFRs when we can't verify location
        return false;
    }
    
    // Parse TFR radius in nautical miles (or use default)
    $tfrRadiusNm = parseTfrRadiusNm($text) ?? TFR_DEFAULT_RADIUS_NM;
    
    // Calculate distance from airport to TFR center in nautical miles
    $distanceNm = calculateDistanceNm(
        $airportLat,
        $airportLon,
        $tfrCoords['lat'],
        $tfrCoords['lon']
    );
    
    // TFR is relevant if airport is within (TFR radius + buffer)
    $relevanceThresholdNm = $tfrRadiusNm + TFR_RELEVANCE_BUFFER_NM;
    
    return $distanceNm <= $relevanceThresholdNm;
}

/**
 * Determine NOTAM status (active, upcoming_today, expired, upcoming_future)
 * 
 * @param array $notam Parsed NOTAM data
 * @return string Status string
 */
function determineNotamStatus(array $notam): string {
    $now = time();
    $startTime = !empty($notam['start_time_utc']) ? strtotime($notam['start_time_utc']) : 0;
    $endTime = !empty($notam['end_time_utc']) ? strtotime($notam['end_time_utc']) : null;
    
    if ($startTime === 0) {
        return 'unknown';
    }
    
    // Expired
    if ($endTime !== null && $now > $endTime) {
        return 'expired';
    }
    
    // Active
    if ($now >= $startTime) {
        return 'active';
    }
    
    // Upcoming today
    $todayStart = strtotime('today');
    $todayEnd = strtotime('tomorrow') - 1;
    if ($startTime >= $todayStart && $startTime <= $todayEnd) {
        return 'upcoming_today';
    }
    
    return 'upcoming_future';
}

/**
 * Filter NOTAMs for relevant closures and TFRs
 * 
 * @param array $notams Array of parsed NOTAM data
 * @param array $airport Airport configuration
 * @return array Filtered NOTAMs with status
 */
function filterRelevantNotams(array $notams, array $airport): array {
    $relevant = [];
    
    foreach ($notams as $notam) {
        $isClosure = isAerodromeClosure($notam, $airport);
        $isTfr = isTfr($notam);
        
        if ($isClosure) {
            $status = determineNotamStatus($notam);
            if ($status === 'active' || $status === 'upcoming_today') {
                $notam['notam_type'] = 'aerodrome_closure';
                $notam['status'] = $status;
                $relevant[] = $notam;
            }
        } elseif ($isTfr) {
            if (isTfrRelevantToAirport($notam, $airport)) {
                $status = determineNotamStatus($notam);
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
