<?php
// Lightweight JSONL logger with simple rotation and APCu-backed error counter

define('AVIATIONWX_LOG_DIR', '/var/log/aviationwx');
define('AVIATIONWX_LOG_FILE', AVIATIONWX_LOG_DIR . '/app.log');
define('AVIATIONWX_LOG_MAX_BYTES', 100 * 1024 * 1024); // 100MB per file
define('AVIATIONWX_LOG_MAX_FILES', 50); // ~5GB cap

function aviationwx_init_log_dir(): void {
    @mkdir(AVIATIONWX_LOG_DIR, 0755, true);
    if (!file_exists(AVIATIONWX_LOG_FILE)) {
        @touch(AVIATIONWX_LOG_FILE);
    }
}

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

function aviationwx_error_rate_last_hour(): int {
    if (!function_exists('apcu_fetch')) return 0;
    $events = apcu_fetch('aviationwx_error_events');
    if (!is_array($events)) return 0;
    $now = time();
    $threshold = $now - 3600;
    $events = array_values(array_filter($events, fn($t) => $t >= $threshold));
    return count($events);
}

function aviationwx_maybe_log_alert(): void {
    $count = aviationwx_error_rate_last_hour();
    if ($count >= 5) {
        aviationwx_log('alert', 'High error rate in last 60 minutes', ['errors_last_hour' => $count]);
    }
}

?>


