<?php
/**
 * Daily Weather Tracking Functions
 * 
 * Tracks daily weather extremes (peak gust, temperature highs/lows) for airports.
 * Uses airport's local timezone to determine "today" for proper daily resets.
 */

require_once __DIR__ . '/../logger.php';
require_once __DIR__ . '/utils.php';

/**
 * Update today's peak gust for an airport
 * 
 * Tracks the highest gust speed for the current day (based on airport's local timezone).
 * Uses observation timestamp to record when the peak gust was actually observed.
 * Automatically cleans up entries older than 2 days.
 * 
 * @param string $airportId Airport identifier (e.g., 'kspb')
 * @param float $currentGust Current gust speed value (knots)
 * @param array|null $airport Airport configuration array (optional, for timezone)
 * @param int|null $obsTimestamp Observation timestamp (when weather was observed), defaults to current time
 * @return void
 */
function updatePeakGust($airportId, $currentGust, $airport = null, $obsTimestamp = null) {
    try {
        $cacheDir = getWeatherCacheDir();
        if (!file_exists($cacheDir)) {
            if (!mkdir($cacheDir, 0755, true)) {
                error_log("Failed to create cache directory: {$cacheDir}");
                return;
            }
        }
        
        $file = $cacheDir . '/peak_gusts.json';
        // Use airport's local timezone to determine "today" (midnight reset at local timezone)
        $dateKey = $airport !== null ? getAirportDateKey($airport) : gmdate('Y-m-d');
        
        $peakGusts = [];
        if (file_exists($file)) {
            $content = file_get_contents($file);
            if ($content !== false) {
                $decoded = json_decode($content, true);
                $jsonError = json_last_error();
                
                // Validate JSON format - if invalid, delete and recreate file
                if ($jsonError !== JSON_ERROR_NONE || !is_array($decoded)) {
                    aviationwx_log('warning', 'peak_gusts.json has invalid format - recreating', [
                        'airport' => $airportId,
                        'json_error' => json_last_error_msg(),
                        'json_error_code' => $jsonError
                    ], 'app');
                    // Delete corrupted file
                    @unlink($file);
                    // Start with empty array
                    $peakGusts = [];
                } else {
                    $peakGusts = $decoded;
                }
            }
        }
        
        // Clean up old entries (older than 2 days) to prevent file bloat and stale data
        $currentDate = new DateTime($dateKey);
        $cleaned = 0;
        foreach ($peakGusts as $key => $value) {
            if (!is_string($key) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $key)) {
                continue; // Skip invalid keys
            }
            $entryDate = new DateTime($key);
            $daysDiff = $currentDate->diff($entryDate)->days;
            if ($daysDiff > 2) {
                unset($peakGusts[$key]);
                $cleaned++;
            }
        }
        if ($cleaned > 0) {
            aviationwx_log('info', 'cleaned old peak gusts', ['removed' => $cleaned, 'date_key' => $dateKey], 'app');
        }
        
        // Normalize existing entry to structured format {value, ts}
        $existing = $peakGusts[$dateKey][$airportId] ?? null;
        if (is_array($existing)) {
            $existingValue = $existing['value'] ?? 0;
        } else {
            $existingValue = is_numeric($existing) ? (float)$existing : 0;
        }

        // Use observation timestamp if provided, otherwise fall back to current time
        $timestamp = $obsTimestamp !== null ? $obsTimestamp : time();
        
        // If no entry for today (new day) or current gust is higher, update value and timestamp
        // This ensures we never use yesterday's data for today
        if (!isset($peakGusts[$dateKey][$airportId])) {
            aviationwx_log('info', 'initializing new day peak gust', ['airport' => $airportId, 'date_key' => $dateKey, 'gust' => $currentGust, 'obs_ts' => $timestamp], 'app');
            $peakGusts[$dateKey][$airportId] = [
                'value' => $currentGust,
                'ts' => $timestamp,
            ];
        } elseif ($currentGust > $existingValue) {
            // Update if current gust is higher (only for today's entry)
            $peakGusts[$dateKey][$airportId] = [
                'value' => $currentGust,
                'ts' => $timestamp,
            ];
        }
        // If current gust is not higher, preserve existing value and timestamp
        
        $jsonData = json_encode($peakGusts);
        if ($jsonData !== false) {
            file_put_contents($file, $jsonData, LOCK_EX);
        }
    } catch (Exception $e) {
        error_log("Error updating peak gust: " . $e->getMessage());
    }
}

/**
 * Get today's peak gust for an airport
 * 
 * Retrieves the highest gust speed recorded for today (based on airport's local timezone).
 * Returns structured data with value and timestamp of when peak gust was observed.
 * 
 * @param string $airportId Airport identifier (e.g., 'kspb')
 * @param float $currentGust Current gust speed value (used as fallback if no data)
 * @param array|null $airport Airport configuration array (optional, for timezone)
 * @return array {
 *   'value' => float,  // Peak gust speed in knots
 *   'ts' => int|null   // Timestamp when peak gust was observed, or null
 * }
 */
function getPeakGust($airportId, $currentGust, $airport = null) {
    $cacheDir = getWeatherCacheDir();
    $file = $cacheDir . '/peak_gusts.json';
    // Use airport's local timezone to determine "today" (midnight reset at local timezone)
    // Fallback to UTC if airport not provided (backward compatibility)
        $dateKey = $airport !== null ? getAirportDateKey($airport) : gmdate('Y-m-d');

    if (!file_exists($file)) {
        return ['value' => $currentGust, 'ts' => null];
    }

    $content = file_get_contents($file);
    if ($content === false) {
        return ['value' => $currentGust, 'ts' => null];
    }
    
    $decoded = json_decode($content, true);
    $jsonError = json_last_error();
    
    // Validate JSON format - if invalid, delete and recreate file
    if ($jsonError !== JSON_ERROR_NONE || !is_array($decoded)) {
        aviationwx_log('warning', 'peak_gusts.json has invalid format - recreating', [
            'airport' => $airportId,
            'json_error' => json_last_error_msg(),
            'json_error_code' => $jsonError
        ], 'app');
        // Delete corrupted file
        @unlink($file);
        // Return current gust as today's value
        return ['value' => $currentGust, 'ts' => null];
    }
    
    $peakGusts = $decoded;
    
    // Only return data for today's date key (never yesterday or older dates)
    $entry = $peakGusts[$dateKey][$airportId] ?? null;
    if ($entry === null) {
        // No entry for today - return current gust as today's value
        return ['value' => $currentGust, 'ts' => null];
    }

    // Support both legacy scalar and new structured format
    if (is_array($entry)) {
        $value = $entry['value'] ?? 0;
        $ts = $entry['ts'] ?? null;
        // Return stored value (today's peak) - don't modify with current gust
        // The stored value represents the peak for today, which may be higher than current
        return ['value' => $value, 'ts' => $ts];
    }

    // Legacy scalar format - return stored value
    $value = (float)$entry;
    return ['value' => $value, 'ts' => null];
}

/**
 * Update today's high and low temperatures for an airport
 * 
 * Tracks the highest and lowest temperatures for the current day (based on airport's local timezone).
 * Uses observation timestamp to record when extremes were actually observed.
 * Automatically cleans up entries older than 2 days.
 * 
 * @param string $airportId Airport identifier (e.g., 'kspb')
 * @param float $currentTemp Current temperature value (Celsius)
 * @param array|null $airport Airport configuration array (optional, for timezone)
 * @param int|null $obsTimestamp Observation timestamp (when weather was observed), defaults to current time
 * @return void
 */
function updateTempExtremes($airportId, $currentTemp, $airport = null, $obsTimestamp = null) {
    try {
        $cacheDir = getWeatherCacheDir();
        if (!file_exists($cacheDir)) {
            if (!mkdir($cacheDir, 0755, true)) {
                error_log("Failed to create cache directory: {$cacheDir}");
                return;
            }
        }
        
        $file = $cacheDir . '/temp_extremes.json';
        // Use airport's local timezone to determine "today" (midnight reset at local timezone)
        $dateKey = $airport !== null ? getAirportDateKey($airport) : gmdate('Y-m-d');
        
        $tempExtremes = [];
        if (file_exists($file)) {
            $content = file_get_contents($file);
            if ($content !== false) {
                $decoded = json_decode($content, true);
                $jsonError = json_last_error();
                
                // Validate JSON format - if invalid, delete and recreate file
                if ($jsonError !== JSON_ERROR_NONE || !is_array($decoded)) {
                    aviationwx_log('warning', 'temp_extremes.json has invalid format - recreating', [
                        'airport' => $airportId,
                        'json_error' => json_last_error_msg(),
                        'json_error_code' => $jsonError
                    ], 'app');
                    // Delete corrupted file
                    @unlink($file);
                    // Start with empty array
                    $tempExtremes = [];
                } else {
                    $tempExtremes = $decoded;
                }
            }
        }
        
        // Clean up old entries (older than 2 days) to prevent file bloat and stale data
        $currentDate = new DateTime($dateKey);
        $cleaned = 0;
        foreach ($tempExtremes as $key => $value) {
            if (!is_string($key) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $key)) {
                continue; // Skip invalid keys
            }
            $entryDate = new DateTime($key);
            $daysDiff = $currentDate->diff($entryDate)->days;
            if ($daysDiff > 2) {
                unset($tempExtremes[$key]);
                $cleaned++;
            }
        }
        if ($cleaned > 0) {
            aviationwx_log('info', 'cleaned old temp extremes', ['removed' => $cleaned, 'date_key' => $dateKey], 'app');
        }
        
        // Use observation timestamp if provided, otherwise fall back to current time
        $obsTs = $obsTimestamp !== null ? $obsTimestamp : time();
        
        // Initialize today's entry if it doesn't exist (always start fresh for new day)
        // This ensures we never use yesterday's data for today
        if (!isset($tempExtremes[$dateKey][$airportId])) {
            aviationwx_log('info', 'initializing new day temp extremes', ['airport' => $airportId, 'date_key' => $dateKey, 'temp' => $currentTemp, 'obs_ts' => $obsTs], 'app');
            $tempExtremes[$dateKey][$airportId] = [
                'high' => $currentTemp,
                'low' => $currentTemp,
                'high_ts' => $obsTs,  // Observation timestamp (when weather was actually observed)
                'low_ts' => $obsTs    // Observation timestamp (when weather was actually observed)
            ];
        } else {
            // Update high if current is higher
            if ($currentTemp > $tempExtremes[$dateKey][$airportId]['high']) {
                $tempExtremes[$dateKey][$airportId]['high'] = $currentTemp;
                $tempExtremes[$dateKey][$airportId]['high_ts'] = $obsTs;
            }
            if ($currentTemp < $tempExtremes[$dateKey][$airportId]['low']) {
                $tempExtremes[$dateKey][$airportId]['low'] = $currentTemp;
                $tempExtremes[$dateKey][$airportId]['low_ts'] = $obsTs;
            }
            // If same low temperature observed at earlier time, update timestamp to earliest observation
            elseif ($currentTemp == $tempExtremes[$dateKey][$airportId]['low']) {
                $existingLowTs = $tempExtremes[$dateKey][$airportId]['low_ts'] ?? $obsTs;
                if ($obsTs < $existingLowTs) {
                    $tempExtremes[$dateKey][$airportId]['low_ts'] = $obsTs;
                }
            }
        }
        
        $jsonData = json_encode($tempExtremes);
        if ($jsonData !== false) {
            file_put_contents($file, $jsonData, LOCK_EX);
        }
    } catch (Exception $e) {
        error_log("Error updating temp extremes: " . $e->getMessage());
    }
}

/**
 * Get today's high and low temperatures for an airport
 * 
 * Retrieves the highest and lowest temperatures recorded for today (based on airport's local timezone).
 * Returns structured data with values and timestamps of when extremes were observed.
 * Uses airport's local timezone midnight for date key to ensure daily reset at local midnight.
 * 
 * @param string $airportId Airport identifier (e.g., 'kspb')
 * @param float $currentTemp Current temperature value (used as fallback if no data)
 * @param array|null $airport Airport configuration array (optional, for timezone)
 * @return array {
 *   'high' => float,      // High temperature in Celsius
 *   'low' => float,       // Low temperature in Celsius
 *   'high_ts' => int,     // Timestamp when high was observed
 *   'low_ts' => int       // Timestamp when low was observed
 * }
 */
function getTempExtremes($airportId, $currentTemp, $airport = null) {
    $cacheDir = getWeatherCacheDir();
    $file = $cacheDir . '/temp_extremes.json';
    // Use airport's local timezone to determine "today" (midnight reset at local timezone)
    // Fallback to UTC if airport not provided (backward compatibility)
        $dateKey = $airport !== null ? getAirportDateKey($airport) : gmdate('Y-m-d');
    
    if (!file_exists($file)) {
        $now = time();
        return [
            'high' => $currentTemp, 
            'low' => $currentTemp,
            'high_ts' => $now,
            'low_ts' => $now
        ];
    }
    
    $content = file_get_contents($file);
    if ($content === false) {
        $now = time();
        return [
            'high' => $currentTemp, 
            'low' => $currentTemp,
            'high_ts' => $now,
            'low_ts' => $now
        ];
    }
    
    $decoded = json_decode($content, true);
    $jsonError = json_last_error();
    
    // Validate JSON format - if invalid, delete and recreate file
    if ($jsonError !== JSON_ERROR_NONE || !is_array($decoded)) {
        aviationwx_log('warning', 'temp_extremes.json has invalid format - recreating', [
            'airport' => $airportId,
            'json_error' => json_last_error_msg(),
            'json_error_code' => $jsonError
        ], 'app');
        // Delete corrupted file
        @unlink($file);
        // Return current temp as today's value
        $now = time();
        return [
            'high' => $currentTemp, 
            'low' => $currentTemp,
            'high_ts' => $now,
            'low_ts' => $now
        ];
    }
    
    $tempExtremes = $decoded;
    
    // Only return data for today's date key (never yesterday or older dates)
    if (isset($tempExtremes[$dateKey][$airportId])) {
        $stored = $tempExtremes[$dateKey][$airportId];
        
        // Return stored values without modification (this is a getter function)
        return [
            'high' => $stored['high'] ?? $currentTemp,
            'low' => $stored['low'] ?? $currentTemp,
            'high_ts' => $stored['high_ts'] ?? time(),
            'low_ts' => $stored['low_ts'] ?? time()
        ];
    }
    
    // No entry for today - return current temp as today's value
    $now = time();
    return [
        'high' => $currentTemp, 
        'low' => $currentTemp,
        'high_ts' => $now,
        'low_ts' => $now
    ];
}

