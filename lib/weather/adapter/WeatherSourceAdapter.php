<?php
/**
 * Weather Source Adapter Interface
 * 
 * All weather source adapters (Tempest, Ambient, METAR, etc.) implement this interface.
 * Adapters are self-describing: they define what fields they provide and their
 * typical update frequency, which the aggregator uses to make smart decisions.
 * 
 * @package AviationWX\Weather\Adapter
 */

namespace AviationWX\Weather\Adapter;

use AviationWX\Weather\Data\WeatherSnapshot;

interface WeatherSourceAdapter {
    
    /**
     * Get the fields this adapter can provide
     * 
     * Returns an array of field names that this source typically provides.
     * Used by the aggregator to know which sources to consult for which fields.
     * 
     * @return array<string> Field names (e.g., ['temperature', 'humidity', 'wind_speed'])
     */
    public static function getFieldsProvided(): array;
    
    /**
     * Get typical update frequency in seconds
     * 
     * How often this source typically updates its data.
     * - Tempest: ~60 seconds (real-time)
     * - SynopticData: ~300-600 seconds (5-10 minutes)
     * - METAR: ~3600 seconds (hourly, with specials)
     * 
     * Used for calculating staleness thresholds.
     * 
     * @return int Update frequency in seconds
     */
    public static function getTypicalUpdateFrequency(): int;
    
    /**
     * Get maximum acceptable age before data is considered stale
     * 
     * Data older than this should not be used. This is typically a multiple
     * of the update frequency to allow for brief outages.
     * 
     * @return int Maximum age in seconds
     */
    public static function getMaxAcceptableAge(): int;
    
    /**
     * Get the source type identifier
     * 
     * Returns the type string used in config (e.g., 'tempest', 'ambient', 'metar')
     * 
     * @return string Source type identifier
     */
    public static function getSourceType(): string;
    
    /**
     * Check if this source provides a specific field
     * 
     * @param string $fieldName Field to check
     * @return bool True if this source can provide the field
     */
    public static function providesField(string $fieldName): bool;
    
    /**
     * Parse a raw API response into a WeatherSnapshot
     * 
     * @param string $response Raw API response (usually JSON)
     * @param array $config Source configuration (API keys, station IDs, etc.)
     * @return WeatherSnapshot|null Parsed snapshot or null if parsing failed
     */
    public static function parseResponse(string $response, array $config = []): ?WeatherSnapshot;
    
    /**
     * Build the API URL for fetching data
     * 
     * @param array $config Source configuration
     * @return string|null API URL or null if config is invalid
     */
    public static function buildUrl(array $config): ?string;
}

