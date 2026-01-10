<?php
/**
 * Weather Aggregator - Combines weather data from multiple sources
 * 
 * Takes snapshots from multiple sources (Tempest, Ambient, METAR, etc.)
 * and produces a single aggregated weather observation.
 * 
 * Key principles:
 * - Wind group must come from a single source (complete or nothing)
 * - METAR is preferred for visibility/ceiling when available
 * - Other fields: freshest valid value wins (in source priority order)
 * - Pure function: no side effects, no cache reading
 * 
 * @package AviationWX\Weather
 */

namespace AviationWX\Weather;

use AviationWX\Weather\Data\WeatherSnapshot;
use AviationWX\Weather\Data\WeatherReading;
use AviationWX\Weather\Data\WindGroup;

class WeatherAggregator {
    
    /** @var int Current timestamp (injectable for testing) */
    private int $now;
    
    /**
     * Create a new aggregator
     * 
     * @param int|null $now Current timestamp (defaults to time())
     */
    public function __construct(?int $now = null) {
        $this->now = $now ?? time();
    }
    
    /**
     * Aggregate weather data from multiple sources
     * 
     * Sources should be provided in preference order (first = most preferred).
     * The aggregator will select the best data from each source based on
     * freshness and completeness.
     * 
     * During aggregation, we accept any data < 3 hours old (failclosed threshold).
     * Staleness indicators (warning/error) are applied later during display.
     * 
     * @param array<WeatherSnapshot> $snapshots Snapshots in preference order
     * @return array Aggregated weather data with attribution
     */
    public function aggregate(array $snapshots): array {
        if (empty($snapshots)) {
            return $this->emptyResult();
        }
        
        $result = [];
        $fieldObsTimeMap = [];
        $fieldSourceMap = [];
        
        // ═══════════════════════════════════════════════════════════════════
        // STEP 1: Select wind group (must be complete from single source)
        // ═══════════════════════════════════════════════════════════════════
        
        $windResult = $this->selectWindGroup($snapshots);
        $result = array_merge($result, $windResult['fields']);
        $fieldObsTimeMap = array_merge($fieldObsTimeMap, $windResult['obs_times']);
        $fieldSourceMap = array_merge($fieldSourceMap, $windResult['sources']);
        
        // ═══════════════════════════════════════════════════════════════════
        // STEP 2: Select METAR-preferred fields (visibility, ceiling, cloud_cover)
        // ═══════════════════════════════════════════════════════════════════
        
        foreach (AggregationPolicy::METAR_PREFERRED_FIELDS as $fieldName) {
            $reading = $this->selectMetarPreferredField($fieldName, $snapshots);
            $result[$fieldName] = $reading->value;
            if ($reading->observationTime !== null) {
                $fieldObsTimeMap[$fieldName] = $reading->observationTime;
            }
            if ($reading->source !== null) {
                $fieldSourceMap[$fieldName] = $reading->source;
            }
        }
        
        // ═══════════════════════════════════════════════════════════════════
        // STEP 3: Select independent fields (temperature, humidity, etc.)
        // ═══════════════════════════════════════════════════════════════════
        
        foreach (AggregationPolicy::INDEPENDENT_FIELDS as $fieldName) {
            $reading = $this->selectBestField($fieldName, $snapshots);
            $result[$fieldName] = $reading->value;
            if ($reading->observationTime !== null) {
                $fieldObsTimeMap[$fieldName] = $reading->observationTime;
            }
            if ($reading->source !== null) {
                $fieldSourceMap[$fieldName] = $reading->source;
            }
        }
        
        // ═══════════════════════════════════════════════════════════════════
        // STEP 4: Add metadata
        // ═══════════════════════════════════════════════════════════════════
        
        $result['_field_obs_time_map'] = $fieldObsTimeMap;
        $result['_field_source_map'] = $fieldSourceMap;
        
        // Determine overall observation times
        $result['obs_time_primary'] = $this->findPrimaryObsTime($snapshots);
        $result['obs_time_metar'] = $this->findMetarObsTime($snapshots);
        $result['last_updated_primary'] = $this->findPrimaryFetchTime($snapshots);
        $result['last_updated_metar'] = $this->findMetarFetchTime($snapshots);
        
        // Overall last_updated is the freshest observation we're using
        $result['last_updated'] = !empty($fieldObsTimeMap) ? max($fieldObsTimeMap) : $this->now;
        $result['last_updated_iso'] = date('c', $result['last_updated']);
        
        // Add raw METAR if available
        foreach ($snapshots as $snapshot) {
            if ($snapshot->source === 'metar' && $snapshot->rawMetar !== null) {
                $result['raw_metar'] = $snapshot->rawMetar;
                break;
            }
        }
        
        return $result;
    }
    
    /**
     * Select wind group from the first source that has complete data
     * 
     * Wind must come as a complete group (speed + direction) from a single source.
     * We don't mix wind speed from one source with direction from another.
     * 
     * @param array<WeatherSnapshot> $snapshots
     * @return array{fields: array, obs_times: array, sources: array}
     */
    private function selectWindGroup(array $snapshots): array {
        foreach ($snapshots as $snapshot) {
            if (!$snapshot->hasCompleteWind()) {
                continue;
            }
            
            // Aggregator is permissive: accept data up to failclosed threshold (3 hours)
            // Staleness warnings/errors are applied later during display
            $isMetar = $snapshot->source === 'metar';
            $maxAge = $isMetar 
                ? AggregationPolicy::getMetarStaleFailclosedSeconds() 
                : AggregationPolicy::getStaleFailclosedSeconds();
            
            if ($snapshot->wind->isStale($maxAge, $this->now)) {
                continue;
            }
            
            // Found a complete, fresh wind group
            $windData = $snapshot->wind->toArray();
            $obsTimeMap = $snapshot->wind->getObservationTimeMap();
            
            $sourceMap = [];
            foreach (array_keys($windData) as $field) {
                if ($windData[$field] !== null) {
                    $sourceMap[$field] = $snapshot->source;
                }
            }
            
            return [
                'fields' => $windData,
                'obs_times' => $obsTimeMap,
                'sources' => $sourceMap,
            ];
        }
        
        // No complete wind group found - return nulls
        return [
            'fields' => [
                'wind_speed' => null,
                'wind_direction' => null,
                'gust_speed' => null,
                'gust_factor' => null,
            ],
            'obs_times' => [],
            'sources' => [],
        ];
    }
    
    /**
     * Select a METAR-preferred field
     * 
     * If METAR has this field and it's not stale, use METAR.
     * Otherwise, fall back to regular field selection.
     * 
     * @param string $fieldName
     * @param array<WeatherSnapshot> $snapshots
     * @return WeatherReading
     */
    private function selectMetarPreferredField(string $fieldName, array $snapshots): WeatherReading {
        // First, look for METAR source
        foreach ($snapshots as $snapshot) {
            if ($snapshot->source !== 'metar') {
                continue;
            }
            
            $reading = $snapshot->getField($fieldName);
            if ($reading === null || !$reading->hasValue()) {
                break; // METAR doesn't have this field, fall back
            }
            
            // Aggregator is permissive: accept METAR data up to failclosed threshold
            $maxAge = AggregationPolicy::getMetarStaleFailclosedSeconds();
            if (!$reading->isStale($maxAge, $this->now)) {
                return $reading->withSource('metar');
            }
            
            break; // METAR is stale, fall back
        }
        
        // Fall back to regular selection
        return $this->selectBestField($fieldName, $snapshots);
    }
    
    /**
     * Select the best value for an independent field
     * 
     * Returns the first valid, non-stale value from sources in priority order.
     * 
     * @param string $fieldName
     * @param array<WeatherSnapshot> $snapshots
     * @return WeatherReading
     */
    private function selectBestField(string $fieldName, array $snapshots): WeatherReading {
        foreach ($snapshots as $snapshot) {
            $reading = $snapshot->getField($fieldName);
            if ($reading === null || !$reading->hasValue()) {
                continue;
            }
            
            $isMetar = $snapshot->source === 'metar';
            // Aggregator is permissive: accept data up to failclosed threshold (3 hours)
            // Staleness warnings/errors are applied later during display
            $maxAge = $isMetar 
                ? AggregationPolicy::getMetarStaleFailclosedSeconds() 
                : AggregationPolicy::getStaleFailclosedSeconds();
            
            if (!$reading->isStale($maxAge, $this->now)) {
                return $reading->withSource($snapshot->source);
            }
        }
        
        // No valid value found
        return WeatherReading::null();
    }
    
    /**
     * Find observation time from primary (non-METAR) source
     * 
     * @param array<WeatherSnapshot> $snapshots
     * @return int|null
     */
    private function findPrimaryObsTime(array $snapshots): ?int {
        foreach ($snapshots as $snapshot) {
            if ($snapshot->source === 'metar') {
                continue;
            }
            // Use temperature observation time as representative
            $obsTime = $snapshot->temperature->observationTime 
                ?? $snapshot->wind->speed->observationTime;
            if ($obsTime !== null) {
                return $obsTime;
            }
        }
        return null;
    }
    
    /**
     * Find observation time from METAR source
     * 
     * @param array<WeatherSnapshot> $snapshots
     * @return int|null
     */
    private function findMetarObsTime(array $snapshots): ?int {
        foreach ($snapshots as $snapshot) {
            if ($snapshot->source === 'metar') {
                return $snapshot->visibility->observationTime 
                    ?? $snapshot->temperature->observationTime;
            }
        }
        return null;
    }
    
    /**
     * Find fetch time from primary source
     * 
     * @param array<WeatherSnapshot> $snapshots
     * @return int|null
     */
    private function findPrimaryFetchTime(array $snapshots): ?int {
        foreach ($snapshots as $snapshot) {
            if ($snapshot->source !== 'metar') {
                return $snapshot->fetchTime;
            }
        }
        return null;
    }
    
    /**
     * Find fetch time from METAR source
     * 
     * @param array<WeatherSnapshot> $snapshots
     * @return int|null
     */
    private function findMetarFetchTime(array $snapshots): ?int {
        foreach ($snapshots as $snapshot) {
            if ($snapshot->source === 'metar') {
                return $snapshot->fetchTime;
            }
        }
        return null;
    }
    
    /**
     * Generate an empty result
     * 
     * @return array
     */
    private function emptyResult(): array {
        $result = [];
        
        foreach (AggregationPolicy::ALL_FIELDS as $field) {
            $result[$field] = null;
        }
        
        $result['gust_factor'] = null;
        $result['_field_obs_time_map'] = [];
        $result['_field_source_map'] = [];
        $result['last_updated'] = $this->now;
        $result['last_updated_iso'] = date('c', $this->now);
        
        return $result;
    }
}

