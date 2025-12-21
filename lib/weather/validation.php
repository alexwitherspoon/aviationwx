<?php
/**
 * Weather Data Validation
 * 
 * Functions for validating weather data against climate bounds and field relationships.
 * Ensures data quality by rejecting clearly invalid values (earth extremes + 10% margin).
 */

require_once __DIR__ . '/../constants.php';

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
    // Null values are acceptable (missing data)
    if ($value === null) {
        return ['valid' => true, 'reason' => null];
    }
    
    // Non-numeric values are invalid for numeric fields
    if (!is_numeric($value)) {
        return ['valid' => false, 'reason' => 'non-numeric value'];
    }
    
    $numValue = (float)$value;
    
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

