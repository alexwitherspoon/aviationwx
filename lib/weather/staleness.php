<?php
/**
 * Weather Data Staleness Handling
 * 
 * Functions for handling stale weather data, merging with fallbacks, and nulling stale fields.
 * Critical for safety - ensures pilots never see stale data without clear indication.
 */

require_once __DIR__ . '/calculator.php';

/**
 * Merge new weather data with existing cache, preserving last known good values
 * 
 * Merges new weather data with existing cache, intelligently preserving values
 * from cache when new data is missing or invalid. Handles source-specific staleness
 * (primary source vs METAR) and preserves daily values (precip_accum) appropriately.
 * 
 * @param array $newData New weather data from API
 * @param array $existingData Existing cached weather data
 * @param int $maxStaleSeconds Maximum age in seconds for preserving cached values
 * @return array Merged weather data array
 */
function mergeWeatherDataWithFallback($newData, $existingData, $maxStaleSeconds) {
    if (!is_array($existingData) || !is_array($newData)) {
        return $newData;
    }
    
    // Fields that should be preserved from cache if new data is missing/invalid
    // Note: precip_accum is a daily value and should NOT be preserved from cache
    // (it should reset each day, so if missing from new data, it should be 0, not yesterday's value)
    $preservableFields = [
        'temperature', 'temperature_f',
        'dewpoint', 'dewpoint_f', 'dewpoint_spread', 'humidity',
        'wind_speed', 'wind_direction', 'gust_speed', 'gust_factor',
        'pressure',
        'pressure_altitude', 'density_altitude',
        'visibility', 'ceiling', 'cloud_cover'
    ];
    
    // Track which source each field comes from for staleness checking
    // Note: precip_accum is a daily value and should NOT be preserved from cache
    $primarySourceFields = [
        'temperature', 'temperature_f',
        'dewpoint', 'dewpoint_f', 'dewpoint_spread', 'humidity',
        'wind_speed', 'wind_direction', 'gust_speed', 'gust_factor',
        'pressure', 'precip_accum',
        'pressure_altitude', 'density_altitude'
    ];
    
    $metarSourceFields = [
        'visibility', 'ceiling', 'cloud_cover'
    ];
    
    $result = $newData;
    
    // For each field, check if we should preserve the old value
    foreach ($preservableFields as $field) {
        $newValue = $newData[$field] ?? null;
        $oldValue = $existingData[$field] ?? null;
        
        // If new value is missing/null, check if we can use old value
        if ($newValue === null && $oldValue !== null) {
            // Determine which source this field comes from
            $isPrimaryField = in_array($field, $primarySourceFields);
            $isMetarField = in_array($field, $metarSourceFields);
            
            // Check if old value is still fresh enough to use
            $isStale = false;
            if ($isPrimaryField && isset($existingData['last_updated_primary'])) {
                $age = time() - $existingData['last_updated_primary'];
                $isStale = ($age >= $maxStaleSeconds);
            } elseif ($isMetarField && isset($existingData['last_updated_metar'])) {
                $age = time() - $existingData['last_updated_metar'];
                $isStale = ($age >= $maxStaleSeconds);
            }
            
            // For METAR fields: If METAR was successfully fetched (last_updated_metar is set),
            // we need to distinguish between:
            // 1. Field is explicitly set to null in newData (unlimited/missing) - always overwrite
            // 2. Field is missing from newData (not in array) - preserve non-stale old values
            if ($isMetarField && isset($newData['last_updated_metar']) && $newData['last_updated_metar'] > 0) {
                // METAR was successfully fetched
                // Check if field is explicitly set to null (array_key_exists) vs missing (not in array)
                if (array_key_exists($field, $newData) && $newData[$field] === null) {
                    // Explicitly null from METAR means unlimited/missing - always overwrite
                    $result[$field] = null;
                } else {
                    // Field is missing from newData (not in array) - preserve non-stale old value
                    if (!$isStale) {
                        $result[$field] = $oldValue;
                    }
                }
                continue;
            }
            
            // Preserve old value if it's not too stale
            if (!$isStale) {
                $result[$field] = $oldValue;
            }
        }
    }
    
    // Handle precip_accum specially - it's a daily value that should reset each day
    // If missing from new data, set to 0 (no precipitation today) rather than preserving yesterday's value
    if (!isset($newData['precip_accum']) || $newData['precip_accum'] === null) {
        $result['precip_accum'] = 0.0;
    }
    
    // Preserve daily tracking values (always valid)
    $dailyTrackingFields = [
        'temp_high_today', 'temp_low_today', 'peak_gust_today',
        'temp_high_ts', 'temp_low_ts', 'peak_gust_time'
    ];
    foreach ($dailyTrackingFields as $field) {
        if (isset($existingData[$field]) && !isset($result[$field])) {
            $result[$field] = $existingData[$field];
        }
    }
    
    return $result;
}

/**
 * Helper function to null out stale fields based on source timestamps
 * 
 * Nulls out weather fields that are too stale based on their source timestamps.
 * Fields from primary source are checked against last_updated_primary.
 * Fields from METAR are checked against last_updated_metar.
 * Daily tracking values (temp_high_today, temp_low_today, peak_gust_today) are NOT
 * considered stale - they represent valid historical data for the day.
 * 
 * @param array &$data Weather data array (modified in place)
 * @param int $maxStaleSeconds Maximum age in seconds before field is considered stale
 * @return void
 */
function nullStaleFieldsBySource(&$data, $maxStaleSeconds) {
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
    
    $primaryStale = false;
    if (isset($data['last_updated_primary']) && $data['last_updated_primary'] > 0) {
        $primaryAge = time() - $data['last_updated_primary'];
        $primaryStale = ($primaryAge >= $maxStaleSeconds); // >= means at threshold is stale
        
        if ($primaryStale) {
            foreach ($primarySourceFields as $field) {
                if (isset($data[$field])) {
                    $data[$field] = null;
                }
            }
        }
    }
    
    $metarStale = false;
    if (isset($data['last_updated_metar']) && $data['last_updated_metar'] > 0) {
        $metarAge = time() - $data['last_updated_metar'];
        $metarStale = ($metarAge >= $maxStaleSeconds); // >= means at threshold is stale
        
        if ($metarStale) {
            foreach ($metarSourceFields as $field) {
                if (isset($data[$field])) {
                    $data[$field] = null;
                }
            }
        }
    }
    
    // Recalculate flight category if METAR data is stale or visibility/ceiling are missing
    // Note: If METAR is stale, visibility and ceiling are nulled, but we might still have
    // valid ceiling from primary source or other data that allows category calculation
    if ($metarStale || (($data['visibility'] ?? null) === null && ($data['ceiling'] ?? null) === null)) {
        calculateAndSetFlightCategory($data);
    }
}

