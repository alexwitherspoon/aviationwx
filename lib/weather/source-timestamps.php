<?php
/**
 * Source Timestamp Extraction
 * 
 * Shared utility for extracting timestamps from all configured data sources.
 * Used by both outage detection and status page to ensure consistency.
 */

require_once __DIR__ . '/../cache-paths.php';

/**
 * Get timestamps for all configured data sources for an airport
 * 
 * Extracts timestamps from primary weather, METAR, and webcam sources.
 * Handles missing files, corrupted data, and missing timestamps gracefully.
 * 
 * @param string $airportId Airport identifier
 * @param array $airport Airport configuration array
 * @return array {
 *   'primary' => array{
 *     'timestamp' => int,      // Unix timestamp or 0 if unavailable
 *     'age' => int,            // Seconds since timestamp (PHP_INT_MAX if unavailable)
 *     'available' => bool       // Whether source is configured
 *   },
 *   'backup' => array{
 *     'timestamp' => int,
 *     'age' => int,
 *     'available' => bool
 *   },
 *   'metar' => array{
 *     'timestamp' => int,
 *     'age' => int,
 *     'available' => bool
 *   },
 *   'webcams' => array{
 *     'newest_timestamp' => int,
 *     'all_stale' => bool,
 *     'total' => int,
 *     'stale_count' => int,
 *     'available' => bool
 *   }
 * }
 */
function getSourceTimestamps(string $airportId, array $airport): array {
    $now = time();
    $result = [
        'primary' => [
            'timestamp' => 0,
            'age' => PHP_INT_MAX,
            'available' => false
        ],
        'backup' => [
            'timestamp' => 0,
            'age' => PHP_INT_MAX,
            'available' => false
        ],
        'metar' => [
            'timestamp' => 0,
            'age' => PHP_INT_MAX,
            'available' => false
        ],
        'webcams' => [
            'newest_timestamp' => 0,
            'all_stale' => false,
            'total' => 0,
            'stale_count' => 0,
            'available' => false
        ]
    ];
    
    // Check primary weather source (if configured)
    if (isset($airport['weather_source']) && !empty($airport['weather_source'])) {
        $result['primary']['available'] = true;
        $weatherCacheFile = getWeatherCachePath($airportId);
        
        if (file_exists($weatherCacheFile)) {
            // Use @ to suppress errors for non-critical file operations
            // We handle failures explicitly with fallback mechanisms below
            $weatherData = @json_decode(@file_get_contents($weatherCacheFile), true);
            if (is_array($weatherData)) {
                // Check primary source timestamp
                $primaryTimestamp = 0;
                if (isset($weatherData['obs_time_primary']) && $weatherData['obs_time_primary'] > 0) {
                    $primaryTimestamp = (int)$weatherData['obs_time_primary'];
                } elseif (isset($weatherData['last_updated_primary']) && $weatherData['last_updated_primary'] > 0) {
                    $primaryTimestamp = (int)$weatherData['last_updated_primary'];
                }
                
                if ($primaryTimestamp > 0) {
                    $result['primary']['timestamp'] = $primaryTimestamp;
                    $result['primary']['age'] = $now - $primaryTimestamp;
                }
            }
        }
    }
    
    // Check backup weather source (if configured)
    if (isset($airport['weather_source_backup']) && !empty($airport['weather_source_backup'])) {
        $result['backup']['available'] = true;
        $weatherCacheFile = getWeatherCachePath($airportId);
        
        if (file_exists($weatherCacheFile)) {
            // Use @ to suppress errors for non-critical file operations
            // We handle failures explicitly with fallback mechanisms below
            $weatherData = @json_decode(@file_get_contents($weatherCacheFile), true);
            if (is_array($weatherData)) {
                // Check backup source timestamp
                $backupTimestamp = 0;
                if (isset($weatherData['obs_time_backup']) && $weatherData['obs_time_backup'] > 0) {
                    $backupTimestamp = (int)$weatherData['obs_time_backup'];
                } elseif (isset($weatherData['last_updated_backup']) && $weatherData['last_updated_backup'] > 0) {
                    $backupTimestamp = (int)$weatherData['last_updated_backup'];
                }
                
                if ($backupTimestamp > 0) {
                    $result['backup']['timestamp'] = $backupTimestamp;
                    $result['backup']['age'] = $now - $backupTimestamp;
                }
            }
        }
    }
    
    // Check METAR source (if configured)
    // METAR is configured if metar_station exists OR weather_source.type === 'metar'
    $hasMetar = false;
    if (isset($airport['metar_station']) && !empty($airport['metar_station'])) {
        $hasMetar = true;
    } elseif (isset($airport['weather_source']['type']) && $airport['weather_source']['type'] === 'metar') {
        $hasMetar = true;
    }
    
    if ($hasMetar) {
        $result['metar']['available'] = true;
        $weatherCacheFile = getWeatherCachePath($airportId);
        
        if (file_exists($weatherCacheFile)) {
            // Use @ to suppress errors for non-critical file operations
            // We handle failures explicitly with fallback mechanisms below
            $weatherData = @json_decode(@file_get_contents($weatherCacheFile), true);
            if (is_array($weatherData)) {
                // Check METAR source timestamp
                $metarTimestamp = 0;
                if (isset($weatherData['obs_time_metar']) && $weatherData['obs_time_metar'] > 0) {
                    $metarTimestamp = (int)$weatherData['obs_time_metar'];
                } elseif (isset($weatherData['last_updated_metar']) && $weatherData['last_updated_metar'] > 0) {
                    $metarTimestamp = (int)$weatherData['last_updated_metar'];
                }
                
                if ($metarTimestamp > 0) {
                    $result['metar']['timestamp'] = $metarTimestamp;
                    $result['metar']['age'] = $now - $metarTimestamp;
                }
            }
        }
    }
    
    // Check all webcams (if configured)
    if (isset($airport['webcams']) && is_array($airport['webcams']) && count($airport['webcams']) > 0) {
        $result['webcams']['available'] = true;
        $result['webcams']['total'] = count($airport['webcams']);
        $webcamStaleCount = 0;
        $webcamNewestTimestamp = 0;
        
        foreach ($airport['webcams'] as $index => $cam) {
            $webcamTimestamp = 0;
            
            // Try to get timestamp from JPG or WebP file using new path structure
            foreach (['jpg', 'webp'] as $format) {
                $filePath = getCacheSymlinkPath($airportId, $index, $format);
                if (file_exists($filePath)) {
                    // Try EXIF first if available, then fallback to filemtime
                    if (function_exists('exif_read_data')) {
                        // Use @ to suppress errors for non-critical EXIF operations
                        // We handle failures explicitly with fallback to filemtime below
                        $exif = @exif_read_data($filePath, 'EXIF', true);
                        if ($exif !== false && isset($exif['EXIF']['DateTimeOriginal'])) {
                            $dateTime = $exif['EXIF']['DateTimeOriginal'];
                            // Parse EXIF date format: "YYYY:MM:DD HH:MM:SS"
                            $timestamp = @strtotime(str_replace(':', '-', substr($dateTime, 0, 10)) . ' ' . substr($dateTime, 11));
                            if ($timestamp !== false && $timestamp > 0) {
                                $webcamTimestamp = (int)$timestamp;
                            }
                        }
                        // Also check main EXIF array (some cameras store it there)
                        if ($webcamTimestamp === 0 && isset($exif['DateTimeOriginal'])) {
                            $dateTime = $exif['DateTimeOriginal'];
                            $timestamp = @strtotime(str_replace(':', '-', substr($dateTime, 0, 10)) . ' ' . substr($dateTime, 11));
                            if ($timestamp !== false && $timestamp > 0) {
                                $webcamTimestamp = (int)$timestamp;
                            }
                        }
                    }
                    
                    // Fallback to file modification time if EXIF not available or failed
                    if ($webcamTimestamp === 0) {
                        // Use @ to suppress errors for non-critical file operations
                        // We handle failures explicitly by leaving timestamp as 0
                        $mtime = @filemtime($filePath);
                        if ($mtime !== false && $mtime > 0) {
                            $webcamTimestamp = (int)$mtime;
                        }
                    }
                    
                    if ($webcamTimestamp > 0) {
                        break;
                    }
                }
            }
            
            if ($webcamTimestamp > 0) {
                if ($webcamTimestamp > $webcamNewestTimestamp) {
                    $webcamNewestTimestamp = $webcamTimestamp;
                }
                // Note: We don't check staleness here - that's done by the caller
                // We just track if timestamp is available
            } else {
                // No webcam file or timestamp - count as stale
                $webcamStaleCount++;
            }
        }
        
        $result['webcams']['newest_timestamp'] = $webcamNewestTimestamp;
        $result['webcams']['stale_count'] = $webcamStaleCount;
        // all_stale is determined by caller based on threshold
    }
    
    return $result;
}

