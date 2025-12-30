<?php
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/cache-paths.php';
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

$ok = true;
$errors = [];

// Config readable
$configPath = getenv('CONFIG_PATH');
if ($configPath === false || trim($configPath) === '') {
    $configPath = __DIR__ . '/../config/airports.json';
}
if (!file_exists($configPath) || !is_readable($configPath)) {
    $ok = false;
    $errors[] = 'config_unreadable';
}

// Cache directories
$cacheOk = true;
foreach ([CACHE_BASE_DIR, CACHE_WEBCAMS_DIR] as $cacheDir) {
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0777, true);
    }
    if (!is_writable($cacheDir)) {
        $cacheOk = false;
    }
}
if (!$cacheOk) {
    $ok = false;
    $errors[] = 'cache_not_writable';
}

// APCu availability (optional but recommended)
if (!function_exists('apcu_fetch')) {
    $errors[] = 'apcu_missing';
}

aviationwx_log('info', 'ready probe', ['ok' => $ok, 'errors' => $errors], 'app');
http_response_code($ok ? 200 : 503);
echo json_encode([
    'ok' => $ok,
    'errors' => $errors,
]);
?>


