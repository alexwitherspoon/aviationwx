<?php
/**
 * Weather Data Validation
 * 
 * Functions for validating weather data against climate bounds and field relationships.
 * Ensures data quality by rejecting clearly invalid values (earth extremes + 10% margin).
 */

require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/utils.php';

/**
 * Check if a null value is valid for a specific field
 * 
 * Determines if a null value represents a valid state (e.g., no wind, no gust) or
 * an invalid state (e.g., broken sensor, failed measurement). Uses quality metadata
 * and cross-field consistency to make this determination.
 * 
 * @param string $field Field name (e.g., 'wind_speed', 'wind_direction', 'dewpoint')
 * @param mixed $value Field value (should be null for this function)
 * @param array $data Full weather data array for cross-field consistency checks
 * @param array $qualityMetadata Quality metadata from API (e.g., QCcode, RESPONSE_CODE, http_status)
 * @return array {
 *   'valid' => bool,              // True if null is valid for this field, false otherwise
 *   'reason' => string|null      // Reason for validity/invalidity
 * }
 */
function isFieldNullValid(string $field, $value, array $data, array $qualityMetadata = []): array {
    // This function is specifically for null values
    if ($value !== null) {
        return ['valid' => true, 'reason' => 'not_null'];
    }
    
    // Strong indicator: Quality code or HTTP error says there are issues
    if (!empty($qualityMetadata['has_quality_issues']) || 
        (isset($qualityMetadata['http_status']) && $qualityMetadata['http_status'] !== 200 && $qualityMetadata['http_status'] !== 0)) {
        // Quality metadata indicates problems - null is likely invalid
        return ['valid' => false, 'reason' => 'api_indicates_issues'];
    }
    
    // Field-specific validation
    switch ($field) {
        // Core measurements - null is always invalid (sensor should always report)
        case 'temperature':
        case 'humidity':
        case 'pressure':
        case 'wind_speed':
            // Check cross-field consistency: if other sensors working, null is likely invalid
            $otherFieldsWorking = (
                ($data['temperature'] ?? null) !== null ||
                ($data['pressure'] ?? null) !== null ||
                ($data['humidity'] ?? null) !== null
            );
            if ($otherFieldsWorking) {
                return ['valid' => false, 'reason' => 'sensor_failure_with_other_sensors_working'];
            }
            // If all sensors null, might be system failure (but we'll be conservative)
            return ['valid' => false, 'reason' => 'core_measurement_missing'];
        
        // Unlimited fields - null is valid (represents unlimited, but we use sentinels now)
        // Note: With sentinels, null should not occur for visibility/ceiling, but handle gracefully
        case 'visibility':
        case 'ceiling':
            // Null visibility/ceiling should be converted to sentinels in adapters
            // If we see null here, it's likely a failure (should have been sentinel)
            return ['valid' => false, 'reason' => 'unlimited_field_should_use_sentinel'];
        
        // Conditional fields - null is valid under certain conditions
        case 'wind_direction':
            // Valid if wind_speed is 0 or null (direction undefined when no wind)
            $windSpeed = $data['wind_speed'] ?? null;
            if ($windSpeed === 0 || $windSpeed === null) {
                return ['valid' => true, 'reason' => 'no_wind'];
            }
            return ['valid' => false, 'reason' => 'wind_direction_missing'];
        
        case 'gust_speed':
        case 'peak_gust':
            // Valid if no gust (null = no gust, which is valid)
            return ['valid' => true, 'reason' => 'no_gust'];
        
        case 'dewpoint':
            // Valid if temperature is null (can't calculate dewpoint without temp)
            // Invalid if temperature exists but dewpoint is null (sensor should report)
            if (($data['temperature'] ?? null) === null) {
                return ['valid' => true, 'reason' => 'temperature_missing'];
            }
            // Check cross-field consistency
            $otherFieldsWorking = (
                ($data['humidity'] ?? null) !== null ||
                ($data['pressure'] ?? null) !== null
            );
            if ($otherFieldsWorking) {
                return ['valid' => false, 'reason' => 'dewpoint_missing_with_other_sensors_working'];
            }
            return ['valid' => false, 'reason' => 'dewpoint_missing'];
        
        case 'precip_accum':
            // Precipitation is often optional
            // If other sensors working, null is likely valid (precip optional)
            // If all sensors null, likely system failure
            $otherFieldsWorking = (
                ($data['temperature'] ?? null) !== null ||
                ($data['pressure'] ?? null) !== null ||
                ($data['humidity'] ?? null) !== null
            );
            if ($otherFieldsWorking) {
                return ['valid' => true, 'reason' => 'precipitation_optional'];
            }
            return ['valid' => false, 'reason' => 'precipitation_missing_with_other_failures'];
        
        case 'visibility':
        case 'ceiling':
            // These should use sentinels, but if null, check context
            // METAR provides these, so null might mean unlimited (but should be sentinel)
            return ['valid' => false, 'reason' => 'should_use_sentinel'];
        
        default:
            // Unknown fields: assume valid (conservative)
            return ['valid' => true, 'reason' => null];
    }
}

/**
 * Validate weather field value against climate bounds
 * 
 * Validates a weather field value against established climate bounds (earth extremes + 10% margin).
 * Also validates field relationships (e.g., dewpoint <= temperature, peak_gust >= wind_speed).
 * 
 * @param string $field Field name (e.g., 'temperature', 'wind_speed', 'pressure')
 * @param mixed $value Field value to validate
 * @param string|null $unit Unit of measure (optional, for fields that may have multiple units)
 * @param array $context Additional context for relationship validation (e.g., ['wind_speed' => 10, 'temperature' => 15])
 * @return array {
 *   'valid' => bool,              // True if value is valid, false otherwise
 *   'reason' => string|null      // Reason for invalidity, or null if valid
 * }
 */
function validateWeatherField(string $field, $value, ?string $unit = null, array $context = []): array {
    // Null values: check if null is valid for this field
    // Note: This function doesn't have full data context, so we can't fully validate null
    // Null validation should be done by isFieldNullValid() with full context
    // For now, return valid (bounds validation doesn't apply to null)
    if ($value === null) {
        return ['valid' => true, 'reason' => null];
    }
    
    // Non-numeric values are invalid for numeric fields
    if (!is_numeric($value)) {
        return ['valid' => false, 'reason' => 'non-numeric value'];
    }
    
    $numValue = (float)$value;
    
    // IMPORTANT: Check for sentinel values BEFORE bounds validation
    // Sentinel values represent "unlimited" conditions and should pass validation
    if ($field === 'visibility' && $numValue === UNLIMITED_VISIBILITY_SM) {
        return ['valid' => true, 'reason' => 'unlimited_visibility'];
    }
    if ($field === 'ceiling' && $numValue === UNLIMITED_CEILING_FT) {
        return ['valid' => true, 'reason' => 'unlimited_ceiling'];
    }
    
    // Validate based on field type
    switch ($field) {
        case 'temperature':
        case 'temperature_f':
            if ($field === 'temperature_f') {
                // Convert Fahrenheit to Celsius for validation
                $tempC = ($numValue - 32) * 5 / 9;
            } else {
                $tempC = $numValue;
            }
            if ($tempC < CLIMATE_TEMP_MIN_C || $tempC > CLIMATE_TEMP_MAX_C) {
                return ['valid' => false, 'reason' => 'temperature out of bounds'];
            }
            break;
            
        case 'wind_speed':
            if ($numValue < 0 || $numValue > CLIMATE_WIND_SPEED_MAX_KTS) {
                return ['valid' => false, 'reason' => 'wind speed out of bounds'];
            }
            break;
            
        case 'wind_direction':
            if ($numValue < CLIMATE_WIND_DIRECTION_MIN || $numValue > CLIMATE_WIND_DIRECTION_MAX) {
                return ['valid' => false, 'reason' => 'wind direction out of bounds'];
            }
            break;
            
        case 'pressure':
            if ($numValue < CLIMATE_PRESSURE_MIN_INHG || $numValue > CLIMATE_PRESSURE_MAX_INHG) {
                return ['valid' => false, 'reason' => 'pressure out of bounds'];
            }
            break;
            
        case 'humidity':
            if ($numValue < CLIMATE_HUMIDITY_MIN || $numValue > CLIMATE_HUMIDITY_MAX) {
                return ['valid' => false, 'reason' => 'humidity out of bounds'];
            }
            break;
            
        case 'dewpoint':
        case 'dewpoint_f':
            if ($field === 'dewpoint_f') {
                // Convert Fahrenheit to Celsius for validation
                $dewpointC = ($numValue - 32) * 5 / 9;
            } else {
                $dewpointC = $numValue;
            }
            if ($dewpointC < CLIMATE_DEWPOINT_MIN_C || $dewpointC > CLIMATE_DEWPOINT_MAX_C) {
                return ['valid' => false, 'reason' => 'dewpoint out of bounds'];
            }
            // Validate relationship: dewpoint <= temperature
            if (isset($context['temperature']) && is_numeric($context['temperature'])) {
                if ($dewpointC > $context['temperature']) {
                    return ['valid' => false, 'reason' => 'dewpoint exceeds temperature'];
                }
            }
            break;
            
        case 'dewpoint_spread':
            if ($numValue < CLIMATE_DEWPOINT_SPREAD_MIN_C || $numValue > CLIMATE_DEWPOINT_SPREAD_MAX_C) {
                return ['valid' => false, 'reason' => 'dewpoint spread out of bounds'];
            }
            break;
            
        case 'precip_accum':
            if ($numValue < CLIMATE_PRECIP_MIN_INCHES_DAY || $numValue > CLIMATE_PRECIP_MAX_INCHES_DAY) {
                return ['valid' => false, 'reason' => 'precipitation out of bounds'];
            }
            break;
            
        case 'visibility':
            if ($numValue < CLIMATE_VISIBILITY_MIN_SM || $numValue > CLIMATE_VISIBILITY_MAX_SM) {
                return ['valid' => false, 'reason' => 'visibility out of bounds'];
            }
            break;
            
        case 'ceiling':
            if ($numValue < CLIMATE_CEILING_MIN_FT || $numValue > CLIMATE_CEILING_MAX_FT) {
                return ['valid' => false, 'reason' => 'ceiling out of bounds'];
            }
            break;
            
        case 'gust_speed':
        case 'peak_gust':
            // Peak gust must be >= wind_speed (gusts can't be less than steady wind)
            if ($numValue < CLIMATE_PEAK_GUST_MIN_KTS || $numValue > CLIMATE_PEAK_GUST_MAX_KTS) {
                return ['valid' => false, 'reason' => 'peak gust out of bounds'];
            }
            // Validate relationship: peak_gust >= wind_speed
            if (isset($context['wind_speed']) && is_numeric($context['wind_speed'])) {
                if ($numValue < $context['wind_speed']) {
                    return ['valid' => false, 'reason' => 'peak gust less than wind speed'];
                }
            }
            break;
            
        case 'peak_gust_today':
            if ($numValue < CLIMATE_PEAK_GUST_TODAY_MIN_KTS || $numValue > CLIMATE_PEAK_GUST_TODAY_MAX_KTS) {
                return ['valid' => false, 'reason' => 'peak gust today out of bounds'];
            }
            break;
            
        case 'gust_factor':
            // Gust factor can be null (no gust factor at that time)
            // If not null, must be non-negative and within reasonable range
            if ($numValue < CLIMATE_GUST_FACTOR_MIN_KTS || $numValue > CLIMATE_GUST_FACTOR_MAX_KTS) {
                return ['valid' => false, 'reason' => 'gust factor out of bounds'];
            }
            break;
            
        case 'pressure_altitude':
            if ($numValue < CLIMATE_PRESSURE_ALTITUDE_MIN_FT || $numValue > CLIMATE_PRESSURE_ALTITUDE_MAX_FT) {
                return ['valid' => false, 'reason' => 'pressure altitude out of bounds'];
            }
            break;
            
        case 'density_altitude':
            if ($numValue < CLIMATE_DENSITY_ALTITUDE_MIN_FT || $numValue > CLIMATE_DENSITY_ALTITUDE_MAX_FT) {
                return ['valid' => false, 'reason' => 'density altitude out of bounds'];
            }
            break;
            
        default:
            // Unknown fields are considered valid (don't reject new fields)
            return ['valid' => true, 'reason' => null];
    }
    
    return ['valid' => true, 'reason' => null];
}

