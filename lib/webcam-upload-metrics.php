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
 * Uses lightweight counter approach following existing metrics.php pattern.
 * Counters are flushed to hourly JSON files every 5 minutes.
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @return void
 */
function trackWebcamUploadAccepted(string $airportId, int $camIndex): void {
    if (!function_exists('apcu_enabled') || !apcu_enabled()) {
        return; // APCu not available
    }
    
    require_once __DIR__ . '/metrics.php';
    
    $airportId = strtolower($airportId);
    
    // Use lightweight counter (follows metrics.php pattern)
    metrics_increment("webcam_{$airportId}_{$camIndex}_uploads_accepted");
    metrics_increment("webcam_uploads_accepted_global");
}

/**
 * Track rejected upload for a camera
 * 
 * Uses lightweight counter approach following existing metrics.php pattern.
 * Counters are flushed to hourly JSON files every 5 minutes.
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @param string $reason Rejection reason (e.g., 'error_frame', 'exif_invalid', 'size_limit')
 * @return void
 */
function trackWebcamUploadRejected(string $airportId, int $camIndex, string $reason): void {
    if (!function_exists('apcu_enabled') || !apcu_enabled()) {
        return; // APCu not available
    }
    
    require_once __DIR__ . '/metrics.php';
    
    $airportId = strtolower($airportId);
    $reason = strtolower($reason);
    
    // Track per-camera rejections
    metrics_increment("webcam_{$airportId}_{$camIndex}_uploads_rejected");
    
    // Track per-camera rejection reasons  
    metrics_increment("webcam_{$airportId}_{$camIndex}_rejection_{$reason}");
    
    // Track global totals
    metrics_increment("webcam_uploads_rejected_global");
    metrics_increment("webcam_rejection_reason_{$reason}_global");
}

/**
 * Get upload metrics for a camera
 * 
 * Retrieves accepted/rejected counts from rolling 24-hour metrics.
 * Follows existing metrics.php pattern: reads from hourly JSON files + current APCu.
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @return array {
 *   'accepted' => int,
 *   'rejected' => int,
 *   'rejection_reasons' => array (reason => count),
 *   'rejection_rate' => float
 * }
 */
function getWebcamUploadMetrics(string $airportId, int $camIndex): array {
    require_once __DIR__ . '/metrics.php';
    
    $airportId = strtolower($airportId);
    $webcamKey = "webcam_{$airportId}_{$camIndex}";
    
    $metrics = [
        'accepted' => 0,
        'rejected' => 0,
        'rejection_reasons' => [],
        'rejection_rate' => 0.0
    ];
    
    // Get 24-hour rolling metrics (follows metrics_get_rolling pattern)
    $now = time();
    
    // Read yesterday's hourly files
    $yesterday = gmdate('Y-m-d', $now - 86400);
    $currentHour = (int)gmdate('H', $now);
    
    for ($h = $currentHour + 1; $h < 24; $h++) {
        $hourId = $yesterday . '-' . sprintf('%02d', $h);
        $metrics = aggregateWebcamMetricsFromHour($hourId, $webcamKey, $metrics);
    }
    
    // Read today's hourly files  
    $today = gmdate('Y-m-d', $now);
    for ($h = 0; $h <= $currentHour; $h++) {
        $hourId = $today . '-' . sprintf('%02d', $h);
        $metrics = aggregateWebcamMetricsFromHour($hourId, $webcamKey, $metrics);
    }
    
    // Add current APCu counters (not yet flushed)
    if (function_exists('apcu_enabled') && apcu_enabled()) {
        $accepted = metrics_get("{$webcamKey}_uploads_accepted");
        $rejected = metrics_get("{$webcamKey}_uploads_rejected");
        
        $metrics['accepted'] += $accepted;
        $metrics['rejected'] += $rejected;
        
        // Get rejection reasons from APCu
        $allCounters = metrics_get_all();
        foreach ($allCounters as $key => $value) {
            if (preg_match("/^{$webcamKey}_rejection_(.+)$/", $key, $m)) {
                $reason = $m[1];
                if (!isset($metrics['rejection_reasons'][$reason])) {
                    $metrics['rejection_reasons'][$reason] = 0;
                }
                $metrics['rejection_reasons'][$reason] += $value;
            }
        }
    }
    
    // Calculate rejection rate
    $total = $metrics['accepted'] + $metrics['rejected'];
    $metrics['rejection_rate'] = $total > 0 ? ($metrics['rejected'] / $total) : 0.0;
    
    return $metrics;
}

/**
 * Aggregate webcam metrics from a single hour file
 * 
 * Helper function for getWebcamUploadMetrics.
 * 
 * @param string $hourId Hour ID (e.g., '2026-01-13-14')
 * @param string $webcamKey Webcam key (e.g., 'webcam_kspb_0')
 * @param array $metrics Existing metrics to add to
 * @return array Updated metrics
 */
function aggregateWebcamMetricsFromHour(string $hourId, string $webcamKey, array $metrics): array {
    require_once __DIR__ . '/cache-paths.php';
    
    $hourFile = getMetricsHourlyPath($hourId);
    if (!file_exists($hourFile)) {
        return $metrics;
    }
    
    $content = @file_get_contents($hourFile);
    if ($content === false) {
        return $metrics;
    }
    
    $hourData = @json_decode($content, true);
    if (!is_array($hourData)) {
        return $metrics;
    }
    
    // Check if this hour has webcam upload metrics
    if (isset($hourData['webcam_uploads'][$webcamKey])) {
        $webcamData = $hourData['webcam_uploads'][$webcamKey];
        $metrics['accepted'] += $webcamData['accepted'] ?? 0;
        $metrics['rejected'] += $webcamData['rejected'] ?? 0;
        
        foreach ($webcamData['rejection_reasons'] ?? [] as $reason => $count) {
            if (!isset($metrics['rejection_reasons'][$reason])) {
                $metrics['rejection_reasons'][$reason] = 0;
            }
            $metrics['rejection_reasons'][$reason] += $count;
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
