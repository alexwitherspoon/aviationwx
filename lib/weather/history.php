<?php
/**
 * Weather History Storage
 * 
 * Maintains a 24-hour rolling history of weather observations per airport.
 * Observations are appended to a cache file and pruned when older than
 * the retention period.
 */

require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/../public-api/config.php';
require_once __DIR__ . '/../logger.php';
require_once __DIR__ . '/../cache-paths.php';

// Maximum observations to store (safety limit)
define('WEATHER_HISTORY_MAX_OBSERVATIONS', 1500);

/**
 * Append a weather observation to history
 * 
 * Only stores if obs_time differs from the last entry to avoid duplicates.
 * 
 * @param string $airportId Airport ID
 * @param array $weather Weather data from cache
 * @param array|null $fieldSourceMap Optional field-to-source mapping (if not in $weather)
 * @return bool True if observation was appended
 */
function appendWeatherHistory(string $airportId, array $weather, ?array $fieldSourceMap = null): bool
{
    if (!isPublicApiWeatherHistoryEnabled()) {
        return false;
    }
    
    // Get observation time - use primary source observation time
    $obsTime = $weather['obs_time_primary'] ?? $weather['last_updated'] ?? null;
    if ($obsTime === null) {
        return false;
    }
    
    $history = loadWeatherHistory($airportId);
    
    // Check if this observation is new (different from last)
    if (!empty($history['observations'])) {
        $lastObs = end($history['observations']);
        if (isset($lastObs['obs_time']) && $lastObs['obs_time'] === $obsTime) {
            // Same observation time, skip
            return false;
        }
    }
    
    // Get field source map (from parameter or weather data)
    $sourceMap = $fieldSourceMap ?? $weather['_field_source_map'] ?? [];
    
    // Extract all unique sources used in this observation
    $sourcesUsed = array_values(array_unique(array_filter($sourceMap)));
    sort($sourcesUsed); // Sort for consistency
    
    // Create observation record with minimal but complete data
    $observation = [
        'obs_time' => $obsTime,
        'obs_time_iso' => gmdate('c', $obsTime),
        'temperature' => $weather['temperature'] ?? null,
        'temperature_f' => $weather['temperature_f'] ?? null,
        'dewpoint' => $weather['dewpoint'] ?? null,
        'humidity' => $weather['humidity'] ?? null,
        'wind_speed' => $weather['wind_speed'] ?? null,
        'wind_direction' => $weather['wind_direction'] ?? null,
        'gust_speed' => $weather['gust_speed'] ?? null,
        'pressure' => $weather['pressure'] ?? null,
        'visibility' => $weather['visibility'] ?? null,
        'ceiling' => $weather['ceiling'] ?? null,
        'cloud_cover' => $weather['cloud_cover'] ?? null,
        'flight_category' => $weather['flight_category'] ?? null,
        'density_altitude' => $weather['density_altitude'] ?? null,
        'pressure_altitude' => $weather['pressure_altitude'] ?? null,
    ];
    
    // Add source attribution
    if (!empty($sourceMap)) {
        $observation['field_sources'] = $sourceMap;
    }
    if (!empty($sourcesUsed)) {
        $observation['sources'] = $sourcesUsed;
    }
    
    // Append observation
    $history['observations'][] = $observation;
    $history['updated_at'] = time();
    
    // Prune old observations
    $retentionHours = getPublicApiWeatherHistoryRetentionHours();
    $cutoffTime = time() - ($retentionHours * 3600);
    
    $history['observations'] = array_values(array_filter(
        $history['observations'],
        function ($obs) use ($cutoffTime) {
            return isset($obs['obs_time']) && $obs['obs_time'] >= $cutoffTime;
        }
    ));
    
    // Safety limit
    if (count($history['observations']) > WEATHER_HISTORY_MAX_OBSERVATIONS) {
        $history['observations'] = array_slice(
            $history['observations'],
            -WEATHER_HISTORY_MAX_OBSERVATIONS
        );
    }
    
    // Save history
    return saveWeatherHistory($airportId, $history);
}

/**
 * Get weather history for an airport
 * 
 * @param string $airportId Airport ID
 * @param int|null $startTime Optional start timestamp filter
 * @param int|null $endTime Optional end timestamp filter
 * @param string $resolution Resolution: 'all', 'hourly', '15min'
 * @return array History data with observations array
 */
function getWeatherHistory(
    string $airportId,
    ?int $startTime = null,
    ?int $endTime = null,
    string $resolution = 'all'
): array {
    $history = loadWeatherHistory($airportId);
    $observations = $history['observations'];
    
    // Apply time filters
    if ($startTime !== null) {
        $observations = array_filter($observations, function ($obs) use ($startTime) {
            return isset($obs['obs_time']) && $obs['obs_time'] >= $startTime;
        });
    }
    
    if ($endTime !== null) {
        $observations = array_filter($observations, function ($obs) use ($endTime) {
            return isset($obs['obs_time']) && $obs['obs_time'] <= $endTime;
        });
    }
    
    // Re-index array
    $observations = array_values($observations);
    
    // Apply resolution downsampling
    if ($resolution !== 'all' && !empty($observations)) {
        $observations = downsampleWeatherObservations($observations, $resolution);
    }
    
    return [
        'airport_id' => $airportId,
        'start_time' => !empty($observations) ? $observations[0]['obs_time'] : null,
        'end_time' => !empty($observations) ? end($observations)['obs_time'] : null,
        'observation_count' => count($observations),
        'resolution' => $resolution,
        'observations' => $observations,
    ];
}

/**
 * Downsample observations to specified resolution
 * 
 * @param array $observations Array of observations
 * @param string $resolution 'hourly' or '15min'
 * @return array Downsampled observations
 */
function downsampleWeatherObservations(array $observations, string $resolution): array
{
    $intervalSeconds = match ($resolution) {
        'hourly' => 3600,
        '15min' => 900,
        default => 0,
    };
    
    if ($intervalSeconds === 0) {
        return $observations;
    }
    
    $downsampled = [];
    $lastBucket = null;
    
    foreach ($observations as $obs) {
        if (!isset($obs['obs_time'])) {
            continue;
        }
        
        // Calculate which bucket this observation belongs to
        $bucket = floor($obs['obs_time'] / $intervalSeconds);
        
        if ($bucket !== $lastBucket) {
            $downsampled[] = $obs;
            $lastBucket = $bucket;
        }
    }
    
    return $downsampled;
}

/**
 * Get the file path for weather history cache
 * 
 * @param string $airportId Airport ID
 * @return string File path
 */
function getWeatherHistoryFilePath(string $airportId): string
{
    return getWeatherHistoryCachePath($airportId);
}

/**
 * Load weather history from cache file
 * 
 * @param string $airportId Airport ID
 * @return array History data with observations array
 */
function loadWeatherHistory(string $airportId): array
{
    $file = getWeatherHistoryFilePath($airportId);
    
    $default = [
        'airport_id' => $airportId,
        'updated_at' => null,
        'retention_hours' => getPublicApiWeatherHistoryRetentionHours(),
        'observations' => [],
    ];
    
    if (!file_exists($file)) {
        return $default;
    }
    
    $content = @file_get_contents($file);
    if ($content === false) {
        return $default;
    }
    
    $data = json_decode($content, true);
    if (!is_array($data)) {
        return $default;
    }
    
    return array_merge($default, $data);
}

/**
 * Save weather history to cache file
 * 
 * @param string $airportId Airport ID
 * @param array $history History data to save
 * @return bool True on success
 */
function saveWeatherHistory(string $airportId, array $history): bool
{
    $file = getWeatherHistoryFilePath($airportId);
    
    if (!ensureCacheDir(CACHE_WEATHER_HISTORY_DIR)) {
        aviationwx_log('error', 'weather history: failed to create cache dir', [
            'dir' => CACHE_WEATHER_HISTORY_DIR,
        ], 'app');
        return false;
    }
    
    $result = @file_put_contents($file, json_encode($history), LOCK_EX);
    
    if ($result === false) {
        aviationwx_log('error', 'weather history: failed to write file', [
            'file' => $file,
            'airport' => $airportId,
        ], 'app');
        return false;
    }
    
    return true;
}

/**
 * Compute daily temp extremes and peak gust from weather history
 *
 * Fallback when daily tracking has no data for today. Filters observations
 * by airport's local date and computes min/max.
 *
 * @param string $airportId Airport ID
 * @param string $dateKey Date key in Y-m-d format (airport's local "today")
 * @param string $timezone Airport timezone (e.g., 'America/Los_Angeles')
 * @return array{
 *   temp_high: float|null,
 *   temp_low: float|null,
 *   temp_high_ts: int|null,
 *   temp_low_ts: int|null,
 *   peak_gust: float|null,
 *   peak_gust_ts: int|null
 * }
 */
function computeDailyExtremesFromHistory(string $airportId, string $dateKey, string $timezone): array
{
    $result = [
        'temp_high' => null,
        'temp_low' => null,
        'temp_high_ts' => null,
        'temp_low_ts' => null,
        'peak_gust' => null,
        'peak_gust_ts' => null,
    ];

    if (!isPublicApiWeatherHistoryEnabled()) {
        return $result;
    }

    $history = loadWeatherHistory($airportId);
    $observations = $history['observations'] ?? [];
    if (empty($observations)) {
        return $result;
    }

    $tz = new DateTimeZone($timezone);
    $todayObs = [];
    foreach ($observations as $obs) {
        if (!isset($obs['obs_time'])) {
            continue;
        }
        $dt = new DateTime('@' . $obs['obs_time']);
        $dt->setTimezone($tz);
        if ($dt->format('Y-m-d') === $dateKey) {
            $todayObs[] = $obs;
        }
    }

    if (empty($todayObs)) {
        return $result;
    }

    $tempHigh = null;
    $tempHighTs = null;
    $tempLow = null;
    $tempLowTs = null;
    $peakGust = null;
    $peakGustTs = null;

    foreach ($todayObs as $obs) {
        $ts = $obs['obs_time'] ?? null;
        if ($ts === null) {
            continue;
        }

        if (isset($obs['temperature']) && is_numeric($obs['temperature'])) {
            $t = (float)$obs['temperature'];
            if ($tempHigh === null || $t > $tempHigh) {
                $tempHigh = $t;
                $tempHighTs = $ts;
            }
            if ($tempLow === null || $t < $tempLow) {
                $tempLow = $t;
                $tempLowTs = $ts;
            }
        }

        if (isset($obs['gust_speed']) && is_numeric($obs['gust_speed'])) {
            $g = (float)$obs['gust_speed'];
            if ($peakGust === null || $g > $peakGust) {
                $peakGust = $g;
                $peakGustTs = $ts;
            }
        }
    }

    $result['temp_high'] = $tempHigh;
    $result['temp_low'] = $tempLow;
    $result['temp_high_ts'] = $tempHighTs;
    $result['temp_low_ts'] = $tempLowTs;
    $result['peak_gust'] = $peakGust;
    $result['peak_gust_ts'] = $peakGustTs;

    return $result;
}

/**
 * Compute last-hour wind rose data for wind rose petal visualization
 *
 * Returns 16 sectors (N, NNE, NE, ENE, E, ESE, SE, SSE, S, SSW, SW, WSW, W, WNW, NW, NNW)
 * with average wind speed when wind was from that direction. Wind direction is "from" (meteorological).
 * Petals extend in the direction wind is coming from.
 *
 * @param string $airportId Airport ID
 * @return array|null Array of 16 floats (sector 0=N, 1=NNE, ... 15=NNW), or null if unavailable
 */
function computeLastHourWindRose(string $airportId): ?array
{
    if (!isPublicApiWeatherHistoryEnabled()) {
        return null;
    }

    $history = loadWeatherHistory($airportId);
    $observations = $history['observations'] ?? [];
    if (empty($observations)) {
        return null;
    }

    $cutoff = time() - 3600; // Last hour
    $lastHourObs = array_filter($observations, function ($obs) use ($cutoff) {
        return isset($obs['obs_time']) && $obs['obs_time'] >= $cutoff;
    });
    if (empty($lastHourObs)) {
        return null;
    }

    // 16 sectors: N=0, NNE=1, NE=2, ENE=3, E=4, ESE=5, SE=6, SSE=7, S=8, SSW=9, SW=10, WSW=11, W=12, WNW=13, NW=14, NNW=15
    // Sector i is centered at i*22.5°. Assign obs to sector by round(dir/22.5) % 16
    $sectorSums = array_fill(0, 16, 0.0);
    $sectorCounts = array_fill(0, 16, 0);

    foreach ($lastHourObs as $obs) {
        $dir = $obs['wind_direction'] ?? null;
        $speed = $obs['wind_speed'] ?? null;
        if ($dir === null || $speed === null || !is_numeric($dir) || !is_numeric($speed)) {
            continue;
        }
        $dir = (float) $dir;
        $speed = (float) $speed;
        if ($speed < 0) {
            continue;
        }
        if ($speed < CALM_WIND_THRESHOLD_KTS) {
            continue;
        }
        // Normalize direction to 0-360 (handles negative, >360, malformed)
        $dir = fmod($dir + 360.0, 360.0);
        if ($dir < 0) {
            $dir += 360.0;
        }
        $sector = (int) round($dir / 22.5) % 16;
        if ($sector < 0 || $sector >= 16) {
            continue;
        }
        $sectorSums[$sector] += $speed;
        $sectorCounts[$sector]++;
    }

    $validObsCount = array_sum($sectorCounts);
    if ($validObsCount <= 1) {
        return null;
    }

    $petals = [];
    $maxAvg = 0.0;
    for ($i = 0; $i < 16; $i++) {
        $avg = $sectorCounts[$i] > 0 ? $sectorSums[$i] / $sectorCounts[$i] : 0.0;
        $petals[] = round($avg, 1);
        if ($avg > $maxAvg) {
            $maxAvg = $avg;
        }
    }

    if ($maxAvg <= 0) {
        return null;
    }

    return $petals;
}

/**
 * Prune old observations from weather history
 * 
 * @param string $airportId Airport ID
 * @return int Number of observations pruned
 */
function pruneWeatherHistory(string $airportId): int
{
    $history = loadWeatherHistory($airportId);
    $originalCount = count($history['observations']);
    
    $retentionHours = getPublicApiWeatherHistoryRetentionHours();
    $cutoffTime = time() - ($retentionHours * 3600);
    
    $history['observations'] = array_values(array_filter(
        $history['observations'],
        function ($obs) use ($cutoffTime) {
            return isset($obs['obs_time']) && $obs['obs_time'] >= $cutoffTime;
        }
    ));
    
    $pruned = $originalCount - count($history['observations']);
    
    if ($pruned > 0) {
        $history['updated_at'] = time();
        saveWeatherHistory($airportId, $history);
    }
    
    return $pruned;
}

