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
 * @param int $maxStaleSeconds Maximum age in seconds for preserving cached primary source values
 * @param int|null $maxStaleSecondsMetar Maximum age in seconds for preserving cached METAR values (defaults to $maxStaleSeconds if null)
 * @return array Merged weather data array
 */
function mergeWeatherDataWithFallback($newData, $existingData, $maxStaleSeconds, $maxStaleSecondsMetar = null) {
    if ($maxStaleSecondsMetar === null) {
        $maxStaleSecondsMetar = $maxStaleSeconds;
    }
    if (!is_array($existingData) || !is_array($newData)) {
        return $newData;
    }
    
    // Fields that can be preserved from cache if new data is missing/invalid
    // Note: precip_accum is excluded (daily value, resets each day)
    $preservableFields = [
        'temperature', 'temperature_f',
        'dewpoint', 'dewpoint_f', 'dewpoint_spread', 'humidity',
        'wind_speed', 'wind_direction', 'gust_speed', 'gust_factor',
        'pressure',
        'pressure_altitude', 'density_altitude',
        'visibility', 'ceiling', 'cloud_cover'
    ];
    
    // Track which source each field comes from for staleness checking
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
                $isStale = ($age >= $maxStaleSecondsMetar);
            }
            
            // METAR fields: distinguish between explicit null (unlimited/missing) vs missing from array
            if ($isMetarField && isset($newData['last_updated_metar']) && $newData['last_updated_metar'] > 0) {
                if (array_key_exists($field, $newData) && $newData[$field] === null) {
                    // Explicit null means unlimited/missing - always overwrite
                    $result[$field] = null;
                } elseif (!$isStale) {
                    // Missing from array - preserve non-stale old value
                    $result[$field] = $oldValue;
                }
                continue;
            }
            
            // Preserve old value if it's not too stale
            if (!$isStale) {
                $result[$field] = $oldValue;
            }
        }
    }
    
    // precip_accum is a daily value - reset to 0 if missing (don't preserve yesterday's value)
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
 * @param int $maxStaleSeconds Maximum age in seconds before primary source field is considered stale
 * @param int|null $maxStaleSecondsMetar Maximum age in seconds before METAR field is considered stale (defaults to $maxStaleSeconds if null)
 * @return void
 */
function nullStaleFieldsBySource(&$data, $maxStaleSeconds, $maxStaleSecondsMetar = null) {
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
    
    $primaryStale = false;
    if (isset($data['last_updated_primary']) && $data['last_updated_primary'] > 0) {
        $primaryAge = time() - $data['last_updated_primary'];
        $primaryStale = ($primaryAge >= $maxStaleSeconds);
        
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
        $metarStale = ($metarAge >= $maxStaleSecondsMetar);
        
        if ($metarStale) {
            foreach ($metarSourceFields as $field) {
                if (isset($data[$field])) {
                    $data[$field] = null;
                }
            }
        }
    }
    
    // Recalculate flight category if METAR data is stale or visibility/ceiling are missing
    if ($metarStale || (($data['visibility'] ?? null) === null && ($data['ceiling'] ?? null) === null)) {
        calculateAndSetFlightCategory($data);
    }
}

/**
 * Calculate staleness thresholds for weather data
 * 
 * Returns warning and error thresholds in seconds based on whether the source is METAR-only
 * or a primary source (Tempest, Ambient, WeatherLink, etc.).
 * 
 * For METAR-only sources: Uses hour-based thresholds (METARs are published hourly)
 * For primary sources: Uses multiplier-based thresholds (similar to webcams)
 * 
 * @param bool $isMetarOnly True if weather source is METAR-only, false for primary sources
 * @param int $refreshIntervalSeconds Refresh interval in seconds (defaults to 60 if not provided)
 * @return array Array with 'warning' and 'error' keys, both in seconds
 */
function calculateWeatherStalenessThresholds($isMetarOnly, $refreshIntervalSeconds = 60) {
    require_once __DIR__ . '/../constants.php';
    
    // Ensure minimum refresh interval to prevent invalid thresholds
    $refreshIntervalSeconds = max(1, $refreshIntervalSeconds);
    
    if ($isMetarOnly) {
        // METAR thresholds: warning at 1 hour, error at 2 hours
        return [
            'warning' => WEATHER_STALENESS_WARNING_HOURS_METAR * 3600,
            'error' => WEATHER_STALENESS_ERROR_HOURS_METAR * 3600
        ];
    } else {
        // Primary source thresholds: use multiplier-based approach (like webcams)
        // Warning at 5x refresh interval, error at 10x refresh interval
        return [
            'warning' => $refreshIntervalSeconds * WEATHER_STALENESS_WARNING_MULTIPLIER,
            'error' => $refreshIntervalSeconds * WEATHER_STALENESS_ERROR_MULTIPLIER
        ];
    }
}

