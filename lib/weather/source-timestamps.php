<?php
/**
 * Source Timestamp Extraction
 * 
 * Shared utility for extracting timestamps from all configured data sources.
 * Used by both outage detection and status page to ensure consistency.
 */

require_once __DIR__ . '/../cache-paths.php';
require_once __DIR__ . '/../webcam-metadata.php';

/**
 * Get timestamps for all configured data sources for an airport
 *
 * Webcam timestamps: per-camera last-completed frame (see webcam_get_last_completed_timestamp_for_freshness);
 * newest_timestamp is the maximum across cameras.
 *
 * @param string $airportId Airport identifier
 * @param array $airport Airport configuration array
 * @return array {
 *   'primary' => array{
 *     'timestamp' => int,
 *     'age' => int,
 *     'available' => bool
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
    
    // Determine source availability from unified weather_sources array
    $hasPrimary = false;
    $hasBackup = false;
    $hasMetar = false;
    
    if (isset($airport['weather_sources']) && is_array($airport['weather_sources'])) {
        foreach ($airport['weather_sources'] as $source) {
            if (empty($source['type'])) {
                continue;
            }
            
            if ($source['type'] === 'metar') {
                $hasMetar = true;
            } elseif (!empty($source['backup'])) {
                $hasBackup = true;
            } else {
                $hasPrimary = true;
            }
        }
    }
    
    // Read weather cache once when any source needs it
    $weatherData = null;
    if ($hasPrimary || $hasBackup || $hasMetar) {
        $weatherCacheFile = getWeatherCachePath($airportId);
        if (file_exists($weatherCacheFile)) {
            $content = @file_get_contents($weatherCacheFile);
            if ($content !== false) {
                $weatherData = @json_decode($content, true);
            }
        }
    }

    if ($hasPrimary) {
        $result['primary']['available'] = true;
        if (is_array($weatherData)) {
            $primaryTimestamp = 0;
            if (isset($weatherData['obs_time_primary']) && $weatherData['obs_time_primary'] > 0) {
                $primaryTimestamp = (int)$weatherData['obs_time_primary'];
            }
            if ($primaryTimestamp > 0) {
                $result['primary']['timestamp'] = $primaryTimestamp;
                $result['primary']['age'] = $now - $primaryTimestamp;
            }
        }
    }

    if ($hasBackup) {
        $result['backup']['available'] = true;
        if (is_array($weatherData)) {
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

    if ($hasMetar) {
        $result['metar']['available'] = true;
        if (is_array($weatherData)) {
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
    
    // Check all webcams (if configured)
    if (isset($airport['webcams']) && is_array($airport['webcams']) && count($airport['webcams']) > 0) {
        $result['webcams']['available'] = true;
        $result['webcams']['total'] = count($airport['webcams']);
        $webcamStaleCount = 0;
        $webcamNewestTimestamp = 0;
        
        foreach ($airport['webcams'] as $index => $cam) {
            $webcamTimestamp = webcam_get_last_completed_timestamp_for_freshness($airportId, $index);

            if ($webcamTimestamp > 0) {
                if ($webcamTimestamp > $webcamNewestTimestamp) {
                    $webcamNewestTimestamp = $webcamTimestamp;
                }
            } else {
                $webcamStaleCount++;
            }
        }
        
        $result['webcams']['newest_timestamp'] = $webcamNewestTimestamp;
        $result['webcams']['stale_count'] = $webcamStaleCount;
        // all_stale is determined by caller based on threshold
    }
    
    return $result;
}

