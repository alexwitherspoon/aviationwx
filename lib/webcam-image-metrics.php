<?php
/**
 * Webcam Image Metrics Tracking
 * 
 * Tracks verified/rejected images per camera using APCu cache.
 * Applies to all webcam types (push uploads and pull fetches).
 * Provides metrics for monitoring camera health on status page.
 */

/**
 * Track verified image for a camera
 * 
 * Called when an image passes all validation checks.
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @return void
 */
function trackWebcamImageVerified(string $airportId, int $camIndex): void {
    if (!function_exists('apcu_enabled') || !apcu_enabled()) {
        return;
    }
    
    require_once __DIR__ . '/metrics.php';
    
    $airportId = strtolower($airportId);
    
    metrics_increment("webcam_{$airportId}_{$camIndex}_images_verified");
    metrics_increment("webcam_images_verified_global");
}

/**
 * Track rejected image for a camera
 * 
 * Called when an image fails validation checks.
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @param string $reason Rejection reason (e.g., 'error_frame', 'exif_invalid')
 * @return void
 */
function trackWebcamImageRejected(string $airportId, int $camIndex, string $reason): void {
    if (!function_exists('apcu_enabled') || !apcu_enabled()) {
        return;
    }
    
    require_once __DIR__ . '/metrics.php';
    
    $airportId = strtolower($airportId);
    $reason = strtolower($reason);
    
    metrics_increment("webcam_{$airportId}_{$camIndex}_images_rejected");
    metrics_increment("webcam_{$airportId}_{$camIndex}_rejection_{$reason}");
    metrics_increment("webcam_images_rejected_global");
    metrics_increment("webcam_rejection_reason_{$reason}_global");
}

/**
 * Get image metrics for a camera (24-hour rolling window)
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @return array{
 *   verified: int,
 *   rejected: int,
 *   rejection_reasons: array<string, int>,
 *   rejection_rate: float
 * }
 */
function getWebcamImageMetrics(string $airportId, int $camIndex): array {
    require_once __DIR__ . '/metrics.php';
    
    $airportId = strtolower($airportId);
    $webcamKey = "webcam_{$airportId}_{$camIndex}";
    
    $metrics = [
        'verified' => 0,
        'rejected' => 0,
        'rejection_reasons' => [],
        'rejection_rate' => 0.0
    ];
    
    $now = time();
    
    // Read yesterday's hourly files (hours after current hour)
    $yesterday = gmdate('Y-m-d', $now - 86400);
    $currentHour = (int)gmdate('H', $now);
    
    for ($h = $currentHour + 1; $h < 24; $h++) {
        $hourId = $yesterday . '-' . sprintf('%02d', $h);
        $metrics = aggregateWebcamImageMetricsFromHour($hourId, $webcamKey, $metrics);
    }
    
    // Read today's hourly files
    $today = gmdate('Y-m-d', $now);
    for ($h = 0; $h <= $currentHour; $h++) {
        $hourId = $today . '-' . sprintf('%02d', $h);
        $metrics = aggregateWebcamImageMetricsFromHour($hourId, $webcamKey, $metrics);
    }
    
    // Add current APCu counters (not yet flushed to hourly files)
    if (function_exists('apcu_enabled') && apcu_enabled()) {
        $metrics['verified'] += metrics_get("{$webcamKey}_images_verified");
        $metrics['rejected'] += metrics_get("{$webcamKey}_images_rejected");
        
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
    $total = $metrics['verified'] + $metrics['rejected'];
    $metrics['rejection_rate'] = $total > 0 ? ($metrics['rejected'] / $total) : 0.0;
    
    return $metrics;
}

/**
 * Aggregate webcam metrics from a single hour file
 * 
 * @param string $hourId Hour ID (e.g., '2026-01-13-14')
 * @param string $webcamKey Webcam key (e.g., 'webcam_kspb_0')
 * @param array $metrics Existing metrics to add to
 * @return array Updated metrics
 */
function aggregateWebcamImageMetricsFromHour(string $hourId, string $webcamKey, array $metrics): array {
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
    
    if (isset($hourData['webcam_images'][$webcamKey])) {
        $webcamData = $hourData['webcam_images'][$webcamKey];
        $metrics['verified'] += $webcamData['verified'] ?? 0;
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
 * Get image metrics for all cameras at an airport
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $numCameras Number of cameras at airport
 * @return array Array of metrics per camera index
 */
function getAirportImageMetrics(string $airportId, int $numCameras): array {
    $allMetrics = [];
    
    for ($i = 0; $i < $numCameras; $i++) {
        $allMetrics[$i] = getWebcamImageMetrics($airportId, $i);
    }
    
    return $allMetrics;
}

/**
 * Format image metrics for display
 * 
 * @param array $metrics Metrics array from getWebcamImageMetrics()
 * @return string Formatted metrics string
 */
function formatImageMetrics(array $metrics): string {
    $verified = $metrics['verified'] ?? 0;
    $rejected = $metrics['rejected'] ?? 0;
    
    if ($verified === 0 && $rejected === 0) {
        return 'No images (24h)';
    }
    
    $parts = [];
    if ($verified > 0) {
        $parts[] = "Verified: {$verified}";
    }
    if ($rejected > 0) {
        $parts[] = "Rejected: {$rejected}";
    }
    
    return implode(' • ', $parts) . ' (24h)';
}
