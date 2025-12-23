<?php
/**
 * Weather Data Staleness Handling (LEGACY)
 * 
 * @deprecated The new WeatherAggregator handles staleness during aggregation.
 *             This file is kept for backward compatibility with the legacy fetcher.
 * 
 * Functions for handling stale weather data, merging with fallbacks, and nulling stale fields.
 * Critical for safety - ensures pilots never see stale data without clear indication.
 */

require_once __DIR__ . '/calculator.php';
require_once __DIR__ . '/validation.php';

/**
 * Check if data has a complete wind set
 * 
 * Complete wind set requires minimum: wind_speed + wind_direction
 * gust_speed is optional but nice to have
 * 
 * @param array $data Weather data array
 * @return bool True if has complete wind set
 */
function hasCompleteWindSet(array $data): bool {
    $hasWindSpeed = isset($data['wind_speed']) && $data['wind_speed'] !== null;
    $hasWindDirection = isset($data['wind_direction']) && $data['wind_direction'] !== null;
    return $hasWindSpeed && $hasWindDirection;
}

/**
 * Try to merge wind data from multiple sources within tolerance
 * 
 * All merged wind fields must be within the tolerance window (10 seconds).
 * Returns merged wind data if successful, null if unable to merge.
 * 
 * @param array $primaryData Primary weather data
 * @param array $backupData Backup weather data
 * @param int $toleranceSeconds Maximum time difference allowed (default: 10 seconds)
 * @return array|null Merged wind data with 'fields', 'obs_time', 'source_map', or null if unable to merge
 */
function tryMergeWindWithinTolerance(array $primaryData, array $backupData, int $toleranceSeconds = 10): ?array {
    require_once __DIR__ . '/../constants.php';
    
    $windFields = ['wind_speed', 'wind_direction', 'gust_speed'];
    $primaryObsTime = $primaryData['obs_time_primary'] ?? $primaryData['last_updated_primary'] ?? null;
    $backupObsTime = $primaryData['obs_time_backup'] ?? $primaryData['last_updated_backup'] ?? null;
    
    // Collect all available wind fields with their observation times
    $availableFields = [];
    foreach ($windFields as $field) {
        $primaryValue = $primaryData[$field] ?? null;
        $backupValue = $backupData[$field] ?? null;
        
        if ($primaryValue !== null && $primaryObsTime !== null) {
            $availableFields[$field][] = [
                'value' => $primaryValue,
                'obs_time' => $primaryObsTime,
                'source' => 'primary'
            ];
        }
        if ($backupValue !== null && $backupObsTime !== null) {
            $availableFields[$field][] = [
                'value' => $backupValue,
                'obs_time' => $backupObsTime,
                'source' => 'backup'
            ];
        }
    }
    
    // Need at least wind_speed + wind_direction
    if (empty($availableFields['wind_speed']) || empty($availableFields['wind_direction'])) {
        return null; // Cannot merge without minimum required fields
    }
    
    // Try to find a combination where all fields are within tolerance
    // Strategy: For each field, pick the value with the newest obs_time, then check if all are within tolerance
    
    // First, collect all unique observation times
    $allObsTimes = [];
    foreach ($availableFields as $field => $options) {
        foreach ($options as $option) {
            $allObsTimes[] = $option['obs_time'];
        }
    }
    $allObsTimes = array_unique($allObsTimes);
    sort($allObsTimes); // Sort ascending
    
    // Try to find a time window where all fields can be merged
    // For each possible "anchor" time, check if all fields have values within tolerance
    foreach ($allObsTimes as $anchorTime) {
        $mergedFields = [];
        $mergedSourceMap = [];
        $allWithinTolerance = true;
        $newestTime = $anchorTime;
        
        foreach ($windFields as $field) {
            if (empty($availableFields[$field])) {
                // Field not available - skip (gust_speed is optional)
                continue;
            }
            
            // Find the best option for this field (prefer closest to anchor time, within tolerance)
            $bestOption = null;
            $bestTimeDiff = null;
            
            foreach ($availableFields[$field] as $option) {
                $timeDiff = abs($option['obs_time'] - $anchorTime);
                if ($timeDiff <= $toleranceSeconds) {
                    if ($bestOption === null || $timeDiff < $bestTimeDiff) {
                        $bestOption = $option;
                        $bestTimeDiff = $timeDiff;
                    }
                }
            }
            
            if ($bestOption === null) {
                // This field has no value within tolerance of anchor time
                // For required fields (wind_speed, wind_direction), this is a failure
                if ($field === 'wind_speed' || $field === 'wind_direction') {
                    $allWithinTolerance = false;
                    break;
                }
                // For optional fields (gust_speed), we can skip
                continue;
            }
            
            $mergedFields[$field] = $bestOption['value'];
            $mergedSourceMap[$field] = $bestOption['source'];
            if ($bestOption['obs_time'] > $newestTime) {
                $newestTime = $bestOption['obs_time'];
            }
        }
        
        // Check if we have minimum required fields (wind_speed + wind_direction)
        if ($allWithinTolerance && isset($mergedFields['wind_speed']) && isset($mergedFields['wind_direction'])) {
            // Verify all merged fields are within tolerance of each other
            $mergedObsTimes = [];
            foreach ($windFields as $field) {
                if (isset($mergedFields[$field])) {
                    foreach ($availableFields[$field] as $option) {
                        if ($option['value'] === $mergedFields[$field] && $option['source'] === $mergedSourceMap[$field]) {
                            $mergedObsTimes[] = $option['obs_time'];
                            break;
                        }
                    }
                }
            }
            
            // Check if all observation times are within tolerance of each other
            if (!empty($mergedObsTimes)) {
                $minTime = min($mergedObsTimes);
                $maxTime = max($mergedObsTimes);
                if (($maxTime - $minTime) <= $toleranceSeconds) {
                    return [
                        'fields' => $mergedFields,
                        'obs_time' => $newestTime, // Use newest observation time
                        'source_map' => $mergedSourceMap
                    ];
                }
            }
        }
    }
    
    // Unable to merge within tolerance
    return null;
}

/**
 * Validate wind field group
 * 
 * Wind fields must be from the exact same observation time.
 * Can combine from multiple sources if obs_time matches exactly.
 * 
 * @param array $data Weather data array
 * @param string $sourceType Source type ('primary' or 'backup')
 * @return array {
 *   'valid' => bool,
 *   'reason' => string|null,
 *   'obs_time' => int|null  // Common observation time if valid
 * }
 */
function validateWindGroup(array $data, string $sourceType): array {
    $obsTimeKey = $sourceType === 'primary' ? 'obs_time_primary' : 'obs_time_backup';
    $obsTime = $data[$obsTimeKey] ?? null;
    
    $windFields = ['wind_speed', 'wind_direction', 'gust_speed'];
    $presentFields = [];
    $fieldObsTimes = [];
    
    // Check which wind fields are present and their observation times
    foreach ($windFields as $field) {
        if (isset($data[$field]) && $data[$field] !== null) {
            $presentFields[] = $field;
            // For wind group, all fields must have same obs_time
            // Use the source's obs_time (wind fields come from same source observation)
            $fieldObsTimes[$field] = $obsTime;
        }
    }
    
    // If no wind fields present, group is valid (empty group)
    if (empty($presentFields)) {
        return ['valid' => true, 'reason' => 'no_wind_fields', 'obs_time' => null];
    }
    
    // Check if all present fields have same observation time
    $uniqueObsTimes = array_unique(array_filter($fieldObsTimes));
    if (count($uniqueObsTimes) === 1) {
        return ['valid' => true, 'reason' => 'wind_group_valid', 'obs_time' => reset($uniqueObsTimes)];
    }
    
    // Different observation times - invalid
    return ['valid' => false, 'reason' => 'wind_group_mismatched_obs_times', 'obs_time' => null];
}

/**
 * Validate temperature field group
 * 
 * Temperature fields must be within 1 minute of each other.
 * Can come from different sources if times are within tolerance.
 * 
 * @param array $primaryData Primary weather data
 * @param array|null $backupData Backup weather data
 * @return array {
 *   'valid' => bool,
 *   'reason' => string|null,
 *   'fields' => array  // Valid fields with their obs_times
 * }
 */
function validateTemperatureGroup(array $primaryData, ?array $backupData): array {
    $tempFields = ['temperature', 'dewpoint', 'humidity'];
    $validFields = [];
    $fieldObsTimes = [];
    
    // Get per-field observation times from _field_obs_time_map (more accurate than source-level)
    $primaryFieldObsTimeMap = $primaryData['_field_obs_time_map'] ?? [];
    $backupFieldObsTimeMap = $backupData['_field_obs_time_map'] ?? [];
    
    // Collect all present fields from both sources
    foreach ($tempFields as $field) {
        $primaryValue = $primaryData[$field] ?? null;
        $backupValue = $backupData[$field] ?? null;
        
        if ($primaryValue !== null) {
            // Prefer per-field observation time, fallback to source-level
            $obsTime = $primaryFieldObsTimeMap[$field] 
                ?? $primaryData['obs_time_primary'] 
                ?? $primaryData['last_updated_primary'] 
                ?? null;
            $validFields[$field] = ['value' => $primaryValue, 'source' => 'primary', 'obs_time' => $obsTime];
            if ($obsTime !== null) {
                $fieldObsTimes[$field] = $obsTime;
            }
        } elseif ($backupValue !== null) {
            // Prefer per-field observation time, fallback to source-level
            $obsTime = $backupFieldObsTimeMap[$field] 
                ?? $primaryData['obs_time_backup'] 
                ?? $primaryData['last_updated_backup'] 
                ?? null;
            $validFields[$field] = ['value' => $backupValue, 'source' => 'backup', 'obs_time' => $obsTime];
            if ($obsTime !== null) {
                $fieldObsTimes[$field] = $obsTime;
            }
        }
    }
    
    // If no fields present, group is valid (empty group)
    if (empty($validFields)) {
        return ['valid' => true, 'reason' => 'no_temperature_fields', 'fields' => []];
    }
    
    // Check if all fields are within 1 minute of each other
    $obsTimes = array_filter($fieldObsTimes);
    if (empty($obsTimes)) {
        // No observation times - can't validate, assume valid
        return ['valid' => true, 'reason' => 'no_obs_times', 'fields' => $validFields];
    }
    
    // If only one field present, allow it (no group conflict)
    if (count($obsTimes) === 1) {
        return ['valid' => true, 'reason' => 'single_field_no_conflict', 'fields' => $validFields];
    }
    
    $minTime = min($obsTimes);
    $maxTime = max($obsTimes);
    $timeDiff = $maxTime - $minTime;
    
    if ($timeDiff <= 60) {
        // Within 1 minute - valid
        return ['valid' => true, 'reason' => 'temperature_group_valid', 'fields' => $validFields];
    }
    
    // Over 1 minute - invalid for group, but allow individual fields that are valid
    // Return fields that can be used individually (those with valid obs_times)
    // This allows temperature to display even if dewpoint is from different time
    $validIndividualFields = [];
    foreach ($validFields as $field => $fieldData) {
        if (isset($fieldObsTimes[$field])) {
            $validIndividualFields[$field] = $fieldData;
        }
    }
    
    // If we have at least one valid individual field, allow it
    if (!empty($validIndividualFields)) {
        return ['valid' => true, 'reason' => 'individual_fields_valid', 'fields' => $validIndividualFields];
    }
    
    // No valid fields at all
    return ['valid' => false, 'reason' => 'temperature_group_over_1_minute', 'fields' => []];
}

/**
 * Validate pressure field group
 * 
 * Pressure fields must be within 1 minute of each other.
 * Can come from different sources if times are within tolerance.
 * 
 * @param array $primaryData Primary weather data
 * @param array|null $backupData Backup weather data
 * @return array {
 *   'valid' => bool,
 *   'reason' => string|null,
 *   'fields' => array  // Valid fields with their obs_times
 * }
 */
function validatePressureGroup(array $primaryData, ?array $backupData): array {
    $pressureFields = ['pressure', 'altimeter', 'sea_level_pressure'];
    $validFields = [];
    $fieldObsTimes = [];
    
    // Collect all present fields from both sources
    foreach ($pressureFields as $field) {
        $primaryValue = $primaryData[$field] ?? null;
        $backupValue = $backupData[$field] ?? null;
        
        if ($primaryValue !== null) {
            $obsTime = $primaryData['obs_time_primary'] ?? $primaryData['last_updated_primary'] ?? null;
            $validFields[$field] = ['value' => $primaryValue, 'source' => 'primary', 'obs_time' => $obsTime];
            if ($obsTime !== null) {
                $fieldObsTimes[$field] = $obsTime;
            }
        } elseif ($backupValue !== null) {
            $obsTime = $primaryData['obs_time_backup'] ?? $primaryData['last_updated_backup'] ?? null;
            $validFields[$field] = ['value' => $backupValue, 'source' => 'backup', 'obs_time' => $obsTime];
            if ($obsTime !== null) {
                $fieldObsTimes[$field] = $obsTime;
            }
        }
    }
    
    // If no fields present, group is valid (empty group)
    if (empty($validFields)) {
        return ['valid' => true, 'reason' => 'no_pressure_fields', 'fields' => []];
    }
    
    // Check if all fields are within 1 minute of each other
    $obsTimes = array_filter($fieldObsTimes);
    if (empty($obsTimes)) {
        // No observation times - can't validate, assume valid
        return ['valid' => true, 'reason' => 'no_obs_times', 'fields' => $validFields];
    }
    
    $minTime = min($obsTimes);
    $maxTime = max($obsTimes);
    $timeDiff = $maxTime - $minTime;
    
    if ($timeDiff <= 60) {
        // Within 1 minute - valid
        return ['valid' => true, 'reason' => 'pressure_group_valid', 'fields' => $validFields];
    }
    
    // Over 1 minute - invalid, reject mismatched fields
    return ['valid' => false, 'reason' => 'pressure_group_over_1_minute', 'fields' => []];
}

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
    
    // Preserve field observation time map from new data, merge with old if needed
    // CRITICAL: Start with new data's map to preserve METAR field tracking from fetcher.php
    $resultFieldObsTimeMap = $newData['_field_obs_time_map'] ?? [];
    $oldFieldObsTimeMap = $existingData['_field_obs_time_map'] ?? [];
    
    // Merge old map into result to preserve any entries not in new data
    // This ensures we don't lose old observation times when merging with cache
    foreach ($oldFieldObsTimeMap as $field => $obsTime) {
        // Only preserve old entry if new data doesn't have it (new data takes precedence)
        if (!isset($resultFieldObsTimeMap[$field]) && $obsTime > 0) {
            $resultFieldObsTimeMap[$field] = $obsTime;
        }
    }
    
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
            // Prefer per-field observation time if available, otherwise use source-level timestamp
            $isStale = false;
            $now = time();
            
            // Check per-field observation time first (more granular)
            if (isset($oldFieldObsTimeMap[$field]) && $oldFieldObsTimeMap[$field] > 0) {
                $fieldAge = $now - $oldFieldObsTimeMap[$field];
                $staleThreshold = $isMetarField ? $maxStaleSecondsMetar : $maxStaleSeconds;
                $isStale = ($fieldAge >= $staleThreshold);
            } elseif ($isPrimaryField && isset($existingData['last_updated_primary'])) {
                // Fall back to source-level timestamp
                $age = $now - $existingData['last_updated_primary'];
                $isStale = ($age >= $maxStaleSeconds);
            } elseif ($isMetarField && isset($existingData['last_updated_metar'])) {
                // Fall back to source-level timestamp
                $age = $now - $existingData['last_updated_metar'];
                $isStale = ($age >= $maxStaleSecondsMetar);
            }
            
            // CRITICAL: If new data has fresh timestamps, null means the field was explicitly cleared/rejected
            // However, we should still preserve non-stale old values to avoid data loss
            // Only clear old value if it's stale OR if new fetch happened and old value is stale
            $newFetchHappened = false;
            if ($isPrimaryField && isset($newData['last_updated_primary']) && $newData['last_updated_primary'] > 0) {
                // New primary data was fetched - check if it's fresh (within last hour)
                $newFetchAge = $now - $newData['last_updated_primary'];
                $newFetchHappened = ($newFetchAge < 3600); // Fresh if within last hour
            } elseif ($isMetarField && isset($newData['last_updated_metar']) && $newData['last_updated_metar'] > 0) {
                // New METAR data was fetched - check if it's fresh
                $newFetchAge = $now - $newData['last_updated_metar'];
                $newFetchHappened = ($newFetchAge < 3600); // Fresh if within last hour
            }
            
            // If new fetch happened and field is explicitly null, only clear if old value is stale
            // If old value is not stale, preserve it (field might just be missing from API response)
            if ($newFetchHappened && array_key_exists($field, $newData) && $newData[$field] === null) {
                // Explicit null in fresh data - clear old value only if it's stale
                // If old value is not stale, preserve it to avoid data loss
                if ($isStale) {
                    $result[$field] = null;
                    continue;
                }
                // If not stale, fall through to preservation logic below
            }
            
            // METAR fields: distinguish between explicit null (unlimited/missing) vs missing from array
            if ($isMetarField && isset($newData['last_updated_metar']) && $newData['last_updated_metar'] > 0) {
                if (array_key_exists($field, $newData) && $newData[$field] === null) {
                    // Explicit null means unlimited/missing - always overwrite
                    $result[$field] = null;
                } elseif (!$isStale) {
                    // Missing from array - preserve non-stale old value
                    $result[$field] = $oldValue;
                    // Preserve old field observation time if available
                    if (isset($oldFieldObsTimeMap[$field]) && $oldFieldObsTimeMap[$field] > 0) {
                        $resultFieldObsTimeMap[$field] = $oldFieldObsTimeMap[$field];
                    }
                }
                continue;
            }
            
            // Preserve old value if it's not too stale
            // For primary fields: preserve if not stale, regardless of whether new fetch happened
            // This ensures we don't lose valid data when API response is missing a field
            if (!$isStale) {
                $result[$field] = $oldValue;
                // Preserve old field observation time if available
                if (isset($oldFieldObsTimeMap[$field]) && $oldFieldObsTimeMap[$field] > 0) {
                    $resultFieldObsTimeMap[$field] = $oldFieldObsTimeMap[$field];
                }
            }
        }
    }
    
    // Store merged field observation time map (internal only)
    // Always set it (even if empty) to ensure it's present in the result
    // This is critical for frontend validation - empty map is better than missing map
    // METAR field entries from fetcher.php must be preserved through the merge
    $result['_field_obs_time_map'] = $resultFieldObsTimeMap;
    
    // Preserve _field_source_map from new data (don't overwrite with old cache)
    // This ensures we know which source each field comes from
    if (isset($newData['_field_source_map']) && is_array($newData['_field_source_map'])) {
        $result['_field_source_map'] = $newData['_field_source_map'];
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
 * Merge weather data with field-level fallback from backup source
 * 
 * Merges primary and backup weather data on a field-by-field basis, selecting the best
 * available data for each field. Validates data against climate bounds and prefers
 * newest observation time when both sources have valid data.
 * 
 * @param array $primaryData Primary weather data from API (contains backup timestamps if backup exists)
 * @param array|null $backupData Backup weather data from API (optional)
 * @param array|null $existingCache Existing cached weather data (optional)
 * @param int $refreshIntervalSeconds Weather refresh interval in seconds
 * @return array Merged weather data array with field_source_map tracking
 */
function mergeWeatherDataWithFieldLevelFallback(array $primaryData, ?array $backupData, ?array $existingCache, int $refreshIntervalSeconds): array {
    require_once __DIR__ . '/../constants.php';
    
    $result = $primaryData;
    $fieldSourceMap = [];
    // Preserve existing _field_obs_time_map entries (especially METAR fields tracked in fetcher.php)
    $fieldObsTimeMap = $primaryData['_field_obs_time_map'] ?? []; // Track observation time for each field individually
    $staleThresholdSeconds = $refreshIntervalSeconds * WEATHER_STALENESS_WARNING_MULTIPLIER;
    $now = time();
    
    // Primary source fields that can use backup fallback
    $primarySourceFields = [
        'temperature', 'temperature_f',
        'dewpoint', 'dewpoint_f', 'dewpoint_spread', 'humidity',
        'wind_speed', 'wind_direction', 'gust_speed', 'gust_factor',
        'pressure', 'precip_accum',
        'pressure_altitude', 'density_altitude'
    ];// If no backup data, return primary data as-is
    if ($backupData === null || !is_array($backupData)) {// Still track source and observation time for all fields
        $primaryObsTime = $primaryData['obs_time_primary'] ?? $primaryData['last_updated_primary'] ?? null;
        foreach ($primarySourceFields as $field) {
            if (isset($result[$field]) && $result[$field] !== null) {
                $fieldSourceMap[$field] = 'primary';
                if ($primaryObsTime !== null) {
                    $fieldObsTimeMap[$field] = $primaryObsTime;
                }
            }
        }
        $result['_field_source_map'] = $fieldSourceMap;
        if (!empty($fieldObsTimeMap)) {
            $result['_field_obs_time_map'] = $fieldObsTimeMap;
        }
        return $result;
    }
    
    // Check staleness for primary and backup (timestamps are in primaryData)
    $primaryAge = isset($primaryData['last_updated_primary']) && $primaryData['last_updated_primary'] > 0
        ? $now - $primaryData['last_updated_primary']
        : PHP_INT_MAX;
    $primaryStale = $primaryAge >= $staleThresholdSeconds;
    
    $backupAge = isset($primaryData['last_updated_backup']) && $primaryData['last_updated_backup'] > 0
        ? $now - $primaryData['last_updated_backup']
        : PHP_INT_MAX;
    $backupStale = $backupAge >= $staleThresholdSeconds;
    
    // Get observation times for comparison (from primaryData where backup timestamps are stored)
    $primaryObsTime = $primaryData['obs_time_primary'] ?? $primaryData['last_updated_primary'] ?? 0;
    $backupObsTime = $primaryData['obs_time_backup'] ?? $primaryData['last_updated_backup'] ?? 0;
    
    // Get quality metadata from each source
    $primaryQuality = $primaryData['_quality_metadata'] ?? [];
    $backupQuality = $backupData['_quality_metadata'] ?? [];
    
    // Validate field groups first
    $tempGroup = validateTemperatureGroup($primaryData, $backupData);
    $pressureGroup = validatePressureGroup($primaryData, $backupData);
    
    // Wind group validation using three-tier approach:
    // 1. First: Find newest complete wind set from single source
    // 2. Second: Try to merge wind data within 10 seconds tolerance
    // 3. Third: Reject if unable
    
    require_once __DIR__ . '/../constants.php';
    $windToleranceSeconds = WIND_GROUP_MERGE_TOLERANCE_SECONDS;
    
    $windGroupValid = false;
    $windGroupObsTime = null;
    $windGroupFields = null;
    $windGroupSourceMap = null;
    
    // Check for complete wind sets from each source
    $primaryHasCompleteWind = hasCompleteWindSet($primaryData) && $primaryObsTime !== null;
    $backupHasCompleteWind = is_array($backupData) && hasCompleteWindSet($backupData) && $backupObsTime !== null;
    
    // Tier 1: Prefer newest complete wind set from single source
    if ($primaryHasCompleteWind && $backupHasCompleteWind) {
        // Both have complete sets - use newest
        if ($primaryObsTime >= $backupObsTime) {
            $windGroupValid = true;
            $windGroupObsTime = $primaryObsTime;
            $windGroupFields = [
                'wind_speed' => $primaryData['wind_speed'],
                'wind_direction' => $primaryData['wind_direction'],
                'gust_speed' => $primaryData['gust_speed'] ?? null
            ];
            $windGroupSourceMap = [
                'wind_speed' => 'primary',
                'wind_direction' => 'primary',
                'gust_speed' => isset($primaryData['gust_speed']) ? 'primary' : null
            ];
        } else {
            $windGroupValid = true;
            $windGroupObsTime = $backupObsTime;
            $windGroupFields = [
                'wind_speed' => $backupData['wind_speed'],
                'wind_direction' => $backupData['wind_direction'],
                'gust_speed' => $backupData['gust_speed'] ?? null
            ];
            $windGroupSourceMap = [
                'wind_speed' => 'backup',
                'wind_direction' => 'backup',
                'gust_speed' => isset($backupData['gust_speed']) ? 'backup' : null
            ];
        }
    } elseif ($primaryHasCompleteWind) {
        // Only primary has complete set
        $windGroupValid = true;
        $windGroupObsTime = $primaryObsTime;
        $windGroupFields = [
            'wind_speed' => $primaryData['wind_speed'],
            'wind_direction' => $primaryData['wind_direction'],
            'gust_speed' => $primaryData['gust_speed'] ?? null
        ];
        $windGroupSourceMap = [
            'wind_speed' => 'primary',
            'wind_direction' => 'primary',
            'gust_speed' => isset($primaryData['gust_speed']) ? 'primary' : null
        ];
    } elseif ($backupHasCompleteWind) {
        // Only backup has complete set
        $windGroupValid = true;
        $windGroupObsTime = $backupObsTime;
        $windGroupFields = [
            'wind_speed' => $backupData['wind_speed'],
            'wind_direction' => $backupData['wind_direction'],
            'gust_speed' => $backupData['gust_speed'] ?? null
        ];
        $windGroupSourceMap = [
            'wind_speed' => 'backup',
            'wind_direction' => 'backup',
            'gust_speed' => isset($backupData['gust_speed']) ? 'backup' : null
        ];
    } else {
        // Tier 2: Try to merge wind data within tolerance
        if (is_array($backupData)) {
            $mergedWind = tryMergeWindWithinTolerance($primaryData, $backupData, $windToleranceSeconds);
            if ($mergedWind !== null) {
                $windGroupValid = true;
                $windGroupObsTime = $mergedWind['obs_time'];
                $windGroupFields = $mergedWind['fields'];
                $windGroupSourceMap = $mergedWind['source_map'];
            }
        }
    }
    
    // Tier 3: If unable to find valid wind data, windGroupValid remains false
    
    // Process each field
    foreach ($primarySourceFields as $field) {
        $primaryValue = $primaryData[$field] ?? null;
        $backupValue = $backupData[$field] ?? null;
        
        // Check if field belongs to a group that was rejected
        $isWindField = in_array($field, ['wind_speed', 'wind_direction', 'gust_speed', 'gust_factor', 'peak_gust']);
        $isTempField = in_array($field, ['temperature', 'dewpoint', 'humidity', 'dewpoint_spread']);
        $isPressureField = in_array($field, ['pressure', 'altimeter', 'sea_level_pressure', 'pressure_altitude']);
        
        // For wind group: use validated wind group data if available
        if ($isWindField) {
            if (!$windGroupValid) {
                // Wind group validation failed - reject all wind fields
                $result[$field] = null;
                $fieldSourceMap[$field] = null;
                continue;
            }
            
            // Wind group is valid - use the validated wind group data
            if ($windGroupFields !== null && isset($windGroupFields[$field])) {
                // Use value from validated wind group
                $result[$field] = $windGroupFields[$field];
                $fieldSourceMap[$field] = $windGroupSourceMap[$field] ?? null;
                // Track observation time for this field
                if ($windGroupObsTime !== null) {
                    $fieldObsTimeMap[$field] = $windGroupObsTime;
                }
                continue; // Skip normal field processing for wind fields
            } elseif ($field === 'gust_factor' || $field === 'peak_gust') {
                // Calculated fields - will be computed later if source fields are valid
                // For now, continue to normal processing (they may be null)
            } else {
                // Field not in validated wind group - should not happen, but reject to be safe
                $result[$field] = null;
                $fieldSourceMap[$field] = null;
                continue;
            }
        }
        
        // For temperature group: allow individual valid fields even if group validation fails
        // Only reject if the field itself is invalid or if we're trying to combine mismatched times
        if ($isTempField) {
            if (!$tempGroup['valid']) {
                // Group validation failed - check if this field is in the valid individual fields
                if (empty($tempGroup['fields']) || !isset($tempGroup['fields'][$field])) {
                    // Field was not in the group validation result
                    // BUT: if backup has this field and it's valid, allow it even if group failed
                    // This handles edge cases where backup has a field that primary doesn't have
                    // and the times are slightly mismatched (but still reasonable)
                    if ($backupValue !== null && !$backupStale) {
                        // Backup has the field - check if it's within a reasonable time window
                        // Use a more lenient threshold for backup fields (5 minutes instead of 1 minute)
                        $backupObsTime = $primaryData['obs_time_backup'] ?? $primaryData['last_updated_backup'] ?? null;
                        $primaryObsTime = $primaryData['obs_time_primary'] ?? $primaryData['last_updated_primary'] ?? null;
                        
                        // If we have temperature from backup, check if backup dewpoint is within 5 minutes
                        $tempObsTime = null;
                        if (isset($tempGroup['fields']['temperature'])) {
                            $tempObsTime = $tempGroup['fields']['temperature']['obs_time'] ?? null;
                        }
                        
                        if ($backupObsTime !== null && $tempObsTime !== null) {
                            $timeDiff = abs($backupObsTime - $tempObsTime);
                            if ($timeDiff <= 300) { // 5 minutes - more lenient for backup fields
                                // Backup field is within reasonable time - allow it to proceed to validation
                                // Don't reject it here, let it go through normal validation
                            } else {
                                // Too far apart - reject
                                $result[$field] = null;
                                $fieldSourceMap[$field] = null;
                                continue;
                            }
                        } else {
                            // Can't determine time difference - reject to be safe
                            $result[$field] = null;
                            $fieldSourceMap[$field] = null;
                            continue;
                        }
                    } else {
                        // No backup value or backup is stale - reject
                        $result[$field] = null;
                        $fieldSourceMap[$field] = null;
                        continue;
                    }
                }
                // Field is in valid individual fields list - allow it to proceed
            }
        }
        
        // For pressure group: similar logic - allow individual valid fields even if group validation fails
        // Apply same leniency as temperature group for backup fields
        if ($isPressureField) {
            if (!$pressureGroup['valid']) {
                // Group validation failed - check if this field is in the valid individual fields
                if (empty($pressureGroup['fields']) || !isset($pressureGroup['fields'][$field])) {
                    // Field was not in the group validation result
                    // BUT: if backup has this field and it's valid, allow it even if group failed
                    // This handles edge cases where backup has a field that primary doesn't have
                    // and the times are slightly mismatched (but still reasonable)
                    if ($backupValue !== null && !$backupStale) {
                        // Backup has the field - check if it's within a reasonable time window
                        // Use a more lenient threshold for backup fields (5 minutes instead of 1 minute)
                        $backupObsTime = $primaryData['obs_time_backup'] ?? $primaryData['last_updated_backup'] ?? null;
                        $primaryObsTime = $primaryData['obs_time_primary'] ?? $primaryData['last_updated_primary'] ?? null;
                        
                        // Check if backup pressure is within 5 minutes of primary observation time
                        // (pressure doesn't need to match temperature like dewpoint does)
                        if ($backupObsTime !== null && $primaryObsTime !== null) {
                            $timeDiff = abs($backupObsTime - $primaryObsTime);
                            if ($timeDiff <= 300) { // 5 minutes - more lenient for backup fields
                                // Backup field is within reasonable time - allow it to proceed to validation
                                // Don't reject it here, let it go through normal validation
                            } else {
                                // Too far apart - reject
                                $result[$field] = null;
                                $fieldSourceMap[$field] = null;
                                continue;
                            }
                        } else {
                            // Can't determine time difference - reject to be safe
                            $result[$field] = null;
                            $fieldSourceMap[$field] = null;
                            continue;
                        }
                    } else {
                        // No backup value or backup is stale - reject
                        $result[$field] = null;
                        $fieldSourceMap[$field] = null;
                        continue;
                    }
                }
                // Field is in valid individual fields list - allow it to proceed
            }
        }
        
        // Check primary value (with its own context)
        $primaryValid = false;
        if ($primaryValue !== null && !$primaryStale) {
            $primaryContext = [];
            if ($field === 'dewpoint' || $field === 'dewpoint_f') {
                $primaryContext['temperature'] = $primaryData['temperature'] ?? null;
            }
            if ($field === 'gust_speed' || $field === 'peak_gust') {
                $primaryContext['wind_speed'] = $primaryData['wind_speed'] ?? null;
            }
            $primaryValidation = validateWeatherField($field, $primaryValue, null, $primaryContext);
            $primaryValid = $primaryValidation['valid'];
        } elseif ($primaryValue === null && !$primaryStale) {
            // Null value: check if null is valid for this field
            $nullValidation = isFieldNullValid($field, null, $primaryData, $primaryQuality);
            $primaryValid = $nullValidation['valid'];
        }
        
        // Check backup value (with its own context)
        $backupValid = false;
        if ($backupValue !== null && !$backupStale) {
            $backupContext = [];
            // For cross-field validation, use merged values (from either source)
            // This allows backup dewpoint to be validated against primary temperature
            if ($field === 'dewpoint' || $field === 'dewpoint_f') {
                // Use temperature from whichever source has it (primary or backup)
                $backupContext['temperature'] = $primaryData['temperature'] ?? $backupData['temperature'] ?? null;
            }
            if ($field === 'gust_speed' || $field === 'peak_gust') {
                // Use wind_speed from whichever source has it (primary or backup)
                $backupContext['wind_speed'] = $primaryData['wind_speed'] ?? $backupData['wind_speed'] ?? null;
            }
            $backupValidation = validateWeatherField($field, $backupValue, null, $backupContext);
            $backupValid = $backupValidation['valid'];
        } elseif ($backupValue === null && !$backupStale) {
            // Null value: check if null is valid for this field
            // For cross-field validation, use merged values
            $mergedDataForNullCheck = array_merge($backupData, $primaryData);
            $nullValidation = isFieldNullValid($field, null, $mergedDataForNullCheck, $backupQuality);
            $backupValid = $nullValidation['valid'];
        }// Select best value: prefer newest obs_time when both are valid
        // Use recovery cycles and time duration to prevent rapid switching back to primary
        require_once __DIR__ . '/../constants.php';
        $recoveryCycles = $primaryData['primary_recovery_cycles'] ?? 0;
        $recoveryStartTime = $primaryData['primary_recovery_start'] ?? null;
        $currentSource = $existingCache['_field_source_map'][$field] ?? null;
        
        // Track observation time for selected field
        $selectedObsTime = null;
        
        if ($primaryValid && $backupValid) {
            // Both valid - check recovery conditions before switching back to primary
            $recoveryComplete = false;
            if ($currentSource === 'backup') {
                // Check if recovery is complete (both cycles and time duration met)
                $cyclesMet = ($recoveryCycles >= PRIMARY_RECOVERY_CYCLES_THRESHOLD);
                $timeMet = false;
                if ($recoveryStartTime !== null && $recoveryStartTime > 0) {
                    $recoveryDuration = time() - $recoveryStartTime;
                    $timeMet = ($recoveryDuration >= PRIMARY_RECOVERY_TIME_SECONDS);
                }
                $recoveryComplete = ($cyclesMet && $timeMet);
            } else {
                // Already using primary - recovery is considered complete
                $recoveryComplete = true;
            }
            
            if (!$recoveryComplete) {
                // Still in recovery - prefer backup if it was previously used
                $result[$field] = $backupValue;
                $fieldSourceMap[$field] = 'backup';
                $selectedObsTime = $backupObsTime;
            } elseif ($primaryObsTime >= $backupObsTime) {
                // Recovery complete - prefer newest observation time (or primary if equal/newer)
                $result[$field] = $primaryValue;
                $fieldSourceMap[$field] = 'primary';
                $selectedObsTime = $primaryObsTime;
            } else {
                // Recovery complete but backup is newer - use backup
                $result[$field] = $backupValue;
                $fieldSourceMap[$field] = 'backup';
                $selectedObsTime = $backupObsTime;
            }
        } elseif ($primaryValid) {
            // Only primary valid
            $result[$field] = $primaryValue;
            $fieldSourceMap[$field] = 'primary';
            $selectedObsTime = $primaryObsTime;
        } elseif ($backupValid) {
            // Only backup valid
            $result[$field] = $backupValue;
            $fieldSourceMap[$field] = 'backup';
            $selectedObsTime = $backupObsTime;
        } else {
            // Neither valid - set to null (fail closed)
            $result[$field] = null;
            $fieldSourceMap[$field] = null;
            // Don't track obs time for null fields
        }
        
        // Store observation time for this field if we selected a value
        if ($selectedObsTime !== null && $selectedObsTime > 0) {
            $fieldObsTimeMap[$field] = $selectedObsTime;
        }
    }
    
    // Store field source map and observation time map (internal only, not exposed in API)
    $result['_field_source_map'] = $fieldSourceMap;
    if (!empty($fieldObsTimeMap)) {
        $result['_field_obs_time_map'] = $fieldObsTimeMap;
    }
    
    return $result;
}

/**
 * Helper function to null out stale fields based on per-field observation times or source timestamps
 * 
 * Nulls out weather fields that are too stale. Uses per-field observation times if available
 * (from _field_obs_time_map), otherwise falls back to source-level timestamps.
 * This allows granular staleness checking - individual fields can be rejected even if other
 * fields from the same source are fresh.
 * 
 * Fields from primary source are checked against their individual observation times or last_updated_primary.
 * Fields from METAR are checked against their individual observation times or last_updated_metar.
 * Daily tracking values (temp_high_today, temp_low_today, peak_gust_today) are NOT
 * considered stale - they represent valid historical data for the day.
 * 
 * @param array &$data Weather data array (modified in place)
 * @param int $maxStaleSeconds Maximum age in seconds before primary source field is considered stale
 * @param int|null $maxStaleSecondsMetar Maximum age in seconds before METAR field is considered stale (defaults to $maxStaleSeconds if null)
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
    
    // Check backup source staleness (if backup was used for any fields)
    // Use per-field observation times if available, otherwise use source-level timestamp
    $backupStale = false;
    $backupSourceAge = PHP_INT_MAX;
    if (isset($data['last_updated_backup']) && $data['last_updated_backup'] > 0) {
        $backupSourceAge = $now - $data['last_updated_backup'];
        $backupStale = ($backupSourceAge >= $maxStaleSeconds);
    }
    
    if (isset($data['_field_source_map']) && is_array($data['_field_source_map'])) {
        foreach ($data['_field_source_map'] as $field => $source) {
            if ($source === 'backup' && isset($data[$field]) && $data[$field] !== null) {
                // Check per-field observation time if available
                if (isset($fieldObsTimeMap[$field]) && $fieldObsTimeMap[$field] > 0) {
                    $fieldAge = $now - $fieldObsTimeMap[$field];
                    if ($fieldAge >= $maxStaleSeconds) {
                        // This specific backup field is stale - reject it
                        $data[$field] = null;
                    }
                } elseif ($backupStale) {
                    // No per-field obs time - fall back to source-level staleness check
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

