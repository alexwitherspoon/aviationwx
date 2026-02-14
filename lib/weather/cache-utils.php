<?php
/**
 * Weather Cache Utilities
 * 
 * Functions for working with cached weather data, including staleness checks
 * for data that may have aged since it was cached.
 * 
 * Uses 3-tier staleness model:
 *   - Warning: Data is old but still useful (user messaging)
 *   - Error: Data is questionable (stronger user messaging)
 *   - Failclosed: Data too old to display (hidden from user)
 */

require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/../config.php';

/**
 * Null out stale weather fields in cached data (failclosed tier)
 * 
 * When serving cached data, fields may have become stale since caching.
 * This function nulls out fields that exceed the FAILCLOSED threshold,
 * meaning they are too old to be shown to users.
 * 
 * @param array &$data Weather data array (modified in place)
 * @param int $failclosedSeconds Failclosed threshold for primary source fields
 * @param int|null $failclosedSecondsMetar Failclosed threshold for METAR fields (defaults to $failclosedSeconds)
 * @param bool $isMetarOnly True if this is a METAR-only source
 * @return void
 */
function nullStaleFieldsBySource(&$data, $failclosedSeconds, $failclosedSecondsMetar = null, $isMetarOnly = false) {
    if ($failclosedSecondsMetar === null) {
        $failclosedSecondsMetar = $failclosedSeconds;
    }
    
    $primarySourceFields = [
        'temperature', 'temperature_f',
        'dewpoint', 'dewpoint_f', 'dewpoint_spread', 'humidity',
        'wind_speed', 'wind_direction', 'gust_speed', 'gust_factor',
        'pressure', 'precip_accum',
        'pressure_altitude', 'density_altitude'
        // Note: temp_high_today, temp_low_today, peak_gust_today are preserved (daily tracking values)
    ];
    
    $metarSourceFields = [
        'visibility', 'ceiling', 'cloud_cover'
    ];
    
    $now = time();
    $fieldObsTimeMap = $data['_field_obs_time_map'] ?? [];
    
    // For METAR-only sources, ALL fields come from METAR, so use METAR threshold for all fields
    $thresholdForPrimaryFields = $isMetarOnly ? $failclosedSecondsMetar : $failclosedSeconds;
    
    // Check primary source fields - use per-field obs times if available
    $primaryFailclosed = false;
    $primarySourceAge = PHP_INT_MAX;
    if (isset($data['last_updated_primary']) && $data['last_updated_primary'] > 0) {
        $primarySourceAge = $now - $data['last_updated_primary'];
        $primaryFailclosed = ($primarySourceAge >= $thresholdForPrimaryFields);
    } elseif (isset($data['last_updated_metar']) && $data['last_updated_metar'] > 0 && $isMetarOnly) {
        // For METAR-only sources, use METAR timestamp for primary fields
        $primarySourceAge = $now - $data['last_updated_metar'];
        $primaryFailclosed = ($primarySourceAge >= $thresholdForPrimaryFields);
    }
    
    foreach ($primarySourceFields as $field) {
        if (!isset($data[$field]) || $data[$field] === null) {
            continue; // Skip null fields
        }
        
        // Check per-field observation time if available
        if (isset($fieldObsTimeMap[$field]) && $fieldObsTimeMap[$field] > 0) {
            $fieldAge = $now - $fieldObsTimeMap[$field];
            if ($fieldAge >= $thresholdForPrimaryFields) {
                // This specific field has reached failclosed tier - hide it
                $data[$field] = null;
            }
        } elseif ($primaryFailclosed) {
            // No per-field obs time - fall back to source-level failclosed check
            $data[$field] = null;
        }
    }
    
    // Check METAR source fields - use per-field obs times if available
    $metarFailclosed = false;
    $metarSourceAge = PHP_INT_MAX;
    if (isset($data['last_updated_metar']) && $data['last_updated_metar'] > 0) {
        $metarSourceAge = $now - $data['last_updated_metar'];
        $metarFailclosed = ($metarSourceAge >= $failclosedSecondsMetar);
    }
    
    foreach ($metarSourceFields as $field) {
        if (!isset($data[$field]) || $data[$field] === null) {
            continue; // Skip null fields
        }
        
        // Check per-field observation time if available
        if (isset($fieldObsTimeMap[$field]) && $fieldObsTimeMap[$field] > 0) {
            $fieldAge = $now - $fieldObsTimeMap[$field];
            if ($fieldAge >= $failclosedSecondsMetar) {
                // This specific field has reached failclosed tier - hide it
                $data[$field] = null;
                if ($field === 'visibility') {
                    $data['visibility_greater_than'] = false;
                }
            }
        } elseif ($metarFailclosed) {
            // No per-field obs time - fall back to source-level failclosed check
            $data[$field] = null;
            if ($field === 'visibility') {
                $data['visibility_greater_than'] = false;
            }
        }
    }
    
    // Recalculate flight category if visibility/ceiling were nulled
    if (!isset($data['visibility']) || $data['visibility'] === null) {
        // If visibility was nulled, recalculate flight category
        if (function_exists('calculateFlightCategory')) {
            $data['flight_category'] = calculateFlightCategory($data);
        }
    }
}

/**
 * Apply failclosed staleness check using config-based thresholds
 * 
 * Convenience function that gets thresholds from config and applies them.
 * 
 * @param array &$data Weather data array (modified in place)
 * @param array|null $airport Airport config for threshold override
 * @param bool $isMetarOnly True if this is a METAR-only source
 * @return void
 */
function applyFailclosedStaleness(&$data, ?array $airport = null, bool $isMetarOnly = false): void {
    $failclosedSeconds = getStaleFailclosedSeconds($airport);
    $failclosedSecondsMetar = getMetarStaleFailclosedSeconds();
    
    nullStaleFieldsBySource($data, $failclosedSeconds, $failclosedSecondsMetar, $isMetarOnly);
}

