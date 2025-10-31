<?php
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

$ok = true;
$errors = [];

// Config readable
$configPath = getenv('CONFIG_PATH');
if ($configPath === false || trim($configPath) === '') {
    $configPath = __DIR__ . '/airports.json';
}
if (!file_exists($configPath) || !is_readable($configPath)) {
    $ok = false;
    $errors[] = 'config_unreadable';
}

// Cache directories
$cacheOk = true;
foreach (['/cache', '/cache/webcams'] as $rel) {
    $p = __DIR__ . $rel;
    if (!is_dir($p)) {
        @mkdir($p, 0777, true);
    }
    if (!is_writable($p)) {
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

http_response_code($ok ? 200 : 503);
echo json_encode([
    'ok' => $ok,
    'errors' => $errors,
]);
?>


