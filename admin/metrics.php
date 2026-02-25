<?php
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/cache-paths.php';
require_once __DIR__ . '/../lib/metrics.php';
header('Content-Type: text/plain');
header('Cache-Control: no-cache, no-store, must-revalidate');

function metric($name, $value, $labels = []) {
    $labelStr = '';
    if (!empty($labels)) {
        $pairs = [];
        foreach ($labels as $k => $v) {
            $pairs[] = $k . '="' . str_replace('"', '\"', (string)$v) . '"';
        }
        $labelStr = '{' . implode(',', $pairs) . '}';
    }
    echo $name . $labelStr . ' ' . $value . "\n";
}

// Basic metrics
metric('app_up', 1);
metric('php_info', 1, ['version' => PHP_VERSION]);

// Cache dir metrics
metric('webcam_cache_exists', is_dir(CACHE_WEBCAMS_DIR) ? 1 : 0);
metric('webcam_cache_writable', (is_dir(CACHE_WEBCAMS_DIR) && is_writable(CACHE_WEBCAMS_DIR)) ? 1 : 0);

// Count cached files by format (date/hour subdir structure: webcams/airport/cam/YYYY-MM-DD/HH/file)
$counts = ['jpg' => 0, 'webp' => 0];
if (is_dir(CACHE_WEBCAMS_DIR)) {
    foreach (glob(CACHE_WEBCAMS_DIR . '/*/*/*/*/*.{jpg,webp}', GLOB_BRACE) ?: [] as $f) {
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (isset($counts[$ext])) $counts[$ext]++;
    }
}
foreach ($counts as $ext => $cnt) {
    metric('webcam_cache_files_total', $cnt, ['format' => $ext]);
}

// Per-camera metrics: age, sizes, last error code, backoff
$airportsConfig = __DIR__ . '/../config/airports.json';
if (file_exists($airportsConfig)) {
    $cfg = json_decode(@file_get_contents($airportsConfig), true);
    if (is_array($cfg) && isset($cfg['airports'])) {
        foreach ($cfg['airports'] as $airportId => $airport) {
            $cams = $airport['webcams'] ?? [];
            foreach ($cams as $idx => $_) {
                // Use new cache path structure: webcams/{airportId}/{camIndex}/current.{format}
                $jpg = getCacheSymlinkPath(strtolower($airportId), $idx, 'jpg');
                $webp = getCacheSymlinkPath(strtolower($airportId), $idx, 'webp');

                $labels = ['airport' => strtolower($airportId), 'cam' => (string)$idx];

                // File readiness and ages
                $now = time();
                $existsJpg = file_exists($jpg);
                $existsWebp = file_exists($webp);
                metric('webcam_cache_ready', $existsJpg ? 1 : 0, $labels + ['format' => 'jpg']);
                metric('webcam_cache_ready', $existsWebp ? 1 : 0, $labels + ['format' => 'webp']);
                metric('webcam_cache_age_seconds', $existsJpg ? max(0, $now - @filemtime($jpg)) : -1, $labels + ['format' => 'jpg']);
                metric('webcam_cache_age_seconds', $existsWebp ? max(0, $now - @filemtime($webp)) : -1, $labels + ['format' => 'webp']);
                metric('webcam_cache_size_bytes', $existsJpg ? @filesize($jpg) : 0, $labels + ['format' => 'jpg']);
                metric('webcam_cache_size_bytes', $existsWebp ? @filesize($webp) : 0, $labels + ['format' => 'webp']);

                // Last RTSP error code if any
                $errFile = $jpg . '.error.json';
                if (file_exists($errFile)) {
                    $err = json_decode(@file_get_contents($errFile), true);
                    $code = $err['code'] ?? 'unknown';
                    $ts = (int)($err['timestamp'] ?? 0);
                    metric('webcam_last_error', 1, $labels + ['code' => $code]);
                    metric('webcam_last_error_age_seconds', $ts > 0 ? max(0, $now - $ts) : -1, $labels);
                } else {
                    metric('webcam_last_error', 0, $labels + ['code' => 'none']);
                }

                // Circuit breaker/backoff state
                if (file_exists(CACHE_BACKOFF_FILE)) {
                    $bo = json_decode(@file_get_contents(CACHE_BACKOFF_FILE), true) ?: [];
                    $key = strtolower($airportId) . '_' . $idx;
                    $st = $bo[$key] ?? [];
                    $remaining = max(0, (int)($st['next_allowed_time'] ?? 0) - $now);
                    metric('webcam_backoff_failures', (int)($st['failures'] ?? 0), $labels);
                    metric('webcam_backoff_remaining_seconds', $remaining, $labels);
                }
            }
        }
    }
}

// =============================================================================
// USAGE METRICS (from metrics.php)
// =============================================================================
// Export usage metrics (page views, weather requests, webcam serves, etc.)

echo "\n# HELP aviationwx_airport_views_total Total page views per airport (24h)\n";
echo "# TYPE aviationwx_airport_views_total counter\n";

echo "\n# HELP aviationwx_weather_requests_total Total weather API requests per airport (24h)\n";
echo "# TYPE aviationwx_weather_requests_total counter\n";

echo "\n# HELP aviationwx_webcam_serves_total Total webcam serves by format (24h)\n";
echo "# TYPE aviationwx_webcam_serves_total counter\n";

echo "\n# HELP aviationwx_webcam_serves_by_size_total Total webcam serves by size (24h)\n";
echo "# TYPE aviationwx_webcam_serves_by_size_total counter\n";

echo "\n# HELP aviationwx_browser_format_support_total Browser format support distribution (24h)\n";
echo "# TYPE aviationwx_browser_format_support_total counter\n";

echo "\n# HELP aviationwx_cache_hits_total Total cache hits (24h)\n";
echo "# TYPE aviationwx_cache_hits_total counter\n";

echo "\n# HELP aviationwx_cache_misses_total Total cache misses (24h)\n";
echo "# TYPE aviationwx_cache_misses_total counter\n";

// Output the actual metrics
$prometheusLines = metrics_prometheus_export();
foreach ($prometheusLines as $line) {
    echo $line . "\n";
}

aviationwx_log('info', 'metrics probe', [], 'app');
