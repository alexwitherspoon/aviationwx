<?php
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

$status = [
    'ok' => true,
    'time' => time(),
    'php_version' => PHP_VERSION,
    'apcu' => function_exists('apcu_enabled') && apcu_enabled(),
    'ffmpeg' => false,
    'webcam_cache_dir' => [
        'exists' => false,
        'writable' => false,
    ],
];

// ffmpeg availability
$ff = @shell_exec('ffmpeg -version 2>&1');
if ($ff && strpos($ff, 'ffmpeg version') !== false) {
    $status['ffmpeg'] = true;
}

// cache dir
$cacheDir = __DIR__ . '/cache/webcams';
$status['webcam_cache_dir']['exists'] = is_dir($cacheDir);
$status['webcam_cache_dir']['writable'] = is_dir($cacheDir) && is_writable($cacheDir);

echo json_encode($status);
?>


