<?php
/**
 * Daily Weather Tracking Functions
 * 
 * Tracks daily weather extremes (peak gust, temperature highs/lows) for airports.
 * Uses airport's local timezone to determine "today" for proper daily resets.
 */

require_once __DIR__ . '/../logger.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/../cache-paths.php';

/**
 * Clear weather cache file for an airport
 * 
 * Deletes cached weather data to force fresh fetch.
 * Used during daily resets to prevent serving stale observation times.
 * 
 * @param string $airportId Airport identifier (e.g., 'kspb')
 * @return bool True if cache was cleared or didn't exist, false on error
 */
function clearWeatherCache($airportId) {
    $cacheFile = getWeatherCachePath($airportId);
    
    if (!file_exists($cacheFile)) {
        return true; // Already doesn't exist
    }
    
    $result = @unlink($cacheFile);
    if ($result) {
        aviationwx_log('info', 'cleared weather cache for new day', [
            'airport' => $airportId,
            'cache_file' => basename($cacheFile)
        ], 'app');
    } else {
        aviationwx_log('warning', 'failed to clear weather cache', [
            'airport' => $airportId,
            'cache_file' => $cacheFile
        ], 'app');
    }
    
    return $result;
}

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
    try {$cacheDir = getWeatherCacheDir();
        if (!file_exists($cacheDir)) {
            if (!mkdir($cacheDir, 0755, true)) {
                error_log("Failed to create cache directory: {$cacheDir}");return;
            }
        }
        
        $file = $cacheDir . '/peak_gusts.json';
        // Use airport's local timezone to determine "today" (midnight reset at local timezone)
        // If observation timestamp is provided, use it to determine the date key (ensures consistency)
        // Otherwise, use current time with airport timezone
        if ($obsTimestamp !== null && $airport !== null) {
            $timezone = getAirportTimezone($airport);
            $tz = new DateTimeZone($timezone);
            // Note: When using '@' prefix, PHP creates DateTime in UTC and ignores the timezone parameter.
            // We must call setTimezone() after creation to convert to the airport's local timezone.
            $obsDate = new DateTime('@' . $obsTimestamp);
            $obsDate->setTimezone($tz);
            $dateKey = $obsDate->format('Y-m-d');
        } else {
            $dateKey = $airport !== null ? getAirportDateKey($airport) : gmdate('Y-m-d');
        }
        
        // Use file locking to prevent race conditions
        // Critical: Lock must be acquired BEFORE reading to prevent concurrent updates
        // from overwriting each other's changes
        $fp = @fopen($file, 'c+');
        if ($fp === false) {
            // Fallback to non-locked write if we can't open file
            aviationwx_log('warning', 'peak_gusts.json file open failed, using fallback', ['file' => $file], 'app');
            updatePeakGustFallback($airportId, $currentGust, $airport, $obsTimestamp, $file, $dateKey);
            return;
        }
        
        // Acquire exclusive lock (blocking) to ensure atomicity
        // This prevents concurrent requests from both reading the same data
        if (!@flock($fp, LOCK_EX)) {
            @fclose($fp);
            aviationwx_log('warning', 'peak_gusts.json file lock failed, using fallback', ['file' => $file], 'app');
            updatePeakGustFallback($airportId, $currentGust, $airport, $obsTimestamp, $file, $dateKey);
            return;
        }
        
        // Read current data while lock is held
        $peakGusts = [];
        $fileSize = @filesize($file);
        if ($fileSize > 0) {
            $content = @stream_get_contents($fp);
            if ($content !== false && $content !== '') {
                $decoded = @json_decode($content, true);
                $jsonError = json_last_error();
                
                // Validate JSON format - if invalid, start with empty array
                if ($jsonError !== JSON_ERROR_NONE || !is_array($decoded)) {
                    aviationwx_log('warning', 'peak_gusts.json has invalid format - recreating', [
                        'airport' => $airportId,
                        'json_error' => json_last_error_msg(),
                        'json_error_code' => $jsonError
                    ], 'app');
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
            $existingValue = is_numeric($existing['value']) ? (float)$existing['value'] : 0;
        } else {
            $existingValue = is_numeric($existing) ? (float)$existing : 0;
        }

        // Ensure currentGust is properly cast to float for accurate comparison
        $currentGustFloat = is_numeric($currentGust) ? (float)$currentGust : 0;// Use observation timestamp if provided, otherwise fall back to current time
        $timestamp = $obsTimestamp !== null ? $obsTimestamp : time();// Get existing timestamp for comparison
        $existingTimestamp = null;
        if (isset($peakGusts[$dateKey][$airportId])) {
            if (is_array($peakGusts[$dateKey][$airportId])) {
                $existingTimestamp = $peakGusts[$dateKey][$airportId]['ts'] ?? null;
            }
        }
        
        // If no entry for today (new day), current gust is higher, or current gust equals existing but timestamp is newer
        // This ensures we never use yesterday's data for today, and we always show the most recent timestamp for equal values
        if (!isset($peakGusts[$dateKey][$airportId])) {
            aviationwx_log('info', 'initializing new day peak gust', ['airport' => $airportId, 'date_key' => $dateKey, 'gust' => $currentGustFloat, 'obs_ts' => $timestamp], 'app');
            
            // Clear weather cache to force fresh fetch with new observation times
            // This prevents serving cached data with yesterday's observation timestamps
            clearWeatherCache($airportId);
            
            $peakGusts[$dateKey][$airportId] = [
                'value' => $currentGustFloat,
                'ts' => $timestamp,
            ];
        } elseif ($currentGustFloat > $existingValue) {
            // Update if current gust is higher (only for today's entry)
            // Use strict float comparison to prevent type coercion issues
            aviationwx_log('info', 'updating peak gust with higher value', [
                'airport' => $airportId,
                'date_key' => $dateKey,
                'old_peak' => $existingValue,
                'new_peak' => $currentGustFloat,
                'obs_ts' => $timestamp
            ], 'app');$peakGusts[$dateKey][$airportId] = [
                'value' => $currentGustFloat,
                'ts' => $timestamp,
            ];
        } elseif ($currentGustFloat == $existingValue && $timestamp !== null && ($existingTimestamp === null || $timestamp > $existingTimestamp)) {
            // Update timestamp if current gust equals existing value but new timestamp is more recent
            // This ensures we always show the most recent occurrence of the peak gust
            aviationwx_log('info', 'updating peak gust timestamp (equal value, newer timestamp)', [
                'airport' => $airportId,
                'date_key' => $dateKey,
                'peak_value' => $currentGustFloat,
                'old_ts' => $existingTimestamp,
                'new_ts' => $timestamp
            ], 'app');$peakGusts[$dateKey][$airportId] = [
                'value' => $currentGustFloat,
                'ts' => $timestamp,
            ];
        } else {
            // If current gust is not higher and timestamp is not newer, preserve existing value and timestamp
            // Log this to help debug any issues where peak gust might be incorrectly overwritten
            if ($currentGustFloat < $existingValue) {
                aviationwx_log('debug', 'preserving peak gust (current lower than existing)', [
                    'airport' => $airportId,
                    'date_key' => $dateKey,
                    'existing_peak' => $existingValue,
                    'current_gust' => $currentGustFloat
                ], 'app');
            }}// Write modified data back while lock is still held
        // Truncate first to ensure clean write (prevents partial overwrites)
        @ftruncate($fp, 0);
        @rewind($fp);
        $jsonData = json_encode($peakGusts);
        if ($jsonData !== false) {
            $bytesWritten = @fwrite($fp, $jsonData);
            @fflush($fp);} else {}
        
        // Release lock and close
        @flock($fp, LOCK_UN);
        @fclose($fp);} catch (Exception $e) {
        error_log("Error updating peak gust: " . $e->getMessage());
    }
}

/**
 * Fallback for peak gust update when file handle operations fail
 * Uses file_put_contents with LOCK_EX as last resort
 * 
 * @param string $airportId Airport identifier
 * @param float $currentGust Current gust speed value
 * @param array|null $airport Airport configuration array
 * @param int|null $obsTimestamp Observation timestamp
 * @param string $file Cache file path
 * @param string $dateKey Date key for today
 * @return void
 */
function updatePeakGustFallback($airportId, $currentGust, $airport, $obsTimestamp, $file, $dateKey) {
    try {
        $peakGusts = [];
        if (file_exists($file)) {
            $content = @file_get_contents($file);
            if ($content !== false) {
                $decoded = @json_decode($content, true);
                $jsonError = json_last_error();
                
                // Validate JSON format - if invalid, delete and recreate file
                if ($jsonError !== JSON_ERROR_NONE || !is_array($decoded)) {
                    aviationwx_log('warning', 'peak_gusts.json has invalid format - recreating (fallback)', [
                        'airport' => $airportId,
                        'json_error' => json_last_error_msg(),
                        'json_error_code' => $jsonError
                    ], 'app');
                    @unlink($file);
                    $peakGusts = [];
                } else {
                    $peakGusts = $decoded;
                }
            }
        }
        
        // Clean up old entries (older than 2 days)
        $currentDate = new DateTime($dateKey);
        foreach ($peakGusts as $key => $value) {
            if (!is_string($key) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $key)) {
                continue;
            }
            $entryDate = new DateTime($key);
            $daysDiff = $currentDate->diff($entryDate)->days;
            if ($daysDiff > 2) {
                unset($peakGusts[$key]);
            }
        }
        
        // Normalize existing entry
        $existing = $peakGusts[$dateKey][$airportId] ?? null;
        if (is_array($existing)) {
            $existingValue = is_numeric($existing['value']) ? (float)$existing['value'] : 0;
        } else {
            $existingValue = is_numeric($existing) ? (float)$existing : 0;
        }
        
        $currentGustFloat = is_numeric($currentGust) ? (float)$currentGust : 0;
        $timestamp = $obsTimestamp !== null ? $obsTimestamp : time();
        
        // Update logic (same as main function)
        if (!isset($peakGusts[$dateKey][$airportId])) {
            $peakGusts[$dateKey][$airportId] = [
                'value' => $currentGustFloat,
                'ts' => $timestamp,
            ];
        } elseif ($currentGustFloat > $existingValue) {
            $peakGusts[$dateKey][$airportId] = [
                'value' => $currentGustFloat,
                'ts' => $timestamp,
            ];
        }
        
        $jsonData = json_encode($peakGusts);
        if ($jsonData !== false) {
            @file_put_contents($file, $jsonData, LOCK_EX);
        }
    } catch (Exception $e) {
        error_log("Error updating peak gust (fallback): " . $e->getMessage());
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
        $dateKey = $airport !== null ? getAirportDateKey($airport) : gmdate('Y-m-d');if (!file_exists($file)) {
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
    
    // Get entry for today's date key
    $entry = $peakGusts[$dateKey][$airportId] ?? null;
    
    // Also check for a more recent date (timezone boundary case)
    // This can happen if the date key calculation changed due to timezone or time differences
    // For example: observation at 11:30 PM PST (Dec 22) might be stored as Dec 23 if timestamp is in UTC
    $allDateKeys = array_keys($peakGusts);
    rsort($allDateKeys); // Sort descending (most recent first)
    $mostRecentEntry = null;
    $mostRecentDateKey = null;
    foreach ($allDateKeys as $key) {
        if (isset($peakGusts[$key][$airportId])) {
            $mostRecentEntry = $peakGusts[$key][$airportId];
            $mostRecentDateKey = $key;
            break;
        }
    }
    
    // Use the most recent entry if it's more recent than today's date key
    // This handles timezone boundary cases where entries might be stored under tomorrow's date
    if ($mostRecentEntry !== null && $mostRecentDateKey !== null && $mostRecentDateKey >= $dateKey) {
        $entry = $mostRecentEntry;
    }
    
    if ($entry === null) {
        // No entry for today or more recent - return current gust as today's value
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
        
        // Use file locking to prevent race conditions
        // Critical: Lock must be acquired BEFORE reading to prevent concurrent updates
        // from overwriting each other's changes
        $fp = @fopen($file, 'c+');
        if ($fp === false) {
            // Fallback to non-locked write if we can't open file
            aviationwx_log('warning', 'temp_extremes.json file open failed, using fallback', ['file' => $file], 'app');
            updateTempExtremesFallback($airportId, $currentTemp, $airport, $obsTimestamp, $file, $dateKey);
            return;
        }
        
        // Acquire exclusive lock (blocking) to ensure atomicity
        // This prevents concurrent requests from both reading the same data
        if (!@flock($fp, LOCK_EX)) {
            @fclose($fp);
            aviationwx_log('warning', 'temp_extremes.json file lock failed, using fallback', ['file' => $file], 'app');
            updateTempExtremesFallback($airportId, $currentTemp, $airport, $obsTimestamp, $file, $dateKey);
            return;
        }
        
        // Read current data while lock is held
        $tempExtremes = [];
        $fileSize = @filesize($file);
        if ($fileSize > 0) {
            $content = @stream_get_contents($fp);
            if ($content !== false && $content !== '') {
                $decoded = @json_decode($content, true);
                $jsonError = json_last_error();
                
                // Validate JSON format - if invalid, start with empty array
                if ($jsonError !== JSON_ERROR_NONE || !is_array($decoded)) {
                    aviationwx_log('warning', 'temp_extremes.json has invalid format - recreating', [
                        'airport' => $airportId,
                        'json_error' => json_last_error_msg(),
                        'json_error_code' => $jsonError
                    ], 'app');
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
        
        // Ensure currentTemp is properly cast to float for accurate comparison
        $currentTempFloat = is_numeric($currentTemp) ? (float)$currentTemp : 0;
        
        // Initialize today's entry if it doesn't exist (always start fresh for new day)
        // This ensures we never use yesterday's data for today
        if (!isset($tempExtremes[$dateKey][$airportId])) {
            aviationwx_log('info', 'initializing new day temp extremes', ['airport' => $airportId, 'date_key' => $dateKey, 'temp' => $currentTempFloat, 'obs_ts' => $obsTs], 'app');
            
            // Clear weather cache to force fresh fetch with new observation times
            // This prevents serving cached data with yesterday's observation timestamps
            clearWeatherCache($airportId);
            
            $tempExtremes[$dateKey][$airportId] = [
                'high' => $currentTempFloat,
                'low' => $currentTempFloat,
                'high_ts' => $obsTs,  // Observation timestamp (when weather was actually observed)
                'low_ts' => $obsTs    // Observation timestamp (when weather was actually observed)
            ];
        } else {
            // Normalize stored values to float for accurate comparison
            $storedHigh = is_numeric($tempExtremes[$dateKey][$airportId]['high']) ? (float)$tempExtremes[$dateKey][$airportId]['high'] : $currentTempFloat;
            $storedLow = is_numeric($tempExtremes[$dateKey][$airportId]['low']) ? (float)$tempExtremes[$dateKey][$airportId]['low'] : $currentTempFloat;
            
            // Update high if current is higher
            if ($currentTempFloat > $storedHigh) {
                aviationwx_log('info', 'updating temp high with higher value', [
                    'airport' => $airportId,
                    'date_key' => $dateKey,
                    'old_high' => $storedHigh,
                    'new_high' => $currentTempFloat,
                    'obs_ts' => $obsTs
                ], 'app');
                $tempExtremes[$dateKey][$airportId]['high'] = $currentTempFloat;
                $tempExtremes[$dateKey][$airportId]['high_ts'] = $obsTs;
            }
            // Update low if current is lower
            if ($currentTempFloat < $storedLow) {
                aviationwx_log('info', 'updating temp low with lower value', [
                    'airport' => $airportId,
                    'date_key' => $dateKey,
                    'old_low' => $storedLow,
                    'new_low' => $currentTempFloat,
                    'obs_ts' => $obsTs
                ], 'app');
                $tempExtremes[$dateKey][$airportId]['low'] = $currentTempFloat;
                $tempExtremes[$dateKey][$airportId]['low_ts'] = $obsTs;
            }
            // If same low temperature observed at earlier time, update timestamp to earliest observation
            elseif ($currentTempFloat == $storedLow) {
                $existingLowTs = $tempExtremes[$dateKey][$airportId]['low_ts'] ?? $obsTs;
                if ($obsTs < $existingLowTs) {
                    aviationwx_log('debug', 'updating temp low timestamp to earlier observation', [
                        'airport' => $airportId,
                        'date_key' => $dateKey,
                        'temp' => $currentTempFloat,
                        'old_ts' => $existingLowTs,
                        'new_ts' => $obsTs
                    ], 'app');
                    $tempExtremes[$dateKey][$airportId]['low_ts'] = $obsTs;
                }
            }
        }
        
        // Write modified data back while lock is still held
        // Truncate first to ensure clean write (prevents partial overwrites)
        @ftruncate($fp, 0);
        @rewind($fp);
        $jsonData = json_encode($tempExtremes);
        if ($jsonData !== false) {
            @fwrite($fp, $jsonData);
            @fflush($fp);
        }
        
        // Release lock and close
        @flock($fp, LOCK_UN);
        @fclose($fp);
    } catch (Exception $e) {
        error_log("Error updating temp extremes: " . $e->getMessage());
    }
}

/**
 * Fallback for temp extremes update when file handle operations fail
 * Uses file_put_contents with LOCK_EX as last resort
 * 
 * @param string $airportId Airport identifier
 * @param float $currentTemp Current temperature value
 * @param array|null $airport Airport configuration array
 * @param int|null $obsTimestamp Observation timestamp
 * @param string $file Cache file path
 * @param string $dateKey Date key for today
 * @return void
 */
function updateTempExtremesFallback($airportId, $currentTemp, $airport, $obsTimestamp, $file, $dateKey) {
    try {
        $tempExtremes = [];
        if (file_exists($file)) {
            $content = @file_get_contents($file);
            if ($content !== false) {
                $decoded = @json_decode($content, true);
                $jsonError = json_last_error();
                
                // Validate JSON format - if invalid, delete and recreate file
                if ($jsonError !== JSON_ERROR_NONE || !is_array($decoded)) {
                    aviationwx_log('warning', 'temp_extremes.json has invalid format - recreating (fallback)', [
                        'airport' => $airportId,
                        'json_error' => json_last_error_msg(),
                        'json_error_code' => $jsonError
                    ], 'app');
                    @unlink($file);
                    $tempExtremes = [];
                } else {
                    $tempExtremes = $decoded;
                }
            }
        }
        
        // Clean up old entries (older than 2 days)
        $currentDate = new DateTime($dateKey);
        foreach ($tempExtremes as $key => $value) {
            if (!is_string($key) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $key)) {
                continue;
            }
            $entryDate = new DateTime($key);
            $daysDiff = $currentDate->diff($entryDate)->days;
            if ($daysDiff > 2) {
                unset($tempExtremes[$key]);
            }
        }
        
        $obsTs = $obsTimestamp !== null ? $obsTimestamp : time();
        $currentTempFloat = is_numeric($currentTemp) ? (float)$currentTemp : 0;
        
        // Update logic (same as main function)
        if (!isset($tempExtremes[$dateKey][$airportId])) {
            $tempExtremes[$dateKey][$airportId] = [
                'high' => $currentTempFloat,
                'low' => $currentTempFloat,
                'high_ts' => $obsTs,
                'low_ts' => $obsTs
            ];
        } else {
            $storedHigh = is_numeric($tempExtremes[$dateKey][$airportId]['high']) ? (float)$tempExtremes[$dateKey][$airportId]['high'] : $currentTempFloat;
            $storedLow = is_numeric($tempExtremes[$dateKey][$airportId]['low']) ? (float)$tempExtremes[$dateKey][$airportId]['low'] : $currentTempFloat;
            
            if ($currentTempFloat > $storedHigh) {
                $tempExtremes[$dateKey][$airportId]['high'] = $currentTempFloat;
                $tempExtremes[$dateKey][$airportId]['high_ts'] = $obsTs;
            }
            if ($currentTempFloat < $storedLow) {
                $tempExtremes[$dateKey][$airportId]['low'] = $currentTempFloat;
                $tempExtremes[$dateKey][$airportId]['low_ts'] = $obsTs;
            } elseif ($currentTempFloat == $storedLow) {
                $existingLowTs = $tempExtremes[$dateKey][$airportId]['low_ts'] ?? $obsTs;
                if ($obsTs < $existingLowTs) {
                    $tempExtremes[$dateKey][$airportId]['low_ts'] = $obsTs;
                }
            }
        }
        
        $jsonData = json_encode($tempExtremes);
        if ($jsonData !== false) {
            @file_put_contents($file, $jsonData, LOCK_EX);
        }
    } catch (Exception $e) {
        error_log("Error updating temp extremes (fallback): " . $e->getMessage());
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
    
    // Handle null/invalid currentTemp - return null values if no stored data
    // This prevents 0°C (32°F) from being shown during outages
    $hasValidCurrentTemp = is_numeric($currentTemp);
    $currentTempFloat = $hasValidCurrentTemp ? (float)$currentTemp : null;
    
    // Default return for when no data is available
    $noDataReturn = [
        'high' => null, 
        'low' => null,
        'high_ts' => null,
        'low_ts' => null
    ];
    
    if (!file_exists($file)) {
        // No cache file - return current temp if available, otherwise null
        if ($hasValidCurrentTemp) {
            $now = time();
            return [
                'high' => $currentTempFloat, 
                'low' => $currentTempFloat,
                'high_ts' => $now,
                'low_ts' => $now
            ];
        }
        return $noDataReturn;
    }
    
    $content = file_get_contents($file);
    if ($content === false) {
        if ($hasValidCurrentTemp) {
            $now = time();
            return [
                'high' => $currentTempFloat, 
                'low' => $currentTempFloat,
                'high_ts' => $now,
                'low_ts' => $now
            ];
        }
        return $noDataReturn;
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
        // Return current temp as today's value if available
        if ($hasValidCurrentTemp) {
            $now = time();
            return [
                'high' => $currentTempFloat, 
                'low' => $currentTempFloat,
                'high_ts' => $now,
                'low_ts' => $now
            ];
        }
        return $noDataReturn;
    }
    
    $tempExtremes = $decoded;
    
    // Only return data for today's date key (never yesterday or older dates)
    if (isset($tempExtremes[$dateKey][$airportId])) {
        $stored = $tempExtremes[$dateKey][$airportId];
        
        // Return stored values if valid, otherwise fall back to current temp or null
        $storedHigh = isset($stored['high']) && is_numeric($stored['high']) ? (float)$stored['high'] : $currentTempFloat;
        $storedLow = isset($stored['low']) && is_numeric($stored['low']) ? (float)$stored['low'] : $currentTempFloat;
        
        return [
            'high' => $storedHigh,
            'low' => $storedLow,
            'high_ts' => $stored['high_ts'] ?? ($hasValidCurrentTemp ? time() : null),
            'low_ts' => $stored['low_ts'] ?? ($hasValidCurrentTemp ? time() : null)
        ];
    }
    
    // No entry for today - return current temp as today's value if available, otherwise null
    if (!$hasValidCurrentTemp) {
        return $noDataReturn;
    }
    $now = time();
    return [
        'high' => $currentTempFloat, 
        'low' => $currentTempFloat,
        'high_ts' => $now,
        'low_ts' => $now
    ];
}

