<?php
/**
 * NOTAM Filter
 * 
 * Filters NOTAMs for aerodrome closures and TFRs relevant to an airport
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../airport-identifiers.php';

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
 * Check if NOTAM is an aerodrome closure
 * 
 * @param array $notam Parsed NOTAM data
 * @param array $airport Airport configuration
 * @return bool True if aerodrome closure
 */
function isAerodromeClosure(array $notam, array $airport): bool {
    $code = strtoupper($notam['code'] ?? '');
    $text = strtoupper($notam['text'] ?? '');
    
    // Q-code filter (primary) - must start with QM
    if (strpos($code, 'QM') !== 0) {
        return false;
    }
    
    // Text validation - must contain CLSD or CLOSED
    if (stripos($text, 'CLSD') === false && stripos($text, 'CLOSED') === false) {
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
 * Check if TFR is relevant to airport
 * 
 * @param array $tfr Parsed TFR NOTAM data
 * @param array $airport Airport configuration
 * @return bool True if relevant
 */
function isTfrRelevantToAirport(array $tfr, array $airport): bool {
    $text = strtoupper($tfr['text'] ?? '');
    $airportName = strtoupper($airport['name'] ?? '');
    $identifiers = getAirportIdentifiers($airport);
    
    // Check if TFR mentions airport name
    if (!empty($airportName) && stripos($text, $airportName) !== false) {
        return true;
    }
    
    // Check if TFR mentions any airport identifier
    foreach ($identifiers as $identifier) {
        if (!empty($identifier) && stripos($text, $identifier) !== false) {
            return true;
        }
    }
    
    // For geospatial queries, if we found it within radius, assume relevant
    // (This is a fallback - ideally we'd parse coordinates and check distance)
    return true;
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

