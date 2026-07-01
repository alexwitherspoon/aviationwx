<?php
/**
 * Local vs supplemental remote weather helpers
 *
 * Co-located sources observe the airport; supplemental remote sources (e.g. KUAO for 7S9)
 * may fill gaps when on-field infrastructure is healthy but must not clear outage detection.
 *
 * @see docs/DATA_FLOW.md#supplemental-remote-weather-policy
 */

/**
 * True when the airport has on-field infrastructure beyond supplemental remote METAR.
 *
 * On-field means non-METAR weather sources and/or webcams configured for the airport.
 *
 * @param array $airport Airport configuration
 * @return bool
 */
function airportHasOnFieldInfrastructure(array $airport): bool
{
    if (isset($airport['webcams']) && is_array($airport['webcams']) && count($airport['webcams']) > 0) {
        return true;
    }

    if (!isset($airport['weather_sources']) || !is_array($airport['weather_sources'])) {
        return false;
    }

    foreach ($airport['weather_sources'] as $source) {
        if (!empty($source['type']) && $source['type'] !== 'metar') {
            return true;
        }
    }

    return false;
}

/**
 * METAR station_id from the first configured METAR weather source.
 *
 * @param array $airport Airport configuration
 * @return string|null Uppercase station id
 */
function getConfiguredMetarStationId(array $airport): ?string
{
    if (!isset($airport['weather_sources']) || !is_array($airport['weather_sources'])) {
        return null;
    }

    foreach ($airport['weather_sources'] as $source) {
        if (($source['type'] ?? '') === 'metar' && !empty($source['station_id'])) {
            return strtoupper(trim((string) $source['station_id']));
        }
    }

    return null;
}

/**
 * Active METAR station from cached weather attribution, with config fallback.
 *
 * @param array|null $weatherData Cached or aggregated weather payload
 * @param array|null $airport Airport configuration for configured station fallback
 * @return string|null Uppercase station id
 */
function getActiveMetarStationId(?array $weatherData, ?array $airport = null): ?string
{
    if (is_array($weatherData) && isset($weatherData['_field_station_map']) && is_array($weatherData['_field_station_map'])) {
        foreach (['visibility', 'ceiling', 'cloud_cover', 'wind_speed', 'temperature'] as $field) {
            if (!empty($weatherData['_field_station_map'][$field])) {
                return strtoupper(trim((string) $weatherData['_field_station_map'][$field]));
            }
        }
    }

    if ($airport !== null) {
        return getConfiguredMetarStationId($airport);
    }

    return null;
}

/**
 * True when the METAR station observes the airport (airport ICAO matches station).
 *
 * @param string|null $metarStationId Active or configured METAR station
 * @param array $airport Airport configuration
 * @return bool
 */
function isCoLocatedMetarStation(?string $metarStationId, array $airport): bool
{
    $icao = isset($airport['icao']) ? trim((string) $airport['icao']) : '';
    if ($icao === '' || $metarStationId === null || $metarStationId === '') {
        return false;
    }

    return strcasecmp($metarStationId, $icao) === 0;
}

/**
 * True when configured METAR is supplemental remote for outage and fail-closed policy.
 *
 * METAR-only airports treat METAR as primary. When on-field infrastructure exists,
 * only co-located METAR counts toward site health.
 *
 * @param array $airport Airport configuration
 * @param array|null $weatherData Cached weather for active station attribution
 * @return bool
 */
function isSupplementalMetarForOutage(array $airport, ?array $weatherData = null): bool
{
    if (!airportHasOnFieldInfrastructure($airport)) {
        return false;
    }

    if (getConfiguredMetarStationId($airport) === null) {
        return false;
    }

    $activeStation = getActiveMetarStationId($weatherData, $airport);

    return !isCoLocatedMetarStation($activeStation, $airport);
}

/**
 * Newest timestamp from on-field sources only (primary, webcams), for outage banner anchoring.
 *
 * @param array $sources Outage source status map from checkDataOutageStatus
 * @param array $sourceTimestamps Timestamps from getSourceTimestamps()
 * @return int Unix timestamp, or 0 when none available
 */
function newestOnFieldOutageTimestamp(array $sources, array $sourceTimestamps): int
{
    $newest = 0;

    if (isset($sources['primary']) && $sources['primary']['timestamp'] > 0) {
        $newest = max($newest, (int) $sources['primary']['timestamp']);
    }

    if (isset($sources['backup']) && $sources['backup']['timestamp'] > 0) {
        $newest = max($newest, (int) $sources['backup']['timestamp']);
    }

    $backupTimestamp = (int) ($sourceTimestamps['backup']['timestamp'] ?? 0);
    if ($backupTimestamp > 0) {
        $newest = max($newest, $backupTimestamp);
    }

    if (isset($sources['webcams'])) {
        $webcamTs = (int) ($sourceTimestamps['webcams']['newest_timestamp'] ?? 0);
        if ($webcamTs > 0) {
            $newest = max($newest, $webcamTs);
        }
    }

    return $newest;
}

/**
 * Null supplemental remote weather display fields during on-field outage (fail-closed).
 *
 * @param array $data Weather payload (modified in place)
 * @return void
 * @see \AviationWX\Weather\AggregationPolicy::ALL_FIELDS
 * @see \AviationWX\Weather\AggregationPolicy::SUPPLEMENTAL_OUTAGE_DISPLAY_EXTRAS
 */
function nullSupplementalRemoteWeatherDisplayFields(array &$data): void
{
    require_once __DIR__ . '/AggregationPolicy.php';
    require_once __DIR__ . '/calculator.php';

    $policy = \AviationWX\Weather\AggregationPolicy::class;

    foreach ($policy::ALL_FIELDS as $field) {
        if (array_key_exists($field, $data)) {
            $data[$field] = null;
        }
    }

    foreach ($policy::CALCULATED_FIELDS as $field) {
        if (array_key_exists($field, $data)) {
            $data[$field] = null;
        }
    }

    foreach ($policy::SUPPLEMENTAL_OUTAGE_DISPLAY_EXTRAS as $field) {
        if (array_key_exists($field, $data)) {
            $data[$field] = null;
        }
    }

    $data['visibility_greater_than'] = false;

    calculateAndSetFlightCategory($data);

    anchorSupplementalOutageDisplayTimestamps($data);
}

/**
 * Anchor display timestamps to on-field infrastructure during supplemental outage fail-closed.
 *
 * Fresh supplemental METAR metadata must not drive the airport "Last updated" line when fields
 * are hidden because on-field sensors and webcams are down.
 *
 * @param array $data Weather payload (modified in place)
 * @return void
 */
function anchorSupplementalOutageDisplayTimestamps(array &$data): void
{
    require_once __DIR__ . '/aggregate-timestamps.php';

    $data['obs_time_metar'] = null;
    $data['last_updated_metar'] = null;

    $sourceMap = $data['_field_source_map'] ?? null;
    if (isset($data['_field_obs_time_map']) && is_array($data['_field_obs_time_map'])) {
        if (!is_array($sourceMap)) {
            $data['_field_obs_time_map'] = [];
        } else {
            foreach (array_keys($data['_field_obs_time_map']) as $field) {
                $source = $sourceMap[$field] ?? null;
                if ($source === null || $source === 'metar') {
                    unset($data['_field_obs_time_map'][$field]);
                }
            }
        }
    }

    $onFieldCandidates = [];
    foreach (['obs_time_primary', 'last_updated_primary', 'obs_time_backup', 'last_updated_backup'] as $key) {
        if (!array_key_exists($key, $data)) {
            continue;
        }
        $ts = weather_positive_aggregate_timestamp($data[$key]);
        if ($ts !== null) {
            $onFieldCandidates[] = $ts;
        }
    }

    if (isset($data['_field_obs_time_map']) && is_array($data['_field_obs_time_map'])) {
        foreach ($data['_field_obs_time_map'] as $field => $value) {
            $ts = weather_positive_aggregate_timestamp($value);
            if ($ts !== null) {
                $onFieldCandidates[] = $ts;
            }
        }
    }

    if ($onFieldCandidates === []) {
        $data['last_updated'] = null;
        unset($data['last_updated_iso']);

        return;
    }

    $onFieldTs = max($onFieldCandidates);
    $data['last_updated'] = $onFieldTs;
    $data['last_updated_iso'] = date('c', $onFieldTs);
}

/**
 * Build dashboard "Weather data at this airport from" attribution entries.
 *
 * Credits only sources with non-null displayed fields after fail-closed processing.
 * Supplemental remote METAR metadata stripped during outage does not appear.
 *
 * @param array $airport Airport configuration
 * @param array|null $weatherData Weather payload (post applyFailclosedStaleness)
 * @return array<int, array{name: string, url: ?string}>
 */
function buildDashboardWeatherSourceAttribution(array $airport, ?array $weatherData): array
{
    if ($weatherData === null || !is_array($weatherData)) {
        return [];
    }

    require_once __DIR__ . '/utils.php';

    $weatherSources = [];
    $addedKeys = [];
    $fieldSourceMap = $weatherData['_field_source_map'] ?? null;
    $fieldStationMap = $weatherData['_field_station_map'] ?? null;
    if (!is_array($fieldSourceMap)) {
        $fieldSourceMap = [];
    }
    if (!is_array($fieldStationMap)) {
        $fieldStationMap = [];
    }

    $addSource = static function (string $sourceType, ?string $stationId = null) use (
        &$weatherSources,
        &$addedKeys
    ): void {
        $key = $sourceType . ':' . ($stationId ?? '');
        if (in_array($key, $addedKeys, true)) {
            return;
        }
        $sourceInfo = getWeatherSourceInfo($sourceType);
        if ($sourceInfo === null) {
            return;
        }
        $weatherSources[] = [
            'name' => getWeatherSourceDisplayName($sourceType, $stationId),
            'url' => $sourceInfo['url'],
        ];
        $addedKeys[] = $key;
    };

    foreach ($fieldSourceMap as $field => $sourceType) {
        if ($sourceType === 'metar') {
            continue;
        }
        if (!array_key_exists($field, $weatherData) || $weatherData[$field] === null) {
            continue;
        }
        $addSource($sourceType, $fieldStationMap[$field] ?? null);
    }

    $hasMetarMetadata = (($weatherData['obs_time_metar'] ?? 0) > 0)
        || (($weatherData['last_updated_metar'] ?? 0) > 0);
    $metarHasDisplayedField = false;
    foreach ($fieldSourceMap as $field => $src) {
        if ($src !== 'metar') {
            continue;
        }
        if (array_key_exists($field, $weatherData) && $weatherData[$field] !== null) {
            $metarHasDisplayedField = true;
            break;
        }
    }
    if ($hasMetarMetadata && $metarHasDisplayedField) {
        $metarStationId = null;
        foreach ($fieldSourceMap as $field => $src) {
            if ($src === 'metar') {
                $metarStationId = $fieldStationMap[$field] ?? null;
                break;
            }
        }
        $addSource('metar', $metarStationId);
    }

    return $weatherSources;
}
