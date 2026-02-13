<?php
/**
 * Daily Weather Tracking Functions
 * 
 * Tracks daily weather extremes (peak gust, temperature highs/lows) for airports.
 * Uses airport's local timezone to determine "today" for proper daily resets.
 * 
 * Architecture: Per-airport files for scalability and reduced lock contention
 * - Old: Single file for all airports (lock contention, I/O amplification)
 * - New: One file per airport (~100 bytes each, no cross-airport locking)
 * 
 * Migration: Backward compatible - reads old format if new format doesn't exist
 */

require_once __DIR__ . '/../logger.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/../cache-paths.php';
require_once __DIR__ . '/history.php';

/**
 * Get the file path for an airport's daily tracking data
 *
 * Uses centralized cache path constants from cache-paths.php.
 *
 * @param string $airportId Airport identifier
 * @param string $trackingType Type of tracking: 'temp_extremes' or 'peak_gusts'
 * @return string File path (e.g., cache/peak_gusts/56s.json)
 */
function getAirportTrackingFile(string $airportId, string $trackingType): string {
    if ($trackingType === 'peak_gusts') {
        return getPeakGustTrackingPath($airportId);
    }
    return getTempExtremesTrackingPath($airportId);
}

/**
 * Migrate legacy single-file data to per-airport files
 *
 * Reads old format (all airports in one file) and splits into per-airport files.
 * Only migrates if per-airport file doesn't exist yet.
 *
 * @param string $trackingType Type of tracking: 'temp_extremes' or 'peak_gusts'
 * @return void
 */
function migrateLegacyTrackingData(string $trackingType): void {
    $legacyFile = $trackingType === 'peak_gusts' ? CACHE_PEAK_GUSTS_FILE : CACHE_TEMP_EXTREMES_FILE;

    if (!file_exists($legacyFile)) {
        return;
    }
    
    $content = @file_get_contents($legacyFile);
    if ($content === false) {
        return;
    }
    
    $data = @json_decode($content, true);
    if (!is_array($data)) {
        return;
    }
    
    // Legacy format: { "2026-02-13": { "56s": {...}, "kspb": {...} } }
    $migrated = 0;
    foreach ($data as $dateKey => $airports) {
        if (!is_array($airports)) continue;
        
        foreach ($airports as $airportId => $values) {
            $airportFile = getAirportTrackingFile($airportId, $trackingType);
            
            // Only migrate if per-airport file doesn't exist
            if (file_exists($airportFile)) {
                continue;
            }
            
            // Write per-airport file: { "2026-02-13": {...}, "2026-02-12": {...} }
            $airportData = [];
            
            // Collect all dates for this airport from legacy file
            foreach ($data as $date => $airportsForDate) {
                if (isset($airportsForDate[$airportId])) {
                    $airportData[$date] = $airportsForDate[$airportId];
                }
            }
            
            if (!empty($airportData)) {
                ensureCacheDir(dirname($airportFile));
                @file_put_contents($airportFile, json_encode($airportData), LOCK_EX);
                $migrated++;
            }
        }
    }
    
    if ($migrated > 0) {
        aviationwx_log('info', 'migrated legacy tracking data', [
            'type' => $trackingType,
            'airports_migrated' => $migrated
        ], 'app');
    }
}

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
 * Per-airport architecture: Each airport has its own file to eliminate lock contention.
 * 
 * @param string $airportId Airport identifier (e.g., 'kspb')
 * @param float $currentGust Current gust speed value (knots)
 * @param array|null $airport Airport configuration array (optional, for timezone)
 * @param int|null $obsTimestamp Observation timestamp (when weather was observed), defaults to current time
 * @return void
 */
function updatePeakGust($airportId, $currentGust, $airport = null, $obsTimestamp = null) {
    try {
        // Migrate legacy data first time
        static $migrated = false;
        if (!$migrated) {
            migrateLegacyTrackingData('peak_gusts');
            $migrated = true;
        }
        
        $file = getPeakGustTrackingPath($airportId);
        ensureCacheDir(CACHE_PEAK_GUSTS_DIR);

        // Determine date key from observation timestamp
        if ($obsTimestamp !== null && $airport !== null) {
            $timezone = getAirportTimezone($airport);
            $tz = new DateTimeZone($timezone);
            $obsDate = new DateTime('@' . $obsTimestamp);
            $obsDate->setTimezone($tz);
            $dateKey = $obsDate->format('Y-m-d');
        } else {
            $dateKey = $airport !== null ? getAirportDateKey($airport) : gmdate('Y-m-d');
        }
        
        // Use file locking for this airport only (no cross-airport contention)
        $fp = @fopen($file, 'c+');
        if ($fp === false) {
            aviationwx_log('warning', 'peak gust file open failed', [
                'airport' => $airportId,
                'file' => basename($file)
            ], 'app');
            return;
        }
        
        if (!@flock($fp, LOCK_EX)) {
            @fclose($fp);
            aviationwx_log('warning', 'peak gust file lock failed', [
                'airport' => $airportId,
                'file' => basename($file)
            ], 'app');
            return;
        }
        
        // Read current data (per-airport format: { "2026-02-13": {...}, "2026-02-12": {...} })
        $peakGusts = [];
        $fileSize = @filesize($file);
        if ($fileSize > 0) {
            $content = @stream_get_contents($fp);
            if ($content !== false && $content !== '') {
                $decoded = @json_decode($content, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $peakGusts = $decoded;
                } else {
                    aviationwx_log('warning', 'peak gust file corrupted - recreating', [
                        'airport' => $airportId,
                        'json_error' => json_last_error_msg()
                    ], 'app');
                }
            }
        }
        
        // Clean up old entries (older than 2 days)
        $currentDate = new DateTime($dateKey);
        $cleaned = 0;
        foreach ($peakGusts as $key => $value) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $key)) {
                continue;
            }
            $entryDate = new DateTime($key);
            $daysDiff = $currentDate->diff($entryDate)->days;
            if ($daysDiff > 2) {
                unset($peakGusts[$key]);
                $cleaned++;
            }
        }
        
        // Get existing value for today
        $existing = $peakGusts[$dateKey] ?? null;
        $existingValue = 0;
        $existingTimestamp = null;
        
        if (is_array($existing)) {
            $existingValue = is_numeric($existing['value']) ? (float)$existing['value'] : 0;
            $existingTimestamp = $existing['ts'] ?? null;
        } elseif (is_numeric($existing)) {
            $existingValue = (float)$existing;
        }
        
        $currentGustFloat = is_numeric($currentGust) ? (float)$currentGust : 0;
        $timestamp = $obsTimestamp !== null ? $obsTimestamp : time();
        
        // Update logic
        if (!isset($peakGusts[$dateKey])) {
            aviationwx_log('info', 'initializing new day peak gust', [
                'airport' => $airportId,
                'date_key' => $dateKey,
                'gust' => $currentGustFloat,
                'obs_ts' => $timestamp
            ], 'app');
            
            clearWeatherCache($airportId);
            
            $peakGusts[$dateKey] = [
                'value' => $currentGustFloat,
                'ts' => $timestamp
            ];
        } elseif ($currentGustFloat > $existingValue) {
            $peakGusts[$dateKey] = [
                'value' => $currentGustFloat,
                'ts' => $timestamp
            ];
        } elseif ($currentGustFloat == $existingValue && $timestamp !== null && ($existingTimestamp === null || $timestamp > $existingTimestamp)) {
            $peakGusts[$dateKey] = [
                'value' => $currentGustFloat,
                'ts' => $timestamp
            ];
        }
        
        // Write back
        @ftruncate($fp, 0);
        @rewind($fp);
        $jsonData = json_encode($peakGusts);
        if ($jsonData !== false) {
            @fwrite($fp, $jsonData);
            @fflush($fp);
        }
        
        @flock($fp, LOCK_UN);
        @fclose($fp);
    } catch (Exception $e) {
        error_log("Error updating peak gust: " . $e->getMessage());
    }
}

/**
 * Get today's peak gust for an airport
 *
 * Tries per-airport file first, then legacy single-file format.
 *
 * @param string $airportId Airport identifier (e.g., 'kspb')
 * @param float $currentGust Current gust speed value (used as fallback if no data)
 * @param array|null $airport Airport configuration array (optional, for timezone)
 * @return array{value: float, ts: int|null}
 */
function getPeakGust($airportId, $currentGust, $airport = null) {
    $dateKey = $airport !== null ? getAirportDateKey($airport) : gmdate('Y-m-d');
    $file = getPeakGustTrackingPath($airportId);

    // Try per-airport file first
    if (file_exists($file)) {
        $content = @file_get_contents($file);
        if ($content !== false) {
            $decoded = @json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $entry = $decoded[$dateKey] ?? null;
                
                if (is_array($entry)) {
                    return [
                        'value' => (float)($entry['value'] ?? $currentGust),
                        'ts' => $entry['ts'] ?? null
                    ];
                }
            }
        }
    }
    
    // Fallback: Try legacy single-file format
    if (file_exists(CACHE_PEAK_GUSTS_FILE)) {
        $content = @file_get_contents(CACHE_PEAK_GUSTS_FILE);
        if ($content !== false) {
            $decoded = @json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $entry = $decoded[$dateKey][$airportId] ?? null;
                if ($entry !== null) {
                    if (is_array($entry)) {
                        return [
                            'value' => (float)($entry['value'] ?? $currentGust),
                            'ts' => $entry['ts'] ?? null
                        ];
                    }
                    return ['value' => (float)$entry, 'ts' => null];
                }
            }
        }
    }

    // Fallback: Compute from weather history when daily tracking is empty
    if ($airport !== null) {
        $timezone = getAirportTimezone($airport);
        $computed = computeDailyExtremesFromHistory($airportId, $dateKey, $timezone);
        if ($computed['peak_gust'] !== null) {
            return [
                'value' => $computed['peak_gust'],
                'ts' => $computed['peak_gust_ts']
            ];
        }
    }

    return ['value' => $currentGust, 'ts' => null];
}

/**
 * Update today's high and low temperatures for an airport
 *
 * Tracks the highest and lowest temperatures for the current day (based on airport's local timezone).
 * Uses observation timestamp to record when extremes were actually observed.
 * Per-airport files eliminate lock contention across airports.
 *
 * @param string $airportId Airport identifier (e.g., 'kspb')
 * @param float $currentTemp Current temperature value (Celsius)
 * @param array|null $airport Airport configuration array (optional, for timezone)
 * @param int|null $obsTimestamp Observation timestamp (when weather was observed), defaults to current time
 * @return void
 */
function updateTempExtremes($airportId, $currentTemp, $airport = null, $obsTimestamp = null): void {
    try {
        static $migrated = false;
        if (!$migrated) {
            migrateLegacyTrackingData('temp_extremes');
            $migrated = true;
        }

        $file = getTempExtremesTrackingPath($airportId);
        ensureCacheDir(CACHE_TEMP_EXTREMES_DIR);

        if ($obsTimestamp !== null && $airport !== null) {
            $timezone = getAirportTimezone($airport);
            $tz = new DateTimeZone($timezone);
            $obsDate = new DateTime('@' . $obsTimestamp);
            $obsDate->setTimezone($tz);
            $dateKey = $obsDate->format('Y-m-d');
        } else {
            $dateKey = $airport !== null ? getAirportDateKey($airport) : gmdate('Y-m-d');
        }

        $fp = @fopen($file, 'c+');
        if ($fp === false) {
            aviationwx_log('warning', 'temp extremes file open failed', [
                'airport' => $airportId,
                'file' => basename($file)
            ], 'app');
            return;
        }

        if (!@flock($fp, LOCK_EX)) {
            @fclose($fp);
            aviationwx_log('warning', 'temp extremes file lock failed', [
                'airport' => $airportId,
                'file' => basename($file)
            ], 'app');
            return;
        }

        $tempExtremes = [];
        $fileSize = @filesize($file);
        if ($fileSize > 0) {
            $content = @stream_get_contents($fp);
            if ($content !== false && $content !== '') {
                $decoded = @json_decode($content, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $tempExtremes = $decoded;
                } else {
                    aviationwx_log('warning', 'temp extremes file corrupted - recreating', [
                        'airport' => $airportId,
                        'json_error' => json_last_error_msg()
                    ], 'app');
                }
            }
        }

        $currentDate = new DateTime($dateKey);
        foreach ($tempExtremes as $key => $value) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $key)) {
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

        $entry = $tempExtremes[$dateKey] ?? null;
        $storedHigh = null;
        $storedLow = null;
        $existingLowTs = null;
        if (is_array($entry)) {
            $storedHigh = is_numeric($entry['high']) ? (float)$entry['high'] : $currentTempFloat;
            $storedLow = is_numeric($entry['low']) ? (float)$entry['low'] : $currentTempFloat;
            $existingLowTs = $entry['low_ts'] ?? $obsTs;
        }

        if (!isset($tempExtremes[$dateKey])) {
            aviationwx_log('info', 'initializing new day temp extremes', [
                'airport' => $airportId,
                'date_key' => $dateKey,
                'temp' => $currentTempFloat,
                'obs_ts' => $obsTs
            ], 'app');
            clearWeatherCache($airportId);
            $tempExtremes[$dateKey] = [
                'high' => $currentTempFloat,
                'low' => $currentTempFloat,
                'high_ts' => $obsTs,
                'low_ts' => $obsTs
            ];
        } else {
            if ($currentTempFloat > $storedHigh) {
                $tempExtremes[$dateKey]['high'] = $currentTempFloat;
                $tempExtremes[$dateKey]['high_ts'] = $obsTs;
            }
            if ($currentTempFloat < $storedLow) {
                $tempExtremes[$dateKey]['low'] = $currentTempFloat;
                $tempExtremes[$dateKey]['low_ts'] = $obsTs;
            } elseif ($currentTempFloat == $storedLow && $obsTs < $existingLowTs) {
                $tempExtremes[$dateKey]['low_ts'] = $obsTs;
            }
        }

        @ftruncate($fp, 0);
        @rewind($fp);
        $jsonData = json_encode($tempExtremes);
        if ($jsonData !== false) {
            @fwrite($fp, $jsonData);
            @fflush($fp);
        }

        @flock($fp, LOCK_UN);
        @fclose($fp);
    } catch (Exception $e) {
        error_log("Error updating temp extremes: " . $e->getMessage());
    }
}

/**
 * Get today's high and low temperatures for an airport
 *
 * Tries per-airport file first, then legacy single-file format.
 *
 * @param string $airportId Airport identifier (e.g., 'kspb')
 * @param float $currentTemp Current temperature value (used as fallback if no data)
 * @param array|null $airport Airport configuration array (optional, for timezone)
 * @return array {
 *   'high' => float|null,
 *   'low' => float|null,
 *   'high_ts' => int|null,
 *   'low_ts' => int|null
 * }
 */
function getTempExtremes($airportId, $currentTemp, $airport = null) {
    $dateKey = $airport !== null ? getAirportDateKey($airport) : gmdate('Y-m-d');
    $hasValidCurrentTemp = is_numeric($currentTemp);
    $currentTempFloat = $hasValidCurrentTemp ? (float)$currentTemp : null;
    $noDataReturn = [
        'high' => null,
        'low' => null,
        'high_ts' => null,
        'low_ts' => null
    ];

    $file = getTempExtremesTrackingPath($airportId);
    if (file_exists($file)) {
        $content = @file_get_contents($file);
        if ($content !== false) {
            $decoded = @json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $entry = $decoded[$dateKey] ?? null;
                if (is_array($entry)) {
                    $storedHigh = isset($entry['high']) && is_numeric($entry['high']) ? (float)$entry['high'] : $currentTempFloat;
                    $storedLow = isset($entry['low']) && is_numeric($entry['low']) ? (float)$entry['low'] : $currentTempFloat;
                    return [
                        'high' => $storedHigh,
                        'low' => $storedLow,
                        'high_ts' => $entry['high_ts'] ?? ($hasValidCurrentTemp ? time() : null),
                        'low_ts' => $entry['low_ts'] ?? ($hasValidCurrentTemp ? time() : null)
                    ];
                }
            }
        }
    }

    if (!file_exists(CACHE_TEMP_EXTREMES_FILE)) {
        if ($airport !== null) {
            $timezone = getAirportTimezone($airport);
            $computed = computeDailyExtremesFromHistory($airportId, $dateKey, $timezone);
            if ($computed['temp_high'] !== null || $computed['temp_low'] !== null) {
                return [
                    'high' => $computed['temp_high'] ?? $currentTempFloat,
                    'low' => $computed['temp_low'] ?? $currentTempFloat,
                    'high_ts' => $computed['temp_high_ts'],
                    'low_ts' => $computed['temp_low_ts']
                ];
            }
        }
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

    $content = @file_get_contents(CACHE_TEMP_EXTREMES_FILE);
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

    $decoded = @json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
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

    $stored = $decoded[$dateKey][$airportId] ?? null;
    if (is_array($stored)) {
        $storedHigh = isset($stored['high']) && is_numeric($stored['high']) ? (float)$stored['high'] : $currentTempFloat;
        $storedLow = isset($stored['low']) && is_numeric($stored['low']) ? (float)$stored['low'] : $currentTempFloat;
        return [
            'high' => $storedHigh,
            'low' => $storedLow,
            'high_ts' => $stored['high_ts'] ?? ($hasValidCurrentTemp ? time() : null),
            'low_ts' => $stored['low_ts'] ?? ($hasValidCurrentTemp ? time() : null)
        ];
    }

    // Fallback: Compute from weather history when daily tracking is empty
    if ($airport !== null) {
        $timezone = getAirportTimezone($airport);
        $computed = computeDailyExtremesFromHistory($airportId, $dateKey, $timezone);
        if ($computed['temp_high'] !== null || $computed['temp_low'] !== null) {
            return [
                'high' => $computed['temp_high'] ?? $currentTempFloat,
                'low' => $computed['temp_low'] ?? $currentTempFloat,
                'high_ts' => $computed['temp_high_ts'],
                'low_ts' => $computed['temp_low_ts']
            ];
        }
    }

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

