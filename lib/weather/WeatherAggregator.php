<?php
/**
 * Weather Aggregator - Combines weather data from multiple sources
 * 
 * Takes snapshots from multiple sources (Tempest, Ambient, METAR, NWS, etc.)
 * and produces a single aggregated weather observation.
 * 
 * Key principles:
 * - Freshest data wins: For each field, select the most recent non-stale observation
 * - Wind group must come from a single source (speed + direction together)
 * - All fields use freshness-based selection (no source priority)
 * - Pure function: no side effects, no cache reading
 * 
 * This approach allows multiple sources to contribute their freshest data,
 * resulting in the most up-to-date composite weather picture.
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
     * Local vs neighboring METAR: For LOCAL_FIELDS (wind, temp, humidity, etc.),
     * local sources (on-site sensors or METAR from same station) always override
     * neighboring METAR when both have valid data. Neighboring METAR may fill in
     * missing fields (e.g. visibility, ceiling) but never overrides local measurements.
     *
     * During aggregation, we accept any data < 3 hours old (failclosed threshold).
     * Staleness indicators (warning/error) are applied later during display.
     *
     * @param array<WeatherSnapshot> $snapshots Snapshots in preference order
     * @param array<string, int>|null $maxAges Optional per-source max age overrides (source => seconds)
     * @param string|null $localAirportIcao Airport ICAO (e.g. KSPB) for local vs neighboring METAR detection
     * @return array Aggregated weather data with attribution
     */
    public function aggregate(array $snapshots, ?array $maxAges = null, ?string $localAirportIcao = null): array {
        if (empty($snapshots)) {
            return $this->emptyResult();
        }

        $result = [];
        $fieldObsTimeMap = [];
        $fieldSourceMap = [];
        $fieldStationMap = [];

        // ═══════════════════════════════════════════════════════════════════
        // STEP 1: Select wind group (must be complete from single source)
        // ═══════════════════════════════════════════════════════════════════

        $windResult = $this->selectWindGroup($snapshots, $maxAges, $localAirportIcao);
        $result = array_merge($result, $windResult['fields']);
        $fieldObsTimeMap = array_merge($fieldObsTimeMap, $windResult['obs_times']);
        $fieldSourceMap = array_merge($fieldSourceMap, $windResult['sources']);
        $fieldStationMap = array_merge($fieldStationMap, $windResult['stations']);
        
        // ═══════════════════════════════════════════════════════════════════
        // STEP 2: Select aviation fields (visibility, ceiling, cloud_cover)
        // Uses freshness-based selection. METAR typically provides these fields.
        // ═══════════════════════════════════════════════════════════════════
        
        foreach (AggregationPolicy::METAR_PREFERRED_FIELDS as $fieldName) {
            $selection = $this->selectMetarPreferredField($fieldName, $snapshots, $maxAges, $localAirportIcao);
            $reading = $selection['reading'];
            $result[$fieldName] = $reading->value;
            if ($reading->observationTime !== null) {
                $fieldObsTimeMap[$fieldName] = $reading->observationTime;
            }
            if ($reading->source !== null) {
                $fieldSourceMap[$fieldName] = $reading->source;
            }
            if ($selection['station_id'] !== null) {
                $fieldStationMap[$fieldName] = $selection['station_id'];
            }
            if ($fieldName === 'visibility') {
                $result['visibility_greater_than'] = $reading->greaterThan;
            }
        }
        
        // ═══════════════════════════════════════════════════════════════════
        // STEP 3: Select other fields (temperature, humidity, pressure, etc.)
        // All use freshness-based selection - freshest non-stale value wins.
        // ═══════════════════════════════════════════════════════════════════
        
        foreach (AggregationPolicy::INDEPENDENT_FIELDS as $fieldName) {
            $selection = $this->selectBestField($fieldName, $snapshots, $maxAges, $localAirportIcao);
            $reading = $selection['reading'];
            $result[$fieldName] = $reading->value;
            if ($reading->observationTime !== null) {
                $fieldObsTimeMap[$fieldName] = $reading->observationTime;
            }
            if ($reading->source !== null) {
                $fieldSourceMap[$fieldName] = $reading->source;
            }
            if ($selection['station_id'] !== null) {
                $fieldStationMap[$fieldName] = $selection['station_id'];
            }
        }
        
        // ═══════════════════════════════════════════════════════════════════
        // STEP 4: Add metadata
        // ═══════════════════════════════════════════════════════════════════
        
        $result['_field_obs_time_map'] = $fieldObsTimeMap;
        $result['_field_source_map'] = $fieldSourceMap;
        $result['_field_station_map'] = $fieldStationMap;
        
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
     * Check if snapshot is from a local source (on-site or same-station METAR).
     *
     * @param WeatherSnapshot $snapshot
     * @param string|null $localAirportIcao Airport ICAO (e.g. KSPB)
     * @return bool True if local; when localAirportIcao is null, all sources are treated as local
     */
    private function isLocalSource(WeatherSnapshot $snapshot, ?string $localAirportIcao): bool {
        if ($localAirportIcao === null || $localAirportIcao === '') {
            return true;
        }
        if ($snapshot->source !== 'metar') {
            return true;
        }
        if ($snapshot->metarStationId === null) {
            return true;
        }
        return strcasecmp($snapshot->metarStationId, $localAirportIcao) === 0;
    }

    /**
     * Select wind group from the source with the freshest complete data
     *
     * Wind must come as a complete group (speed + direction) from a single source.
     * We don't mix wind speed from one source with direction from another.
     * Local sources override neighboring METAR when both have valid wind.
     *
     * @param array<WeatherSnapshot> $snapshots
     * @param array<string, int>|null $maxAges Optional per-source max age overrides
     * @param string|null $localAirportIcao Airport ICAO for local vs neighboring detection
     * @return array{fields: array, obs_times: array, sources: array}
     */
    private function selectWindGroup(array $snapshots, ?array $maxAges = null, ?string $localAirportIcao = null): array {
        $bestWind = null;
        $bestObsTime = 0;
        $bestSource = null;
        $bestSnapshot = null;
        $bestIsLocal = false;

        foreach ($snapshots as $snapshot) {
            if (!$snapshot->hasCompleteWind()) {
                continue;
            }

            $maxAge = $this->getMaxAgeForSource($snapshot->source, $maxAges);
            if ($snapshot->wind->isStale($maxAge, $this->now)) {
                continue;
            }

            $obsTime = $snapshot->wind->speed->observationTime ?? 0;
            $isLocal = $this->isLocalSource($snapshot, $localAirportIcao);

            // Prefer local over neighboring METAR when both have valid wind
            if ($bestWind !== null && $bestIsLocal && !$isLocal) {
                continue;
            }
            if ($bestWind !== null && !$bestIsLocal && $isLocal) {
                $bestObsTime = $obsTime;
                $bestWind = $snapshot->wind;
                $bestSource = $snapshot->source;
                $bestSnapshot = $snapshot;
                $bestIsLocal = $isLocal;
                continue;
            }

            if ($obsTime > $bestObsTime) {
                $bestObsTime = $obsTime;
                $bestWind = $snapshot->wind;
                $bestSource = $snapshot->source;
                $bestSnapshot = $snapshot;
                $bestIsLocal = $isLocal;
            }
        }
        
        // No complete wind group found - return nulls
        if ($bestWind === null) {
            return [
                'fields' => [
                    'wind_speed' => null,
                    'wind_direction' => null,
                    'gust_speed' => null,
                    'gust_factor' => null,
                ],
                'obs_times' => [],
                'sources' => [],
                'stations' => [],
            ];
        }
        
        // Build result from freshest wind group
        $windData = $bestWind->toArray();
        $obsTimeMap = $bestWind->getObservationTimeMap();
        
        $sourceMap = [];
        $stationMap = [];
        $stationId = $bestSnapshot?->getStationIdForAttribution();
        foreach (array_keys($windData) as $field) {
            if ($windData[$field] !== null) {
                $sourceMap[$field] = $bestSource;
                if ($stationId !== null) {
                    $stationMap[$field] = $stationId;
                }
            }
        }
        
        return [
            'fields' => $windData,
            'obs_times' => $obsTimeMap,
            'sources' => $sourceMap,
            'stations' => $stationMap,
        ];
    }
    
    /**
     * Select a field typically provided by METAR (visibility, ceiling, cloud_cover)
     *
     * Local sources override neighboring METAR when both have valid data.
     * Neighboring METAR may fill in when local has no data.
     *
     * @param string $fieldName
     * @param array<WeatherSnapshot> $snapshots
     * @param array<string, int>|null $maxAges Optional per-source max age overrides
     * @param string|null $localAirportIcao Airport ICAO for local vs neighboring detection
     * @return array{reading: WeatherReading, station_id: string|null}
     */
    private function selectMetarPreferredField(string $fieldName, array $snapshots, ?array $maxAges = null, ?string $localAirportIcao = null): array {
        return $this->selectBestField($fieldName, $snapshots, $maxAges, $localAirportIcao);
    }
    
    /**
     * Select the best value for a field based on freshness and local vs neighboring.
     *
     * Local sources override neighboring METAR when both have valid data.
     * When both are local or both neighboring, freshest wins.
     *
     * @param string $fieldName
     * @param array<WeatherSnapshot> $snapshots
     * @param array<string, int>|null $maxAges Optional per-source max age overrides
     * @param string|null $localAirportIcao Airport ICAO for local vs neighboring detection
     * @return array{reading: WeatherReading, station_id: string|null}
     */
    private function selectBestField(string $fieldName, array $snapshots, ?array $maxAges = null, ?string $localAirportIcao = null): array {
        $bestReading = null;
        $bestSnapshot = null;
        $bestObsTime = 0;
        $bestIsLocal = false;

        foreach ($snapshots as $snapshot) {
            $reading = $snapshot->getField($fieldName);
            if ($reading === null || !$reading->hasValue()) {
                continue;
            }

            $maxAge = $this->getMaxAgeForSource($snapshot->source, $maxAges);
            if ($reading->isStale($maxAge, $this->now)) {
                continue;
            }

            $obsTime = $reading->observationTime ?? 0;
            $isLocal = $this->isLocalSource($snapshot, $localAirportIcao);

            if ($bestReading !== null && $bestIsLocal && !$isLocal) {
                continue;
            }
            if ($bestReading !== null && !$bestIsLocal && $isLocal) {
                $bestObsTime = $obsTime;
                $bestReading = $reading->withSource($snapshot->source);
                $bestSnapshot = $snapshot;
                $bestIsLocal = $isLocal;
                continue;
            }

            if ($obsTime > $bestObsTime) {
                $bestObsTime = $obsTime;
                $bestReading = $reading->withSource($snapshot->source);
                $bestSnapshot = $snapshot;
                $bestIsLocal = $isLocal;
            }
        }

        $reading = $bestReading ?? WeatherReading::null();
        $stationId = $bestSnapshot?->getStationIdForAttribution();
        return ['reading' => $reading, 'station_id' => $stationId];
    }
    
    /**
     * Get max age for a source (with override support)
     * 
     * @param string $source Source identifier
     * @param array<string, int>|null $maxAges Optional per-source overrides
     * @return int Max age in seconds
     */
    private function getMaxAgeForSource(string $source, ?array $maxAges = null): int {
        // Check for explicit override
        if ($maxAges !== null && isset($maxAges[$source])) {
            return $maxAges[$source];
        }
        
        // Use default thresholds
        $isMetar = $source === 'metar';
        return $isMetar 
            ? AggregationPolicy::getMetarStaleFailclosedSeconds() 
            : AggregationPolicy::getStaleFailclosedSeconds();
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
        $result['_field_station_map'] = [];
        $result['last_updated'] = $this->now;
        $result['last_updated_iso'] = date('c', $this->now);
        
        return $result;
    }
}

