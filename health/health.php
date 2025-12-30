<?php
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/cache-paths.php';
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
$status['webcam_cache_dir']['exists'] = is_dir(CACHE_WEBCAMS_DIR);
$status['webcam_cache_dir']['writable'] = is_dir(CACHE_WEBCAMS_DIR) && is_writable(CACHE_WEBCAMS_DIR);

aviationwx_log('info', 'health probe', $status, 'app');
echo json_encode($status);
?>


