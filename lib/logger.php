<?php
// Lightweight JSONL logger that writes to log files
// Log rotation is handled by logrotate (1 rotated file, 100MB max per file)
// Supports dual logging: user activity log and application/system log

// File-based logging paths
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
    define('AVIATIONWX_LOG_MAX_FILES', 1); // 1 rotated file (matches logrotate config)
}

if (!function_exists('aviationwx_get_log_file_path')) {
/**
 * Get the file path for a log type
 * 
 * Returns the appropriate log file path based on log type.
 * 
 * @param string $logType Log type: 'app' or 'user' (default: 'app')
 * @return string Full path to log file
 */
function aviationwx_get_log_file_path(string $logType = 'app'): string {
    if ($logType === 'user') {
        return AVIATIONWX_USER_LOG_FILE;
    }
    return AVIATIONWX_APP_LOG_FILE;
}
}

if (!function_exists('aviationwx_init_log_dir')) {
/**
 * Initialize log directory structure
 * 
 * Creates log directory and initial log files if they don't exist.
 * 
 * @return void
 */
function aviationwx_init_log_dir(): void {
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
/**
 * Rotate log file if it exceeds maximum size
 * 
 * Implements log rotation: logfile -> logfile.1 (keeps 1 rotated file).
 * Deletes any existing rotated file before rotating to match logrotate config.
 * Note: This is a fallback mechanism. Primary rotation should be via logrotate.
 * 
 * @param string $logFile Full path to log file to check/rotate
 * @return void
 */
function aviationwx_rotate_log_if_needed(string $logFile): void {
    clearstatcache(true, $logFile);
    $size = @filesize($logFile);
    if ($size !== false && $size > AVIATIONWX_LOG_MAX_BYTES) {
        // Delete existing rotated file if it exists (MAX_FILES = 1, so only keep .1)
        $rotatedFile = $logFile . '.1';
        if (file_exists($rotatedFile)) {
            @unlink($rotatedFile);
        }
        // Delete any stray files beyond MAX_FILES (cleanup for safety)
        for ($i = AVIATIONWX_LOG_MAX_FILES + 1; $i <= AVIATIONWX_LOG_MAX_FILES + 10; $i++) {
            $oldFile = $logFile . '.' . $i;
            if (file_exists($oldFile)) {
                @unlink($oldFile);
            }
        }
        // Rotate: logfile -> logfile.1
        @rename($logFile, $rotatedFile);
        @touch($logFile);
    }
}
}

if (!function_exists('aviationwx_get_request_id')) {
/**
 * Get or generate request ID for request correlation
 * 
 * Returns a unique request ID for correlating log entries within a single request.
 * Uses X-Request-ID header if present, otherwise generates a random 16-character hex ID.
 * Uses static caching to ensure same ID is returned throughout request lifetime.
 * 
 * @return string Request ID (16 hex characters)
 */
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
/**
 * Log a message with structured context
 * 
 * Writes JSONL (JSON Lines) formatted log entries to log files.
 * Log rotation is handled by logrotate (with PHP fallback).
 * 
 * Log levels: 'debug', 'info', 'warning', 'error', 'critical', 'alert', 'emergency'
 * Automatically records error events for rate monitoring when level is warning or above.
 * 
 * @param string $level Log level (debug, info, warning, error, critical, alert, emergency)
 * @param string $message Log message
 * @param array $context Additional context data (key-value pairs)
 * @param string $logType Log type: 'app' or 'user' (default: 'app')
 * @param bool $isInternal True if this is an internal system error (affects error rate monitoring)
 * @return void
 */
function aviationwx_log(string $level, string $message, array $context = [], string $logType = 'app', bool $isInternal = false): void {
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
    
    // Error counter for alerting - only count internal errors for system health
    // External errors (data source failures) are expected and shouldn't trigger alerts
    if (in_array($entry['level'], ['warning','error','critical','alert','emergency'], true)) {
        aviationwx_record_error_event($isInternal);
    }
    
    // Format as JSONL (one JSON object per line)
    $jsonLine = json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n";
    
    // File-based logging
    aviationwx_init_log_dir();
    $logFile = aviationwx_get_log_file_path($logType);
    aviationwx_rotate_log_if_needed($logFile);
    @file_put_contents($logFile, $jsonLine, FILE_APPEND | LOCK_EX);
    
    // Send errors/critical to Sentry
    if (defined('SENTRY_INITIALIZED') && SENTRY_INITIALIZED) {
        $sentryLevels = ['error', 'critical', 'alert', 'emergency'];
        
        if (in_array($level, $sentryLevels, true)) {
            // Map severity
            $sentryLevel = match($level) {
                'error' => \Sentry\Severity::error(),
                'critical', 'alert', 'emergency' => \Sentry\Severity::fatal(),
                default => \Sentry\Severity::warning(),
            };
            
            // Add breadcrumb for context
            \Sentry\addBreadcrumb(new \Sentry\Breadcrumb(
                \Sentry\Breadcrumb::LEVEL_INFO,
                \Sentry\Breadcrumb::TYPE_DEFAULT,
                'log',
                $message,
                $context
            ));
            
            // Capture with context
            \Sentry\withScope(function (\Sentry\State\Scope $scope) use ($message, $sentryLevel, $context, $logType): void {
                // Add tags for filtering
                $scope->setTag('log_type', $logType);
                
                if (isset($context['source'])) {
                    $scope->setTag('log_source', $context['source']);
                }
                
                if (isset($context['airport_id'])) {
                    $scope->setTag('airport_id', $context['airport_id']);
                }
                
                if (isset($context['weather_source'])) {
                    $scope->setTag('weather_source', $context['weather_source']);
                }
                
                // Add full context as additional data
                $scope->setContext('log_context', $context);
                
                // Send event
                \Sentry\captureMessage($message, $sentryLevel);
            });
        }
    }
}
}

if (!function_exists('aviationwx_record_error_event')) {
/**
 * Record an error event for rate monitoring
 * 
 * Stores error event timestamps in APCu for tracking error rates.
 * Used internally by aviationwx_log() when logging warnings or errors.
 * Events older than 3600 seconds are automatically purged.
 * 
 * Separates internal system errors from external data source failures.
 * Only internal errors are counted for system health monitoring.
 * 
 * @param bool $isInternal True if this is an internal system error, false for external data source failures
 * @return void
 */
function aviationwx_record_error_event(bool $isInternal = false): void {
    if (!function_exists('apcu_fetch')) return;
    
    // Only track internal errors for system health monitoring
    // External errors (data source failures) are expected and shouldn't trigger alerts
    if (!$isInternal) return;
    
    $key = 'aviationwx_internal_error_events';
    $events = apcu_fetch($key);
    if (!is_array($events)) $events = [];
    $now = time();
    $events[] = $now;
    // Purge older than ERROR_RATE_WINDOW_SECONDS
    if (!defined('ERROR_RATE_WINDOW_SECONDS')) {
        require_once __DIR__ . '/constants.php';
    }
    $threshold = $now - ERROR_RATE_WINDOW_SECONDS;
    $events = array_values(array_filter($events, fn($t) => $t >= $threshold));
    apcu_store($key, $events, ERROR_RATE_WINDOW_SECONDS);
}
}

if (!function_exists('aviationwx_error_rate_last_hour')) {
/**
 * Get internal error count from the last hour
 * 
 * Returns the number of internal system error/warning events recorded in the last 60 minutes.
 * Only counts internal system errors, not external data source failures.
 * Used for alerting and monitoring system health.
 * 
 * @return int Number of internal error events in the last hour (0 if APCu unavailable)
 */
function aviationwx_error_rate_last_hour(): int {
    if (!function_exists('apcu_fetch')) return 0;
    $events = apcu_fetch('aviationwx_internal_error_events');
    if (!is_array($events)) return 0;
    if (!defined('ERROR_RATE_WINDOW_SECONDS')) {
        require_once __DIR__ . '/constants.php';
    }
    $now = time();
    $threshold = $now - ERROR_RATE_WINDOW_SECONDS;
    $events = array_values(array_filter($events, fn($t) => $t >= $threshold));
    return count($events);
}
}

if (!function_exists('aviationwx_maybe_log_alert')) {
/**
 * Log alert if error rate is high
 * 
 * Checks error rate for the last hour and logs an info-level alert if >= 5 errors.
 * Uses 'info' level to avoid feedback loop (this is a metric, not an error).
 * 
 * @return void
 */
function aviationwx_maybe_log_alert(): void {
    $count = aviationwx_error_rate_last_hour();
    if ($count >= 5) {
        // Use 'info' level to avoid feedback loop - this is a metric, not an error
        // Mark as internal=false to avoid counting this alert itself
        aviationwx_log('info', 'High internal error rate in last 60 minutes', ['internal_errors_last_hour' => $count], 'app', false);
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
