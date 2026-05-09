<?php
/**
 * Merge flat APCu-style counter keys into hourly metrics bucket structure.
 *
 * Mutates $hourData in place. When `bucket_id` is set and `$skipHourBucketNormalization` is false,
 * normalizes via {@see metrics_normalize_hour_bucket_for_merge()} so partial legacy hourly JSON cannot fatal on missing nested keys (PHP 8+).
 * The spill aggregator passes true for `$skipHourBucketNormalization` after it has already normalized once per hour.
 *
 * @param array<string, mixed> $hourData Hourly metrics structure (by reference)
 * @param array<string, int|float> $counters Flat counter keys from APCu or spill payloads
 * @param bool $skipHourBucketNormalization When true, do not re-run merge normalization (caller already normalized)
 * @return void
 */
function metrics_apply_flat_counters_to_hour_data(array &$hourData, array $counters, bool $skipHourBucketNormalization = false): void {
    $bucketId = $hourData['bucket_id'] ?? '';
    if (!$skipHourBucketNormalization && is_string($bucketId) && $bucketId !== '') {
        metrics_normalize_hour_bucket_for_merge($hourData, $bucketId);
    }

    foreach ($counters as $key => $value) {
        if (preg_match('/^airport_([a-z0-9]+)_views$/', $key, $m)) {
            $airportId = $m[1];
            if (!isset($hourData['airports'][$airportId])) {
                $hourData['airports'][$airportId] = ['page_views' => 0, 'weather_requests' => 0, 'webcam_requests' => 0];
            }
            $hourData['airports'][$airportId]['page_views'] += $value;
        } elseif (preg_match('/^airport_([a-z0-9]+)_weather$/', $key, $m)) {
            $airportId = $m[1];
            if (!isset($hourData['airports'][$airportId])) {
                $hourData['airports'][$airportId] = ['page_views' => 0, 'weather_requests' => 0, 'webcam_requests' => 0];
            }
            $hourData['airports'][$airportId]['weather_requests'] += $value;
        } elseif (preg_match('/^airport_([a-z0-9]+)_webcam_requests$/', $key, $m)) {
            $airportId = $m[1];
            if (!isset($hourData['airports'][$airportId])) {
                $hourData['airports'][$airportId] = ['page_views' => 0, 'weather_requests' => 0, 'webcam_requests' => 0];
            }
            if (!isset($hourData['airports'][$airportId]['webcam_requests'])) {
                $hourData['airports'][$airportId]['webcam_requests'] = 0;
            }
            $hourData['airports'][$airportId]['webcam_requests'] += $value;
        } elseif (preg_match('/^webcam_([a-z0-9]+)_(\d+)_requests$/', $key, $m)) {
            // Per-camera request tracking (stored under webcams)
            $webcamKey = $m[1] . '_' . $m[2];
            if (!isset($hourData['webcams'][$webcamKey])) {
                $hourData['webcams'][$webcamKey] = [
                    'requests' => 0,
                    'by_format' => ['jpg' => 0, 'webp' => 0],
                    'by_size' => [] // Dynamic: height-based variants like '720', '360', 'original'
                ];
            }
            if (!isset($hourData['webcams'][$webcamKey]['requests'])) {
                $hourData['webcams'][$webcamKey]['requests'] = 0;
            }
            $hourData['webcams'][$webcamKey]['requests'] += $value;
        } elseif (preg_match('/^webcam_([a-z0-9]+)_(\d+)_(jpg|webp)$/', $key, $m)) {
            $webcamKey = $m[1] . '_' . $m[2];
            $format = $m[3];
            if (!isset($hourData['webcams'][$webcamKey])) {
                $hourData['webcams'][$webcamKey] = [
                    'requests' => 0,
                    'by_format' => ['jpg' => 0, 'webp' => 0],
                    'by_size' => []
                ];
            }
            $hourData['webcams'][$webcamKey]['by_format'][$format] += $value;
        } elseif (preg_match('/^webcam_([a-z0-9]+)_(\d+)_size_(\w+)$/', $key, $m)) {
            // Match both height-based (720, 360, 1080) and named (original) sizes
            $webcamKey = $m[1] . '_' . $m[2];
            $size = $m[3];
            if (!isset($hourData['webcams'][$webcamKey])) {
                $hourData['webcams'][$webcamKey] = [
                    'requests' => 0,
                    'by_format' => ['jpg' => 0, 'webp' => 0],
                    'by_size' => []
                ];
            }
            if (!isset($hourData['webcams'][$webcamKey]['by_size'][$size])) {
                $hourData['webcams'][$webcamKey]['by_size'][$size] = 0;
            }
            $hourData['webcams'][$webcamKey]['by_size'][$size] += $value;
        } elseif (preg_match('/^format_(jpg|webp)_served$/', $key, $m)) {
            $hourData['global']['format_served'][$m[1]] += $value;
        } elseif (preg_match('/^size_(\w+)_served$/', $key, $m)) {
            // Match both height-based and named sizes
            $size = $m[1];
            if (!isset($hourData['global']['size_served'][$size])) {
                $hourData['global']['size_served'][$size] = 0;
            }
            $hourData['global']['size_served'][$size] += $value;
        } elseif ($key === 'global_page_views') {
            $hourData['global']['page_views'] += $value;
        } elseif ($key === 'global_weather_requests') {
            $hourData['global']['weather_requests'] += $value;
        } elseif ($key === 'global_webcam_requests') {
            $hourData['global']['webcam_requests'] += $value;
        } elseif ($key === 'global_webcam_serves') {
            $hourData['global']['webcam_serves'] += $value;
        } elseif ($key === 'global_variants_generated') {
            if (!isset($hourData['global']['variants_generated'])) {
                $hourData['global']['variants_generated'] = 0;
            }
            $hourData['global']['variants_generated'] += $value;
        } elseif ($key === 'global_tiles_served') {
            $hourData['global']['tiles_served'] += $value;
        } elseif (preg_match('/^tiles_(openweathermap|rainviewer)_served$/', $key, $m)) {
            $source = $m[1];
            if (!isset($hourData['global']['tiles_by_source'][$source])) {
                $hourData['global']['tiles_by_source'][$source] = 0;
            }
            $hourData['global']['tiles_by_source'][$source] += $value;
        } elseif ($key === 'browser_webp_support') {
            $hourData['global']['browser_support']['webp'] += $value;
        } elseif ($key === 'browser_jpg_only') {
            $hourData['global']['browser_support']['jpg_only'] += $value;
        } elseif ($key === 'cache_hits') {
            $hourData['global']['cache']['hits'] += $value;
        } elseif ($key === 'cache_misses') {
            $hourData['global']['cache']['misses'] += $value;
        } elseif (preg_match('/^webcam_([a-z0-9]+)_(\d+)_uploads_accepted$/', $key, $m)) {
            // Track accepted uploads per camera
            $webcamKey = "webcam_{$m[1]}_{$m[2]}";
            if (!isset($hourData['webcam_uploads'][$webcamKey])) {
                $hourData['webcam_uploads'][$webcamKey] = [
                    'accepted' => 0,
                    'rejected' => 0,
                    'rejection_reasons' => []
                ];
            }
            $hourData['webcam_uploads'][$webcamKey]['accepted'] += $value;
            $hourData['global']['webcam_uploads_accepted'] += $value;
        } elseif (preg_match('/^webcam_([a-z0-9]+)_(\d+)_uploads_rejected$/', $key, $m)) {
            // Track rejected uploads per camera
            $webcamKey = "webcam_{$m[1]}_{$m[2]}";
            if (!isset($hourData['webcam_uploads'][$webcamKey])) {
                $hourData['webcam_uploads'][$webcamKey] = [
                    'accepted' => 0,
                    'rejected' => 0,
                    'rejection_reasons' => []
                ];
            }
            $hourData['webcam_uploads'][$webcamKey]['rejected'] += $value;
            $hourData['global']['webcam_uploads_rejected'] += $value;
        } elseif (preg_match('/^webcam_([a-z0-9]+)_(\d+)_rejection_(.+)$/', $key, $m)) {
            // Track rejection reasons per camera
            $webcamKey = "webcam_{$m[1]}_{$m[2]}";
            $reason = $m[3];
            if (!isset($hourData['webcam_uploads'][$webcamKey])) {
                $hourData['webcam_uploads'][$webcamKey] = [
                    'accepted' => 0,
                    'rejected' => 0,
                    'rejection_reasons' => []
                ];
            }
            if (!isset($hourData['webcam_uploads'][$webcamKey]['rejection_reasons'][$reason])) {
                $hourData['webcam_uploads'][$webcamKey]['rejection_reasons'][$reason] = 0;
            }
            $hourData['webcam_uploads'][$webcamKey]['rejection_reasons'][$reason] += $value;
        } elseif ($key === 'webcam_uploads_accepted_global') {
            $hourData['global']['webcam_uploads_accepted'] += $value;
        } elseif ($key === 'webcam_uploads_rejected_global') {
            $hourData['global']['webcam_uploads_rejected'] += $value;
        } elseif (preg_match('/^webcam_([a-z0-9]+)_(\d+)_images_verified$/', $key, $m)) {
            // Track verified images per camera (from webcam-image-metrics.php)
            $webcamKey = "webcam_{$m[1]}_{$m[2]}";
            if (!isset($hourData['webcam_images'][$webcamKey])) {
                $hourData['webcam_images'][$webcamKey] = [
                    'verified' => 0,
                    'rejected' => 0,
                    'rejection_reasons' => []
                ];
            }
            $hourData['webcam_images'][$webcamKey]['verified'] += $value;
            $hourData['global']['webcam_images_verified'] += $value;
        } elseif (preg_match('/^webcam_([a-z0-9]+)_(\d+)_images_rejected$/', $key, $m)) {
            // Track rejected images per camera (from webcam-image-metrics.php)
            $webcamKey = "webcam_{$m[1]}_{$m[2]}";
            if (!isset($hourData['webcam_images'][$webcamKey])) {
                $hourData['webcam_images'][$webcamKey] = [
                    'verified' => 0,
                    'rejected' => 0,
                    'rejection_reasons' => []
                ];
            }
            $hourData['webcam_images'][$webcamKey]['rejected'] += $value;
            $hourData['global']['webcam_images_rejected'] += $value;
        } elseif ($key === 'webcam_images_verified_global') {
            $hourData['global']['webcam_images_verified'] += $value;
        } elseif ($key === 'webcam_images_rejected_global') {
            $hourData['global']['webcam_images_rejected'] += $value;
        } elseif (preg_match('/^webcam_rejection_reason_(.+)_global$/', $key, $m)) {
            // Global rejection reason tracking (informational)
            // Just increment global counter, per-camera reasons are tracked separately
        }
    }
}

/**
 * Whether {@see metrics_apply_flat_counters_to_hour_data()} handles this flat counter name.
 *
 * Spill merge uses this to reject shards whose keys are not yet implemented, so unknown keys are not
 * silently dropped while the file is deleted. Add new keys here and in apply together; bump
 * {@see METRICS_SPILL_FILE_SCHEMA_VERSION} when expanding the wire format.
 *
 * @param string $key Flat counter key (same syntax as APCu / spill JSON)
 * @return bool True when the apply function has a matching branch (including intentional no-ops)
 */
function metrics_flat_counter_key_is_recognized(string $key): bool
{
    return preg_match('/^airport_([a-z0-9]+)_views$/', $key) === 1
        || preg_match('/^airport_([a-z0-9]+)_weather$/', $key) === 1
        || preg_match('/^airport_([a-z0-9]+)_webcam_requests$/', $key) === 1
        || preg_match('/^webcam_([a-z0-9]+)_(\d+)_requests$/', $key) === 1
        || preg_match('/^webcam_([a-z0-9]+)_(\d+)_(jpg|webp)$/', $key) === 1
        || preg_match('/^webcam_([a-z0-9]+)_(\d+)_size_(\w+)$/', $key) === 1
        || preg_match('/^format_(jpg|webp)_served$/', $key) === 1
        || preg_match('/^size_(\w+)_served$/', $key) === 1
        || $key === 'global_page_views'
        || $key === 'global_weather_requests'
        || $key === 'global_webcam_requests'
        || $key === 'global_webcam_serves'
        || $key === 'global_variants_generated'
        || $key === 'global_tiles_served'
        || preg_match('/^tiles_(openweathermap|rainviewer)_served$/', $key) === 1
        || $key === 'browser_webp_support'
        || $key === 'browser_jpg_only'
        || $key === 'cache_hits'
        || $key === 'cache_misses'
        || preg_match('/^webcam_([a-z0-9]+)_(\d+)_uploads_accepted$/', $key) === 1
        || preg_match('/^webcam_([a-z0-9]+)_(\d+)_uploads_rejected$/', $key) === 1
        || preg_match('/^webcam_([a-z0-9]+)_(\d+)_rejection_(.+)$/', $key) === 1
        || $key === 'webcam_uploads_accepted_global'
        || $key === 'webcam_uploads_rejected_global'
        || preg_match('/^webcam_([a-z0-9]+)_(\d+)_images_verified$/', $key) === 1
        || preg_match('/^webcam_([a-z0-9]+)_(\d+)_images_rejected$/', $key) === 1
        || $key === 'webcam_images_verified_global'
        || $key === 'webcam_images_rejected_global'
        || preg_match('/^webcam_rejection_reason_(.+)_global$/', $key) === 1;
}
