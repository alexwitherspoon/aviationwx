<?php
/**
 * Observation Time Tracking
 * 
 * Functions for populating _field_obs_time_map immediately after data fetch.
 * This ensures observation times are available for all calculations and validations.
 */

/**
 * Populate _field_obs_time_map for all fields from source-level observation times
 * 
 * This MUST be called immediately after fetching weather data, before any calculations
 * or merges. All downstream logic depends on knowing when each field was measured.
 * 
 * @param array &$weatherData Weather data array (modified in place)
 * @return void
 */
function populateFieldObservationTimes(array &$weatherData): void {
    if (!is_array($weatherData) || empty($weatherData)) {
        return;
    }
    
    // Initialize _field_obs_time_map if not present
    if (!isset($weatherData['_field_obs_time_map'])) {
        $weatherData['_field_obs_time_map'] = [];
    }
    
    // Get source-level observation times
    $primaryObsTime = $weatherData['obs_time_primary'] ?? $weatherData['last_updated_primary'] ?? null;
    $backupObsTime = $weatherData['obs_time_backup'] ?? $weatherData['last_updated_backup'] ?? null;
    $metarObsTime = $weatherData['obs_time_metar'] ?? $weatherData['last_updated_metar'] ?? null;
    
    // Primary source fields - use obs_time_primary
    $primarySourceFields = [
        'temperature', 'temperature_f',
        'dewpoint', 'dewpoint_f', 'dewpoint_spread', 'humidity',
        'wind_speed', 'wind_direction', 'gust_speed', 'gust_factor', 'peak_gust',
        'pressure', 'precip_accum',
        'pressure_altitude', 'density_altitude'
    ];
    
    // METAR source fields - use obs_time_metar
    $metarSourceFields = [
        'visibility', 'ceiling', 'cloud_cover'
    ];
    
    // Populate observation times for primary source fields
    if ($primaryObsTime !== null && $primaryObsTime > 0) {
        foreach ($primarySourceFields as $field) {
            // Only set if field exists and doesn't already have a per-field obs_time
            // (preserve existing per-field times if already set, e.g., from fetcher.php)
            if (isset($weatherData[$field]) && $weatherData[$field] !== null) {
                if (!isset($weatherData['_field_obs_time_map'][$field]) || $weatherData['_field_obs_time_map'][$field] === null) {
                    $weatherData['_field_obs_time_map'][$field] = $primaryObsTime;
                }
            }
        }
    }
    
    // Populate observation times for METAR fields
    if ($metarObsTime !== null && $metarObsTime > 0) {
        foreach ($metarSourceFields as $field) {
            // Only set if field exists and doesn't already have a per-field obs_time
            if (isset($weatherData[$field]) && $weatherData[$field] !== null) {
                if (!isset($weatherData['_field_obs_time_map'][$field]) || $weatherData['_field_obs_time_map'][$field] === null) {
                    $weatherData['_field_obs_time_map'][$field] = $metarObsTime;
                }
            }
        }
    }
    
    // Handle backup data fields if present
    if (isset($weatherData['_backup_data']) && is_array($weatherData['_backup_data'])) {
        $backupData = $weatherData['_backup_data'];
        
        // Backup fields use obs_time_backup
        if ($backupObsTime !== null && $backupObsTime > 0) {
            foreach ($primarySourceFields as $field) {
                // Only set if backup has this field and primary doesn't, or if we're tracking backup source
                if (isset($backupData[$field]) && $backupData[$field] !== null) {
                    // If primary doesn't have this field, or backup field is being used, track backup obs_time
                    // Note: The actual field selection happens in mergeWeatherDataWithFieldLevelFallback
                    // Here we just ensure backup fields have obs times tracked in backup data
                    if (!isset($backupData['_field_obs_time_map'])) {
                        $backupData['_field_obs_time_map'] = [];
                    }
                    if (!isset($backupData['_field_obs_time_map'][$field]) || $backupData['_field_obs_time_map'][$field] === null) {
                        $backupData['_field_obs_time_map'][$field] = $backupObsTime;
                    }
                }
            }
            // Update backup data with populated obs times
            $weatherData['_backup_data'] = $backupData;
        }
    }
}

