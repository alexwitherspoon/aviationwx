<?php
/**
 * Performance Metrics Tracking
 * 
 * Tracks application-level performance metrics:
 * - Image processing time (webcam variant generation)
 * - Page render time (full request-response cycle)
 * 
 * Metrics are stored in APCu and aggregated for status page display.
 */

require_once __DIR__ . '/logger.php';

// APCu keys for performance metrics
const PERF_IMAGE_PROCESSING_KEY = 'perf_image_processing';
const PERF_PAGE_RENDER_KEY = 'perf_page_render';

// How many samples to keep for calculating averages
const PERF_SAMPLE_SIZE = 100;

/**
 * Track image processing time
 * 
 * @param float $durationMs Processing duration in milliseconds
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index
 */
function trackImageProcessingTime(float $durationMs, string $airportId = '', int $camIndex = 0): void {
    if (!function_exists('apcu_fetch')) {
        return; // APCu not available
    }
    
    $samples = apcu_fetch(PERF_IMAGE_PROCESSING_KEY, $success);
    if (!$success || !is_array($samples)) {
        $samples = [];
    }
    
    // Add new sample
    $samples[] = [
        'duration_ms' => $durationMs,
        'airport_id' => $airportId,
        'cam_index' => $camIndex,
        'timestamp' => time()
    ];
    
    // Keep only the last N samples
    if (count($samples) > PERF_SAMPLE_SIZE) {
        $samples = array_slice($samples, -PERF_SAMPLE_SIZE);
    }
    
    apcu_store(PERF_IMAGE_PROCESSING_KEY, $samples, 3600); // 1 hour TTL
}

/**
 * Track page render time
 * 
 * @param float $durationMs Render duration in milliseconds
 * @param string $page Page identifier (e.g., 'homepage', 'airport', 'status')
 * @param string $path Request path
 */
function trackPageRenderTime(float $durationMs, string $page = '', string $path = ''): void {
    if (!function_exists('apcu_fetch')) {
        return; // APCu not available
    }
    
    $samples = apcu_fetch(PERF_PAGE_RENDER_KEY, $success);
    if (!$success || !is_array($samples)) {
        $samples = [];
    }
    
    // Add new sample
    $samples[] = [
        'duration_ms' => $durationMs,
        'page' => $page,
        'path' => $path,
        'timestamp' => time()
    ];
    
    // Keep only the last N samples
    if (count($samples) > PERF_SAMPLE_SIZE) {
        $samples = array_slice($samples, -PERF_SAMPLE_SIZE);
    }
    
    apcu_store(PERF_PAGE_RENDER_KEY, $samples, 3600); // 1 hour TTL
}

/**
 * Get image processing performance metrics
 * 
 * @return array Performance statistics:
 *   - avg_ms: Average processing time
 *   - p50_ms: 50th percentile (median)
 *   - p95_ms: 95th percentile
 *   - sample_count: Number of samples
 *   - last_hour_count: Samples from last hour
 */
function getImageProcessingMetrics(): array {
    if (!function_exists('apcu_fetch')) {
        return [];
    }
    
    $samples = apcu_fetch(PERF_IMAGE_PROCESSING_KEY, $success);
    if (!$success || !is_array($samples) || empty($samples)) {
        return [
            'avg_ms' => 0,
            'p50_ms' => 0,
            'p95_ms' => 0,
            'sample_count' => 0,
            'last_hour_count' => 0
        ];
    }
    
    // Extract durations and sort for percentile calculation
    $durations = array_column($samples, 'duration_ms');
    sort($durations);
    
    $count = count($durations);
    $avg = array_sum($durations) / $count;
    $p50Index = (int)floor($count * 0.5);
    $p95Index = (int)floor($count * 0.95);
    
    // Count samples from last hour
    $oneHourAgo = time() - 3600;
    $lastHourCount = 0;
    foreach ($samples as $sample) {
        if ($sample['timestamp'] >= $oneHourAgo) {
            $lastHourCount++;
        }
    }
    
    return [
        'avg_ms' => round($avg, 1),
        'p50_ms' => round($durations[$p50Index], 1),
        'p95_ms' => round($durations[$p95Index], 1),
        'sample_count' => $count,
        'last_hour_count' => $lastHourCount
    ];
}

/**
 * Get page render performance metrics
 * 
 * @return array Performance statistics:
 *   - avg_ms: Average render time
 *   - p50_ms: 50th percentile (median)
 *   - p95_ms: 95th percentile
 *   - sample_count: Number of samples
 *   - last_hour_count: Samples from last hour
 */
function getPageRenderMetrics(): array {
    if (!function_exists('apcu_fetch')) {
        return [];
    }
    
    $samples = apcu_fetch(PERF_PAGE_RENDER_KEY, $success);
    if (!$success || !is_array($samples) || empty($samples)) {
        return [
            'avg_ms' => 0,
            'p50_ms' => 0,
            'p95_ms' => 0,
            'sample_count' => 0,
            'last_hour_count' => 0
        ];
    }
    
    // Extract durations and sort for percentile calculation
    $durations = array_column($samples, 'duration_ms');
    sort($durations);
    
    $count = count($durations);
    $avg = array_sum($durations) / $count;
    $p50Index = (int)floor($count * 0.5);
    $p95Index = (int)floor($count * 0.95);
    
    // Count samples from last hour
    $oneHourAgo = time() - 3600;
    $lastHourCount = 0;
    foreach ($samples as $sample) {
        if ($sample['timestamp'] >= $oneHourAgo) {
            $lastHourCount++;
        }
    }
    
    return [
        'avg_ms' => round($avg, 1),
        'p50_ms' => round($durations[$p50Index], 1),
        'p95_ms' => round($durations[$p95Index], 1),
        'sample_count' => $count,
        'last_hour_count' => $lastHourCount
    ];
}

/**
 * Start timing a code block
 * 
 * @return float Start timestamp in microseconds
 */
function perfStart(): float {
    return microtime(true);
}

/**
 * End timing and return duration in milliseconds
 * 
 * @param float $start Start timestamp from perfStart()
 * @return float Duration in milliseconds
 */
function perfEnd(float $start): float {
    return (microtime(true) - $start) * 1000;
}
