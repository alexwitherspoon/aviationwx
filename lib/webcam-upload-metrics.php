<?php
/**
 * Webcam Upload Metrics Tracking
 * 
 * Tracks accepted/rejected uploads per camera using APCu cache.
 * Provides metrics for monitoring upload health on status page.
 */

/**
 * Track accepted upload for a camera
 * 
 * Increments counter in APCu for accepted uploads in last hour.
 * Uses sliding window approach with timestamp bucketing.
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @return void
 */
function trackWebcamUploadAccepted($airportId, $camIndex) {
    if (!function_exists('apcu_enabled') || !apcu_enabled()) {
        return; // APCu not available
    }
    
    $now = time();
    $key = "webcam:upload:accepted:{$airportId}:{$camIndex}";
    
    // Store as array of timestamps for accurate 1-hour sliding window
    $data = apcu_fetch($key);
    if ($data === false) {
        $data = [];
    }
    
    // Add current timestamp
    $data[] = $now;
    
    // Remove timestamps older than 1 hour
    $oneHourAgo = $now - 3600;
    $data = array_filter($data, function($timestamp) use ($oneHourAgo) {
        return $timestamp > $oneHourAgo;
    });
    
    // Store back to cache (expire after 2 hours to auto-cleanup)
    apcu_store($key, $data, 7200);
}

/**
 * Track rejected upload for a camera
 * 
 * Increments counter in APCu for rejected uploads in last hour.
 * Also tracks rejection reason for diagnostics.
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @param string $reason Rejection reason (e.g., 'error_frame', 'exif_invalid', 'size_limit')
 * @return void
 */
function trackWebcamUploadRejected($airportId, $camIndex, $reason) {
    if (!function_exists('apcu_enabled') || !apcu_enabled()) {
        return; // APCu not available
    }
    
    $now = time();
    $key = "webcam:upload:rejected:{$airportId}:{$camIndex}";
    
    // Store as array of [timestamp, reason] tuples for diagnostics
    $data = apcu_fetch($key);
    if ($data === false) {
        $data = [];
    }
    
    // Add current rejection
    $data[] = [$now, $reason];
    
    // Remove rejections older than 1 hour
    $oneHourAgo = $now - 3600;
    $data = array_filter($data, function($entry) use ($oneHourAgo) {
        return $entry[0] > $oneHourAgo;
    });
    
    // Store back to cache (expire after 2 hours to auto-cleanup)
    apcu_store($key, $data, 7200);
}

/**
 * Get upload metrics for a camera
 * 
 * Retrieves accepted/rejected counts for the last hour.
 * Returns structured data for display on status page.
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @return array {
 *   'accepted' => int,
 *   'rejected' => int,
 *   'rejection_reasons' => array (reason => count)
 * }
 */
function getWebcamUploadMetrics($airportId, $camIndex) {
    $metrics = [
        'accepted' => 0,
        'rejected' => 0,
        'rejection_reasons' => []
    ];
    
    if (!function_exists('apcu_enabled') || !apcu_enabled()) {
        return $metrics; // APCu not available
    }
    
    // Get accepted count
    $acceptedKey = "webcam:upload:accepted:{$airportId}:{$camIndex}";
    $accepted = apcu_fetch($acceptedKey);
    if ($accepted !== false && is_array($accepted)) {
        // Filter to last hour (defensive check)
        $oneHourAgo = time() - 3600;
        $accepted = array_filter($accepted, function($timestamp) use ($oneHourAgo) {
            return $timestamp > $oneHourAgo;
        });
        $metrics['accepted'] = count($accepted);
    }
    
    // Get rejected count and reasons
    $rejectedKey = "webcam:upload:rejected:{$airportId}:{$camIndex}";
    $rejected = apcu_fetch($rejectedKey);
    if ($rejected !== false && is_array($rejected)) {
        // Filter to last hour (defensive check)
        $oneHourAgo = time() - 3600;
        $rejected = array_filter($rejected, function($entry) use ($oneHourAgo) {
            return $entry[0] > $oneHourAgo;
        });
        $metrics['rejected'] = count($rejected);
        
        // Count rejection reasons
        foreach ($rejected as $entry) {
            $reason = $entry[1];
            if (!isset($metrics['rejection_reasons'][$reason])) {
                $metrics['rejection_reasons'][$reason] = 0;
            }
            $metrics['rejection_reasons'][$reason]++;
        }
    }
    
    return $metrics;
}

/**
 * Get all upload metrics for an airport
 * 
 * Retrieves upload metrics for all cameras at an airport.
 * Used for status page summary statistics.
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $numCameras Number of cameras at airport
 * @return array Array of metrics per camera index
 */
function getAirportUploadMetrics($airportId, $numCameras) {
    $allMetrics = [];
    
    for ($i = 0; $i < $numCameras; $i++) {
        $allMetrics[$i] = getWebcamUploadMetrics($airportId, $i);
    }
    
    return $allMetrics;
}

/**
 * Format upload metrics for display
 * 
 * Formats upload metrics into human-readable text.
 * Used on status page for camera health display.
 * 
 * @param array $metrics Metrics array from getWebcamUploadMetrics()
 * @return string Formatted metrics string (e.g., "Accepted: 15 • Rejected: 2")
 */
function formatUploadMetrics($metrics) {
    $accepted = $metrics['accepted'] ?? 0;
    $rejected = $metrics['rejected'] ?? 0;
    
    if ($accepted === 0 && $rejected === 0) {
        return 'No uploads (last 1h)';
    }
    
    $parts = [];
    if ($accepted > 0) {
        $parts[] = "Accepted: {$accepted}";
    }
    if ($rejected > 0) {
        $parts[] = "Rejected: {$rejected}";
    }
    
    return implode(' • ', $parts) . ' (last 1h)';
}
