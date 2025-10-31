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

?>


