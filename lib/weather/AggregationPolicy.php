<?php
/**
 * Aggregation Policy - Global rules for combining weather data from multiple sources
 * 
 * Defines consistent aggregation behavior across all airports:
 * - Which fields must be grouped (wind)
 * - Which fields prefer METAR (visibility, ceiling)
 * - Staleness thresholds
 * 
 * These rules are global, not per-airport, for predictable behavior.
 * 
 * @package AviationWX\Weather
 */

namespace AviationWX\Weather;

class AggregationPolicy {
    
    /**
     * Fields that MUST come from the same source as a group
     * 
     * Wind speed, direction, and gust must be from the same source/time
     * to avoid misleading pilots with mixed measurements.
     */
    public const GROUPED_FIELDS = [
        'wind' => ['wind_speed', 'wind_direction', 'gust_speed'],
    ];
    
    /**
     * Fields where METAR is the preferred/authoritative source
     * 
     * When METAR has these fields, use METAR regardless of other sources.
     * Fall back to other sources only if METAR is unavailable.
     */
    public const METAR_PREFERRED_FIELDS = [
        'visibility',
        'ceiling',
        'cloud_cover',
    ];
    
    /**
     * Fields that can be selected independently from any source
     * 
     * Each field is selected based on freshness and validity,
     * independent of where other fields come from.
     */
    public const INDEPENDENT_FIELDS = [
        'temperature',
        'dewpoint',
        'humidity',
        'pressure',
        'precip_accum',
    ];
    
    /**
     * All weather fields we track
     */
    public const ALL_FIELDS = [
        'temperature',
        'dewpoint',
        'humidity',
        'pressure',
        'precip_accum',
        'wind_speed',
        'wind_direction',
        'gust_speed',
        'visibility',
        'ceiling',
        'cloud_cover',
    ];
    
    /**
     * Calculated fields (derived from other fields)
     */
    public const CALCULATED_FIELDS = [
        'temperature_f',      // Fahrenheit conversion
        'dewpoint_f',         // Fahrenheit conversion
        'dewpoint_spread',    // temperature - dewpoint
        'gust_factor',        // gust - wind_speed
        'pressure_altitude',  // Calculated from pressure + elevation
        'density_altitude',   // Calculated from pressure + temp + elevation
        'flight_category',    // VFR/MVFR/IFR/LIFR from ceiling + visibility
    ];
    
    /**
     * Staleness warning multiplier
     * 
     * Data older than (update_frequency * WARNING_MULTIPLIER) triggers a warning
     * but is still shown to users.
     */
    public const WARNING_MULTIPLIER = 5;
    
    /**
     * Staleness error multiplier
     * 
     * Data older than (update_frequency * ERROR_MULTIPLIER) is NULLed out
     * and not shown to users (fail closed).
     */
    public const ERROR_MULTIPLIER = 10;
    
    /**
     * Maximum absolute staleness (hard limit)
     * 
     * Data older than this is always considered stale, regardless of source.
     * This is the safety backstop.
     */
    public const MAX_STALE_SECONDS = 10800; // 3 hours
    
    /**
     * METAR-specific staleness threshold
     * 
     * METAR updates hourly (with specials), so we allow longer staleness.
     */
    public const METAR_MAX_STALE_SECONDS = 7200; // 2 hours
    
    /**
     * Recovery cycles threshold
     * 
     * How many consecutive successful fetches before a previously-failed
     * source is considered healthy again.
     */
    public const RECOVERY_CYCLES = 3;
    
    /**
     * Check if a field is part of a group
     * 
     * @param string $fieldName Field to check
     * @return string|null Group name or null if independent
     */
    public static function getFieldGroup(string $fieldName): ?string {
        foreach (self::GROUPED_FIELDS as $groupName => $fields) {
            if (in_array($fieldName, $fields, true)) {
                return $groupName;
            }
        }
        return null;
    }
    
    /**
     * Check if a field prefers METAR
     * 
     * @param string $fieldName Field to check
     * @return bool True if METAR is preferred source
     */
    public static function isMetarPreferred(string $fieldName): bool {
        return in_array($fieldName, self::METAR_PREFERRED_FIELDS, true);
    }
    
    /**
     * Check if a field is independent (not grouped, not METAR-preferred)
     * 
     * @param string $fieldName Field to check
     * @return bool True if field is independently selectable
     */
    public static function isIndependent(string $fieldName): bool {
        return in_array($fieldName, self::INDEPENDENT_FIELDS, true);
    }
    
    /**
     * Calculate staleness threshold for a source
     * 
     * @param int $updateFrequency Source's typical update frequency in seconds
     * @param bool $isMetar Whether this is a METAR source
     * @return int Maximum acceptable age in seconds
     */
    public static function calculateMaxAge(int $updateFrequency, bool $isMetar = false): int {
        if ($isMetar) {
            return self::METAR_MAX_STALE_SECONDS;
        }
        
        // Use error multiplier for hard cutoff
        $calculated = $updateFrequency * self::ERROR_MULTIPLIER;
        
        // Cap at absolute maximum
        return min($calculated, self::MAX_STALE_SECONDS);
    }
    
    /**
     * Calculate warning threshold for a source
     * 
     * @param int $updateFrequency Source's typical update frequency in seconds
     * @param bool $isMetar Whether this is a METAR source
     * @return int Warning age threshold in seconds
     */
    public static function calculateWarningAge(int $updateFrequency, bool $isMetar = false): int {
        if ($isMetar) {
            return 3600; // 1 hour warning for METAR
        }
        
        return $updateFrequency * self::WARNING_MULTIPLIER;
    }
}

