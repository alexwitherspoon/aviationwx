<?php
// Lightweight JSONL logger that writes to stdout/stderr for Docker logging
// Docker handles log rotation automatically via json-file driver
// Supports dual logging: user activity and system messages (both go to Docker logs)

// Logging configuration - can be overridden via environment variable
if (!defined('AVIATIONWX_LOG_TO_STDOUT')) {
    // Default to stdout/stderr logging (Docker-friendly)
    // Set to false to use file-based logging (for backward compatibility)
    define('AVIATIONWX_LOG_TO_STDOUT', getenv('AVIATIONWX_LOG_TO_STDOUT') !== 'false');
}

// File-based logging paths (only used if AVIATIONWX_LOG_TO_STDOUT is false)
if (!defined('AVIATIONWX_LOG_DIR')) {
    define('AVIATIONWX_LOG_DIR', '/var/log/aviationwx');
}
if (!defined('AVIATIONWX_LOG_FILE')) {
    define('AVIATIONWX_LOG_FILE', AVIATIONWX_LOG_DIR . '/app.log');
}
if (!defined('AVIATIONWX_USER_LOG_FILE')) {
    define('AVIATIONWX_USER_LOG_FILE', AVIATIONWX_LOG_DIR . '/user.log');
}
if (!defined('AVIATIONWX_APP_LOG_FILE')) {
    define('AVIATIONWX_APP_LOG_FILE', AVIATIONWX_LOG_DIR . '/app.log');
}
if (!defined('AVIATIONWX_LOG_MAX_BYTES')) {
    define('AVIATIONWX_LOG_MAX_BYTES', 100 * 1024 * 1024); // 100MB per file
}
if (!defined('AVIATIONWX_LOG_MAX_FILES')) {
    define('AVIATIONWX_LOG_MAX_FILES', 10); // 10 files × 100MB = 1GB total per log type
}

if (!function_exists('aviationwx_get_log_file_path')) {
function aviationwx_get_log_file_path(string $logType = 'app'): string {
    if ($logType === 'user') {
        return AVIATIONWX_USER_LOG_FILE;
    }
    return AVIATIONWX_APP_LOG_FILE;
}
}

if (!function_exists('aviationwx_init_log_dir')) {
function aviationwx_init_log_dir(): void {
    if (AVIATIONWX_LOG_TO_STDOUT) {
        return; // No directory needed for stdout/stderr logging
    }
    @mkdir(AVIATIONWX_LOG_DIR, 0755, true);
    if (!file_exists(AVIATIONWX_USER_LOG_FILE)) {
        @touch(AVIATIONWX_USER_LOG_FILE);
    }
    if (!file_exists(AVIATIONWX_APP_LOG_FILE)) {
        @touch(AVIATIONWX_APP_LOG_FILE);
    }
}
}

if (!function_exists('aviationwx_rotate_log_if_needed')) {
function aviationwx_rotate_log_if_needed(string $logFile): void {
    if (AVIATIONWX_LOG_TO_STDOUT) {
        return; // Docker handles rotation for stdout/stderr
    }
    clearstatcache(true, $logFile);
    $size = @filesize($logFile);
    if ($size !== false && $size > AVIATIONWX_LOG_MAX_BYTES) {
        // Rotate: logfile -> logfile.1 -> logfile.2 -> ... -> logfile.N, delete > MAX_FILES
        for ($i = AVIATIONWX_LOG_MAX_FILES - 1; $i >= 1; $i--) {
            $src = $logFile . '.' . $i;
            $dst = $logFile . '.' . ($i + 1);
            if (file_exists($src)) {
                @rename($src, $dst);
            }
        }
        // Delete files beyond MAX_FILES
        for ($i = AVIATIONWX_LOG_MAX_FILES + 1; $i <= AVIATIONWX_LOG_MAX_FILES + 10; $i++) {
            $oldFile = $logFile . '.' . $i;
            if (file_exists($oldFile)) {
                @unlink($oldFile);
            }
        }
        @rename($logFile, $logFile . '.1');
        @touch($logFile);
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
function aviationwx_log(string $level, string $message, array $context = [], string $logType = 'app'): void {
    $now = (new DateTime('now', new DateTimeZone('UTC')))->format('c');
    
    // Determine log source based on context
    $source = 'app';
    if (php_sapi_name() === 'cli') {
        $source = 'cli';
    } elseif (!empty($_SERVER['REQUEST_METHOD'])) {
        $source = 'web';
    }
    
    // Add source to context if not already present
    if (!isset($context['source'])) {
        $context['source'] = $source;
    }
    
    $entry = [
        'ts' => $now,
        'level' => strtolower($level),
        'request_id' => aviationwx_get_request_id(),
        'message' => $message,
        'context' => $context,
        'log_type' => $logType,
        'source' => $source
    ];
    
    // Error counter for alerting
    if (in_array($entry['level'], ['warning','error','critical','alert','emergency'], true)) {
        aviationwx_record_error_event();
    }
    
    // Format as JSONL (one JSON object per line)
    $jsonLine = json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n";
    
    if (AVIATIONWX_LOG_TO_STDOUT) {
        // Write to stdout/stderr for Docker logging
        // Errors and warnings go to stderr, info/debug go to stdout
        $isError = in_array($entry['level'], ['warning','error','critical','alert','emergency'], true);
        
        // In CLI, check if running via cron (non-interactive, no TTY)
        // When running via cron, use error_log() to ensure output goes to Docker logs
        // Cron captures stdout/stderr, but error_log() goes to syslog/stderr which Docker captures
        $isCron = false;
        if (php_sapi_name() === 'cli') {
            // Check if running via cron: no TTY on stdin
            if (function_exists('posix_isatty') && !@posix_isatty(STDIN)) {
                $isCron = true;
            }
        }
        
        // In CLI, STDOUT/STDERR are defined and work directly
        // BUT: when running via cron, use error_log() instead so output goes to Docker logs
        if (php_sapi_name() === 'cli' && !$isCron && defined('STDOUT') && defined('STDERR')) {
            $stream = $isError ? STDERR : STDOUT;
            @fwrite($stream, $jsonLine);
            @fflush($stream);
        } else {
            // Web context (Apache/mod_php) OR CLI via cron: use error_log() which writes to Apache error log / syslog
            // Both are captured by Docker as stderr
            // Note: php://stdout goes to HTTP response body (not what we want)
            // php://stderr might work but error_log() is more reliable for Apache and cron
            @error_log($jsonLine);
        }
    } else {
        // File-based logging (backward compatibility)
        aviationwx_init_log_dir();
        $logFile = aviationwx_get_log_file_path($logType);
        aviationwx_rotate_log_if_needed($logFile);
        @file_put_contents($logFile, $jsonLine, FILE_APPEND | LOCK_EX);
    }
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
        // Use 'info' level to avoid feedback loop - this is a metric, not an error
        aviationwx_log('info', 'High error rate in last 60 minutes', ['errors_last_hour' => $count]);
    }
}
}

if (!function_exists('aviationwx_get_invocation_id')) {
/**
 * Generate a unique invocation ID for tracking a single script execution
 * This ID persists for the lifetime of the script and can be used to correlate all log entries
 * from a single run of a fetcher script
 * 
 * @return string Unique invocation ID (16 hex characters)
 */
function aviationwx_get_invocation_id(): string {
    static $invocationId = null;
    if ($invocationId !== null) return $invocationId;
    $invocationId = bin2hex(random_bytes(8));
    return $invocationId;
}
}

if (!function_exists('aviationwx_detect_trigger_type')) {
/**
 * Detect the trigger type for fetcher scripts with enhanced context
 * Distinguishes between cron jobs, web requests, and manual CLI runs
 * 
 * @return array ['trigger' => string, 'context' => array]
 *   - trigger: 'cron_job', 'web_request', or 'manual_cli'
 *   - context: Additional context based on trigger type
 */
function aviationwx_detect_trigger_type(): array {
    $isWeb = !empty($_SERVER['REQUEST_METHOD']);
    
    if ($isWeb) {
        // Web request - gather HTTP context
        $context = [
            'http_method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'referer' => $_SERVER['HTTP_REFERER'] ?? null,
            'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? null,
            'request_uri' => $_SERVER['REQUEST_URI'] ?? null,
        ];
        
        // Remove null values to keep logs clean
        $context = array_filter($context, fn($v) => $v !== null);
        
        return [
            'trigger' => 'web_request',
            'context' => $context
        ];
    } else {
        // CLI context - check if it's a cron job or manual run
        $isCron = false;
        $context = [
            'pid' => getmypid(),
            'ppid' => function_exists('posix_getppid') ? @posix_getppid() : null,
        ];
        
        // Try to detect cron by checking parent process or environment
        // Cron jobs typically have specific environment variables or parent process names
        if (function_exists('posix_getppid')) {
            $ppid = @posix_getppid();
            if ($ppid > 0) {
                // Try to read parent process name from /proc (Linux) or ps (other systems)
                if (is_readable("/proc/{$ppid}/comm")) {
                    $parentName = trim(@file_get_contents("/proc/{$ppid}/comm"));
                    if (stripos($parentName, 'cron') !== false || stripos($parentName, 'crond') !== false) {
                        $isCron = true;
                        $context['parent_process'] = $parentName;
                    }
                }
            }
        }
        
        // Check environment variables that cron typically sets
        if (!$isCron) {
            $cronEnvVars = ['CRON', 'CROND', 'RUNLEVEL'];
            foreach ($cronEnvVars as $var) {
                if (getenv($var) !== false) {
                    $isCron = true;
                    $context['cron_env_var'] = $var;
                    break;
                }
            }
        }
        
        // Check if running via cron by checking if stdin is not a TTY
        if (!$isCron && function_exists('posix_isatty')) {
            if (!@posix_isatty(STDIN)) {
                // Non-interactive - likely cron
                $isCron = true;
                $context['non_interactive'] = true;
            }
        }
        
        // Remove null values
        $context = array_filter($context, fn($v) => $v !== null);
        
        return [
            'trigger' => $isCron ? 'cron_job' : 'manual_cli',
            'context' => $context
        ];
    }
}
}
