<?php
/**
 * Weather History Storage
 * 
 * Maintains a 24-hour rolling history of weather observations per airport.
 * Observations are appended to a cache file and pruned when older than
 * the retention period.
 */

require_once __DIR__ . '/../public-api/config.php';
require_once __DIR__ . '/../logger.php';

// Maximum observations to store (safety limit)
define('WEATHER_HISTORY_MAX_OBSERVATIONS', 1500);

/**
 * Append a weather observation to history
 * 
 * Only stores if obs_time differs from the last entry to avoid duplicates.
 * 
 * @param string $airportId Airport ID
 * @param array $weather Weather data from cache
 * @return bool True if observation was appended
 */
function appendWeatherHistory(string $airportId, array $weather): bool
{
    if (!isPublicApiWeatherHistoryEnabled()) {
        return false;
    }
    
    // Get observation time - use primary source observation time
    $obsTime = $weather['obs_time_primary'] ?? $weather['last_updated'] ?? null;
    if ($obsTime === null) {
        return false;
    }
    
    $historyFile = getWeatherHistoryFilePath($airportId);
    $history = loadWeatherHistory($airportId);
    
    // Check if this observation is new (different from last)
    if (!empty($history['observations'])) {
        $lastObs = end($history['observations']);
        if (isset($lastObs['obs_time']) && $lastObs['obs_time'] === $obsTime) {
            // Same observation time, skip
            return false;
        }
    }
    
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
    $cacheDir = __DIR__ . '/../../cache';
    return $cacheDir . '/weather_history_' . $airportId . '.json';
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
    $cacheDir = dirname($file);
    
    if (!is_dir($cacheDir)) {
        if (!@mkdir($cacheDir, 0755, true)) {
            aviationwx_log('error', 'weather history: failed to create cache dir', [
                'dir' => $cacheDir,
            ], 'app');
            return false;
        }
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

