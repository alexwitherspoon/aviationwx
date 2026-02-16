<?php
/**
 * Aggregation Policy - Global rules for combining weather data from multiple sources
 * 
 * Defines consistent aggregation behavior across all airports:
 * - Which fields must be grouped (wind)
 * - Which fields prefer METAR (visibility, ceiling)
 * - Staleness thresholds (uses 3-tier model from constants.php)
 * 
 * These rules are global, not per-airport, for predictable behavior.
 * 
 * @package AviationWX\Weather
 */

namespace AviationWX\Weather;

// Load constants for staleness thresholds
require_once __DIR__ . '/../constants.php';

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
     * Local fields: prefer local source over neighboring METAR.
     *
     * Wind, temperature, humidity, etc. at the airport should come from
     * on-site sensors when available. Neighboring METAR (different station)
     * may fill in missing fields but must not override local measurements.
     *
     * @see docs/DATA_FLOW.md Local vs Neighboring METAR
     */
    public const LOCAL_FIELDS = [
        'wind_speed',
        'wind_direction',
        'gust_speed',
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
     * Recovery cycles threshold
     * 
     * How many consecutive successful fetches before a previously-failed
     * source is considered healthy again.
     */
    public const RECOVERY_CYCLES = 3;
    
    // =========================================================================
    // STALENESS THRESHOLDS (3-Tier Model)
    // =========================================================================
    // These methods provide access to staleness thresholds from constants.php
    // Use these instead of hardcoded values for consistent behavior.
    
    /**
     * Get stale warning threshold for weather data
     * @return int Threshold in seconds
     */
    public static function getStaleWarningSeconds(): int {
        return \DEFAULT_STALE_WARNING_SECONDS;
    }
    
    /**
     * Get stale error threshold for weather data
     * @return int Threshold in seconds
     */
    public static function getStaleErrorSeconds(): int {
        return \DEFAULT_STALE_ERROR_SECONDS;
    }
    
    /**
     * Get stale failclosed threshold for weather data (data hidden from user)
     * @return int Threshold in seconds
     */
    public static function getStaleFailclosedSeconds(): int {
        return \DEFAULT_STALE_FAILCLOSED_SECONDS;
    }
    
    /**
     * Get METAR stale warning threshold
     * @return int Threshold in seconds
     */
    public static function getMetarStaleWarningSeconds(): int {
        return \DEFAULT_METAR_STALE_WARNING_SECONDS;
    }
    
    /**
     * Get METAR stale error threshold
     * @return int Threshold in seconds
     */
    public static function getMetarStaleErrorSeconds(): int {
        return \DEFAULT_METAR_STALE_ERROR_SECONDS;
    }
    
    /**
     * Get METAR stale failclosed threshold
     * @return int Threshold in seconds
     */
    public static function getMetarStaleFailclosedSeconds(): int {
        return \DEFAULT_METAR_STALE_FAILCLOSED_SECONDS;
    }
    
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
     * Determine staleness tier for a given age
     * 
     * @param int $ageSeconds Age of data in seconds
     * @param bool $isMetar Whether this is a METAR source
     * @return string 'fresh' | 'warning' | 'error' | 'failclosed'
     */
    public static function getStaleTier(int $ageSeconds, bool $isMetar = false): string {
        if ($isMetar) {
            if ($ageSeconds >= self::getMetarStaleFailclosedSeconds()) {
                return 'failclosed';
            }
            if ($ageSeconds >= self::getMetarStaleErrorSeconds()) {
                return 'error';
            }
            if ($ageSeconds >= self::getMetarStaleWarningSeconds()) {
                return 'warning';
            }
            return 'fresh';
        }
        
        if ($ageSeconds >= self::getStaleFailclosedSeconds()) {
            return 'failclosed';
        }
        if ($ageSeconds >= self::getStaleErrorSeconds()) {
            return 'error';
        }
        if ($ageSeconds >= self::getStaleWarningSeconds()) {
            return 'warning';
        }
        return 'fresh';
    }
    
    /**
     * Check if data should be hidden (failclosed tier)
     * 
     * @param int $ageSeconds Age of data in seconds
     * @param bool $isMetar Whether this is a METAR source
     * @return bool True if data should be hidden from user
     */
    public static function isFailclosed(int $ageSeconds, bool $isMetar = false): bool {
        return self::getStaleTier($ageSeconds, $isMetar) === 'failclosed';
    }
    
    /**
     * Get failclosed threshold for a source type
     * This is the "stop showing data" threshold
     * 
     * @param bool $isMetar Whether this is a METAR source
     * @return int Maximum acceptable age in seconds before failclosed
     */
    public static function getFailclosedThreshold(bool $isMetar = false): int {
        return $isMetar ? self::getMetarStaleFailclosedSeconds() : self::getStaleFailclosedSeconds();
    }
    
    /**
     * Get warning threshold for a source type
     * 
     * @param bool $isMetar Whether this is a METAR source
     * @return int Warning age threshold in seconds
     */
    public static function getWarningThreshold(bool $isMetar = false): int {
        return $isMetar ? self::getMetarStaleWarningSeconds() : self::getStaleWarningSeconds();
    }
    
    /**
     * Get error threshold for a source type
     * 
     * @param bool $isMetar Whether this is a METAR source
     * @return int Error age threshold in seconds
     */
    public static function getErrorThreshold(bool $isMetar = false): int {
        return $isMetar ? self::getMetarStaleErrorSeconds() : self::getStaleErrorSeconds();
    }
}

