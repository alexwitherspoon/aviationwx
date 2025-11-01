<?php
// Lightweight JSONL logger with simple rotation and APCu-backed error counter

if (!defined('AVIATIONWX_LOG_DIR')) {
    define('AVIATIONWX_LOG_DIR', '/var/log/aviationwx');
}
if (!defined('AVIATIONWX_LOG_FILE')) {
    define('AVIATIONWX_LOG_FILE', AVIATIONWX_LOG_DIR . '/app.log');
}
if (!defined('AVIATIONWX_LOG_MAX_BYTES')) {
    define('AVIATIONWX_LOG_MAX_BYTES', 100 * 1024 * 1024); // 100MB per file
}
if (!defined('AVIATIONWX_LOG_MAX_FILES')) {
    define('AVIATIONWX_LOG_MAX_FILES', 50); // ~5GB cap
}

if (!function_exists('aviationwx_init_log_dir')) {
function aviationwx_init_log_dir(): void {
    @mkdir(AVIATIONWX_LOG_DIR, 0755, true);
    if (!file_exists(AVIATIONWX_LOG_FILE)) {
        @touch(AVIATIONWX_LOG_FILE);
    }
}
}

if (!function_exists('aviationwx_rotate_log_if_needed')) {
function aviationwx_rotate_log_if_needed(): void {
    clearstatcache(true, AVIATIONWX_LOG_FILE);
    $size = @filesize(AVIATIONWX_LOG_FILE);
    if ($size !== false && $size > AVIATIONWX_LOG_MAX_BYTES) {
        // Rotate: app.log.N -> app.log.N+1, delete > MAX_FILES
        for ($i = AVIATIONWX_LOG_MAX_FILES - 1; $i >= 1; $i--) {
            $src = AVIATIONWX_LOG_FILE . '.' . $i;
            $dst = AVIATIONWX_LOG_FILE . '.' . ($i + 1);
            if (file_exists($src)) {
                @rename($src, $dst);
            }
        }
        @rename(AVIATIONWX_LOG_FILE, AVIATIONWX_LOG_FILE . '.1');
        @touch(AVIATIONWX_LOG_FILE);
    }
}
}

if (!function_exists('aviationwx_get_request_id')) {
function aviationwx_get_request_id(): string {
    static $reqId = null;
    if ($reqId !== null) return $reqId;
    if (!empty($_SERVER['HTTP_X_REQUEST_ID'])) {
        $reqId = trim($_SERVER['HTTP_X_REQUEST_ID']);
    } else {
        $reqId = bin2hex(random_bytes(8));
    }
    return $reqId;
}
}

if (!function_exists('aviationwx_log')) {
function aviationwx_log(string $level, string $message, array $context = []): void {
    aviationwx_init_log_dir();
    aviationwx_rotate_log_if_needed();
    $now = (new DateTime('now', new DateTimeZone('UTC')))->format('c');
    $entry = [
        'ts' => $now,
        'level' => strtolower($level),
        'request_id' => aviationwx_get_request_id(),
        'message' => $message,
        'context' => $context
    ];
    // Error counter for alerting
    if (in_array($entry['level'], ['warning','error','critical','alert','emergency'], true)) {
        aviationwx_record_error_event();
    }
    @file_put_contents(AVIATIONWX_LOG_FILE, json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}
}

if (!function_exists('aviationwx_record_error_event')) {
function aviationwx_record_error_event(): void {
    if (!function_exists('apcu_fetch')) return;
    $key = 'aviationwx_error_events';
    $events = apcu_fetch($key);
    if (!is_array($events)) $events = [];
    $now = time();
    $events[] = $now;
    // Purge older than 3600s
    $threshold = $now - 3600;
    $events = array_values(array_filter($events, fn($t) => $t >= $threshold));
    apcu_store($key, $events, 3600);
}
}

if (!function_exists('aviationwx_error_rate_last_hour')) {
function aviationwx_error_rate_last_hour(): int {
    if (!function_exists('apcu_fetch')) return 0;
    $events = apcu_fetch('aviationwx_error_events');
    if (!is_array($events)) return 0;
    $now = time();
    $threshold = $now - 3600;
    $events = array_values(array_filter($events, fn($t) => $t >= $threshold));
    return count($events);
}
}

if (!function_exists('aviationwx_maybe_log_alert')) {
function aviationwx_maybe_log_alert(): void {
    $count = aviationwx_error_rate_last_hour();
    if ($count >= 5) {
        aviationwx_log('alert', 'High error rate in last 60 minutes', ['errors_last_hour' => $count]);
    }
}
}
