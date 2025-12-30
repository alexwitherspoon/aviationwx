<?php
/**
 * Data Outage Detection
 * 
 * Checks if all configured data sources are stale (data outage condition).
 * Uses outage state file to persist outage start time across cache loss and brief recoveries.
 * Preserves original outage start time during grace period (1.5 hours) after recovery
 * to handle back-to-back outages as a single continuous event.
 * 
 * Logs outage start when first detected and outage end when fully resolved (after grace period).
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/../logger.php';
require_once __DIR__ . '/source-timestamps.php';

/**
 * Check if all configured data sources are stale (data outage condition)
 * 
 * Checks all configured sources (primary weather, METAR, webcams) and determines
 * if they are all stale (older than failclosed threshold). Uses outage state file
 * to persist outage start time across cache loss and brief recoveries. Preserves original
 * outage start time during grace period (same as failclosed threshold) after recovery 
 * to handle back-to-back outages as a single continuous event.
 * 
 * Logs outage start when first detected and outage end when fully resolved (after grace period).
 * 
 * @param string $airportId Airport identifier
 * @param array $airport Airport configuration array
 * @return array|null Returns array with 'newest_timestamp' if all sources are stale, null otherwise
 */
function checkDataOutageStatus(string $airportId, array $airport): ?array {
    // Don't show outage banner if airport is in maintenance mode
    if (isAirportInMaintenance($airport)) {
        return null;
    }
    
    // Use failclosed threshold for outage detection
    $outageThresholdSeconds = getStaleFailclosedSeconds($airport);
    $now = time();
    
    // Get timestamps from all configured sources using shared helper
    $sourceTimestamps = getSourceTimestamps($airportId, $airport);
    
    // Build sources array and determine staleness
    $sources = [];
    $newestTimestamp = 0;
    
    // Check primary weather source
    if ($sourceTimestamps['primary']['available']) {
        $primaryTimestamp = $sourceTimestamps['primary']['timestamp'];
        $primaryAge = $sourceTimestamps['primary']['age'];
        $isStale = ($primaryTimestamp === 0) || ($primaryAge >= $outageThresholdSeconds);
        
        $sources['primary'] = [
            'timestamp' => $primaryTimestamp,
            'age' => $primaryAge,
            'stale' => $isStale
        ];
        
        if ($primaryTimestamp > $newestTimestamp) {
            $newestTimestamp = $primaryTimestamp;
        }
    }
    
    // Check METAR source
    if ($sourceTimestamps['metar']['available']) {
        $metarTimestamp = $sourceTimestamps['metar']['timestamp'];
        $metarAge = $sourceTimestamps['metar']['age'];
        $isStale = ($metarTimestamp === 0) || ($metarAge >= $outageThresholdSeconds);
        
        $sources['metar'] = [
            'timestamp' => $metarTimestamp,
            'age' => $metarAge,
            'stale' => $isStale
        ];
        
        if ($metarTimestamp > $newestTimestamp) {
            $newestTimestamp = $metarTimestamp;
        }
    }
    
    // Check webcams
    if ($sourceTimestamps['webcams']['available']) {
        $webcamNewestTimestamp = $sourceTimestamps['webcams']['newest_timestamp'];
        $webcamStaleCount = $sourceTimestamps['webcams']['stale_count'];
        $webcamTotal = $sourceTimestamps['webcams']['total'];
        
        // Determine if all webcams are stale (no timestamp or age exceeds threshold)
        $allWebcamsStale = false;
        if ($webcamStaleCount === $webcamTotal) {
            $allWebcamsStale = true;
        } elseif ($webcamNewestTimestamp > 0) {
            $webcamAge = $now - $webcamNewestTimestamp;
            $allWebcamsStale = ($webcamAge >= $outageThresholdSeconds);
        } else {
            // No timestamps available but some webcams exist - treat as stale
            $allWebcamsStale = true;
        }
        
        $sources['webcams'] = [
            'stale' => $allWebcamsStale,
            'total' => $webcamTotal,
            'stale_count' => $webcamStaleCount
        ];
        
        if ($allWebcamsStale && $webcamNewestTimestamp > 0) {
            if ($webcamNewestTimestamp > $newestTimestamp) {
                $newestTimestamp = $webcamNewestTimestamp;
            }
        }
    }
    
    // Check if ALL configured sources are stale
    $allStale = true;
    foreach ($sources as $sourceName => $sourceData) {
        if ($sourceName === 'webcams') {
            // For webcams, check if all are stale
            if (!$sourceData['stale']) {
                $allStale = false;
                break;
            }
        } else {
            // For weather sources, check stale flag
            if (!$sourceData['stale']) {
                $allStale = false;
                break;
            }
        }
    }
    
    // If no sources configured, don't show banner
    if (empty($sources)) {
        return null;
    }
    
    // Handle outage state file for persistence across cache loss
    $outageStateFile = __DIR__ . '/../../cache/outage_' . $airportId . '.json';
    
    if ($allStale) {
        // All sources are stale - check/create outage state file
        $outageStart = 0;
        $isNewOutage = !file_exists($outageStateFile);
        
        if (!$isNewOutage) {
            // Use @ to suppress errors for non-critical file operations
            // We handle failures explicitly with fallback mechanisms below
            $outageState = @json_decode(@file_get_contents($outageStateFile), true);
            if (is_array($outageState) && isset($outageState['outage_start']) && $outageState['outage_start'] > 0) {
                // Preserve original outage start time across brief recoveries
                $outageStart = (int)$outageState['outage_start'];
            }
        }
        
        // If no existing outage start, use newest timestamp from stale sources
        if ($outageStart === 0 && $newestTimestamp > 0) {
            $outageStart = $newestTimestamp;
        }
        
        // Fallback: try webcam cache file modification times if no timestamp available
        if ($outageStart === 0 && isset($airport['webcams']) && is_array($airport['webcams']) && count($airport['webcams']) > 0) {
            $webcamNewestMtime = 0;
            foreach ($airport['webcams'] as $index => $cam) {
                foreach (['jpg', 'webp'] as $format) {
                    $filePath = getCacheSymlinkPath($airportId, $index, $format);
                    if (file_exists($filePath)) {
                        // Use @ to suppress errors for non-critical file operations
                        // We handle failures explicitly with fallback mechanisms below
                        $mtime = @filemtime($filePath);
                        if ($mtime !== false && $mtime > 0 && $mtime > $webcamNewestMtime) {
                            $webcamNewestMtime = $mtime;
                        }
                    }
                }
            }
            if ($webcamNewestMtime > 0) {
                $outageStart = $webcamNewestMtime;
            }
        }
        
        // Final fallback: use current time if no other timestamp available
        if ($outageStart === 0) {
            $outageStart = $now;
        }
        
        // Write/update outage state file
        $outageState = [
            'outage_start' => $outageStart,
            'last_checked' => $now
        ];
        @file_put_contents($outageStateFile, json_encode($outageState), LOCK_EX);
        
        // Log outage start only for new outages
        if ($isNewOutage) {
            aviationwx_log('info', 'data outage detected', [
                'airport' => $airportId,
                'outage_start' => $outageStart,
                'outage_start_iso' => date('c', $outageStart),
                'sources_affected' => array_keys($sources)
            ], 'app');
        }
        
        return [
            'newest_timestamp' => $outageStart,
            'all_stale' => true
        ];
    } else {
        // At least one source is fresh - check for recovery and cleanup
        if (file_exists($outageStateFile)) {
            // Use @ to suppress errors for non-critical file operations
            // We handle failures explicitly with fallback mechanisms below
            $outageState = @json_decode(@file_get_contents($outageStateFile), true);
            if (is_array($outageState) && isset($outageState['last_checked']) && isset($outageState['outage_start'])) {
                $lastChecked = (int)$outageState['last_checked'];
                $outageStart = (int)$outageState['outage_start'];
                $timeSinceLastCheck = $now - $lastChecked;
                $gracePeriodSeconds = getStaleFailclosedSeconds($airport);
                
                // If more than grace period has passed, delete file (full recovery confirmed)
                if ($timeSinceLastCheck >= $gracePeriodSeconds) {
                    $duration = $now - $outageStart;
                    aviationwx_log('info', 'data outage resolved', [
                        'airport' => $airportId,
                        'outage_start' => $outageStart,
                        'outage_start_iso' => date('c', $outageStart),
                        'outage_end' => $now,
                        'outage_end_iso' => date('c', $now),
                        'outage_duration_seconds' => $duration,
                        'outage_duration_hours' => round($duration / 3600, 2)
                    ], 'app');
                    @unlink($outageStateFile);
                } else {
                    // Within grace period - update last_checked but keep file to handle brief recoveries
                    $outageState['last_checked'] = $now;
                    @file_put_contents($outageStateFile, json_encode($outageState), LOCK_EX);
                }
            } else {
                // Invalid outage state file - delete it
                @unlink($outageStateFile);
            }
        }
        
        return null; // No banner when sources are fresh
    }
}

