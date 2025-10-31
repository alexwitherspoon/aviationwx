<?php
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
$cacheDir = __DIR__ . '/cache/webcams';
metric('webcam_cache_exists', is_dir($cacheDir) ? 1 : 0);
metric('webcam_cache_writable', (is_dir($cacheDir) && is_writable($cacheDir)) ? 1 : 0);

// Count cached files by format
$counts = ['jpg' => 0, 'webp' => 0, 'avif' => 0];
if (is_dir($cacheDir)) {
    foreach (glob($cacheDir . '/*.{jpg,webp,avif}', GLOB_BRACE) ?: [] as $f) {
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (isset($counts[$ext])) $counts[$ext]++;
    }
}
foreach ($counts as $ext => $cnt) {
    metric('webcam_cache_files_total', $cnt, ['format' => $ext]);
}

// Per-camera metrics: age, sizes, last error code, backoff
$airportsConfig = __DIR__ . '/airports.json';
if (file_exists($airportsConfig)) {
    $cfg = json_decode(@file_get_contents($airportsConfig), true);
    if (is_array($cfg) && isset($cfg['airports'])) {
        foreach ($cfg['airports'] as $airportId => $airport) {
            $cams = $airport['webcams'] ?? [];
            foreach ($cams as $idx => $_) {
                $base = $cacheDir . '/' . strtolower($airportId) . '_' . $idx;
                $jpg = $base . '.jpg';
                $webp = $base . '.webp';
                $avif = $base . '.avif';

                $labels = ['airport' => strtolower($airportId), 'cam' => (string)$idx];

                // File readiness and ages
                $now = time();
                $existsJpg = file_exists($jpg);
                $existsWebp = file_exists($webp);
                $existsAvif = file_exists($avif);
                metric('webcam_cache_ready', $existsJpg ? 1 : 0, $labels + ['format' => 'jpg']);
                metric('webcam_cache_ready', $existsWebp ? 1 : 0, $labels + ['format' => 'webp']);
                metric('webcam_cache_ready', $existsAvif ? 1 : 0, $labels + ['format' => 'avif']);
                metric('webcam_cache_age_seconds', $existsJpg ? max(0, $now - @filemtime($jpg)) : -1, $labels + ['format' => 'jpg']);
                metric('webcam_cache_age_seconds', $existsWebp ? max(0, $now - @filemtime($webp)) : -1, $labels + ['format' => 'webp']);
                metric('webcam_cache_age_seconds', $existsAvif ? max(0, $now - @filemtime($avif)) : -1, $labels + ['format' => 'avif']);
                metric('webcam_cache_size_bytes', $existsJpg ? @filesize($jpg) : 0, $labels + ['format' => 'jpg']);
                metric('webcam_cache_size_bytes', $existsWebp ? @filesize($webp) : 0, $labels + ['format' => 'webp']);
                metric('webcam_cache_size_bytes', $existsAvif ? @filesize($avif) : 0, $labels + ['format' => 'avif']);

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
                $backoff = __DIR__ . '/cache/backoff.json';
                if (file_exists($backoff)) {
                    $bo = json_decode(@file_get_contents($backoff), true) ?: [];
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

?>


