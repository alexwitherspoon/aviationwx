<?php
/**
 * Weather Cache Utilities
 * 
 * Functions for working with cached weather data, including staleness checks
 * for data that may have aged since it was cached.
 */

require_once __DIR__ . '/../constants.php';

/**
 * Null out stale weather fields in cached data
 * 
 * When serving cached data, fields may have become stale since caching.
 * This function nulls out fields that exceed staleness thresholds.
 * 
 * @param array &$data Weather data array (modified in place)
 * @param int $maxStaleSeconds Maximum age for primary source fields
 * @param int|null $maxStaleSecondsMetar Maximum age for METAR fields (defaults to $maxStaleSeconds)
 * @param bool $isMetarOnly True if this is a METAR-only source
 * @return void
 */
function nullStaleFieldsBySource(&$data, $maxStaleSeconds, $maxStaleSecondsMetar = null, $isMetarOnly = false) {
    if ($maxStaleSecondsMetar === null) {
        $maxStaleSecondsMetar = $maxStaleSeconds;
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
    $thresholdForPrimaryFields = $isMetarOnly ? $maxStaleSecondsMetar : $maxStaleSeconds;
    
    // Check primary source fields - use per-field obs times if available
    $primaryStale = false;
    $primarySourceAge = PHP_INT_MAX;
    if (isset($data['last_updated_primary']) && $data['last_updated_primary'] > 0) {
        $primarySourceAge = $now - $data['last_updated_primary'];
        $primaryStale = ($primarySourceAge >= $thresholdForPrimaryFields);
    } elseif (isset($data['last_updated_metar']) && $data['last_updated_metar'] > 0 && $isMetarOnly) {
        // For METAR-only sources, use METAR timestamp for primary fields
        $primarySourceAge = $now - $data['last_updated_metar'];
        $primaryStale = ($primarySourceAge >= $thresholdForPrimaryFields);
    }
    
    foreach ($primarySourceFields as $field) {
        if (!isset($data[$field]) || $data[$field] === null) {
            continue; // Skip null fields
        }
        
        // Check per-field observation time if available
        if (isset($fieldObsTimeMap[$field]) && $fieldObsTimeMap[$field] > 0) {
            $fieldAge = $now - $fieldObsTimeMap[$field];
            if ($fieldAge >= $thresholdForPrimaryFields) {
                // This specific field is stale - reject it
                $data[$field] = null;
            }
        } elseif ($primaryStale) {
            // No per-field obs time - fall back to source-level staleness check
            $data[$field] = null;
        }
    }
    
    // Check METAR source fields - use per-field obs times if available
    $metarStale = false;
    $metarSourceAge = PHP_INT_MAX;
    if (isset($data['last_updated_metar']) && $data['last_updated_metar'] > 0) {
        $metarSourceAge = $now - $data['last_updated_metar'];
        $metarStale = ($metarSourceAge >= $maxStaleSecondsMetar);
    }
    
    foreach ($metarSourceFields as $field) {
        if (!isset($data[$field]) || $data[$field] === null) {
            continue; // Skip null fields
        }
        
        // Check per-field observation time if available
        if (isset($fieldObsTimeMap[$field]) && $fieldObsTimeMap[$field] > 0) {
            $fieldAge = $now - $fieldObsTimeMap[$field];
            if ($fieldAge >= $maxStaleSecondsMetar) {
                // This specific field is stale - reject it
                $data[$field] = null;
            }
        } elseif ($metarStale) {
            // No per-field obs time - fall back to source-level staleness check
            $data[$field] = null;
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

