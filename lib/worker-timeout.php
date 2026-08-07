<?php
/**
 * Worker Self-Timeout Utilities
 * 
 * Provides self-termination mechanisms for worker processes to prevent zombies.
 * Workers should call initWorkerTimeout() at startup to ensure they terminate
 * themselves if they exceed their expected runtime.
 * 
 * Defense-in-depth approach:
 * 1. set_time_limit() - PHP execution timeout (honored by most operations)
 * 2. pcntl_alarm() - SIGALRM timer for guaranteed termination
 * 3. Heartbeat file - Allows parent to detect stuck workers
 */

require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/process-utils.php';

// Worker state globals (prefixed to avoid conflicts)
// These are intentionally global for CLI single-process workers
$GLOBALS['_aviationwx_worker_start_time'] = null;
$GLOBALS['_aviationwx_worker_timeout'] = null;
$GLOBALS['_aviationwx_worker_heartbeat_file'] = null;

/**
 * Initialize worker self-timeout mechanisms
 * 
 * Sets up multiple layers of self-termination:
 * - PHP set_time_limit() for execution timeout
 * - SIGALRM via pcntl_alarm() for guaranteed termination
 * - Optional heartbeat file for external monitoring
 * 
 * Call this at the start of worker mode execution.
 * 
 * @param int|null $timeout Timeout in seconds (default: worker_timeout_seconds config - 5s buffer)
 * @param string|null $heartbeatId Optional identifier for heartbeat file (e.g., "kspb_0")
 * @return void
 */
function initWorkerTimeout(?int $timeout = null, ?string $heartbeatId = null): void {
    $GLOBALS['_aviationwx_worker_start_time'] = time();
    
    // Default timeout: configured worker timeout minus 5-second buffer for cleanup
    // This ensures worker exits before ProcessPool's hard kill
    if ($timeout === null) {
        $timeout = max(10, getWorkerTimeout() - 5);
    }
    $GLOBALS['_aviationwx_worker_timeout'] = $timeout;
    
    // 1. PHP execution timeout (works for most operations, but not all I/O)
    // Add a small buffer to allow SIGALRM to fire first
    @set_time_limit($timeout + 2);
    
    // 2. SIGALRM timer - guaranteed termination (only works in CLI with pcntl)
    if (function_exists('pcntl_alarm') && function_exists('pcntl_signal') && defined('SIGALRM')) {
        // Enable async signals so SIGALRM can interrupt blocking operations
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
        }
        
        // Install SIGALRM handler
        pcntl_signal(SIGALRM, function($signo) {
            $startTime = $GLOBALS['_aviationwx_worker_start_time'] ?? time();
            $workerTimeout = $GLOBALS['_aviationwx_worker_timeout'] ?? 0;
            $runtime = time() - $startTime;
            
            aviationwx_log('warning', 'worker self-terminating via SIGALRM', [
                'pid' => getmypid(),
                'runtime' => $runtime,
                'timeout' => $workerTimeout
            ], 'app');
            
            // Clean exit with non-zero code to indicate timeout
            exit(124); // Same exit code as 'timeout' command
        });
        
        // Schedule SIGALRM
        pcntl_alarm($timeout);
    }
    
    // 3. Heartbeat file (optional)
    if ($heartbeatId !== null) {
        // Sanitize heartbeatId to prevent path traversal (allow only alphanumeric, underscore, hyphen)
        $safeId = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $heartbeatId);
        $GLOBALS['_aviationwx_worker_heartbeat_file'] = "/tmp/worker_heartbeat_{$safeId}.json";
        updateWorkerHeartbeat();
    }
    
    // Register shutdown function to clean up heartbeat file
    register_shutdown_function(function() {
        $heartbeatFile = $GLOBALS['_aviationwx_worker_heartbeat_file'] ?? null;
        if ($heartbeatFile && file_exists($heartbeatFile)) {
            @unlink($heartbeatFile);
        }
    });
}

/**
 * Update worker heartbeat file
 * 
 * Call this periodically during long-running operations to indicate
 * the worker is still alive and making progress.
 * 
 * @return void
 */
function updateWorkerHeartbeat(): void {
    $heartbeatFile = $GLOBALS['_aviationwx_worker_heartbeat_file'] ?? null;
    
    if ($heartbeatFile === null) {
        return;
    }
    
    $data = [
        'pid' => getmypid(),
        'started' => $GLOBALS['_aviationwx_worker_start_time'],
        'heartbeat' => time(),
        'timeout' => $GLOBALS['_aviationwx_worker_timeout']
    ];

    $script = basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    if ($script === '' && isset($_SERVER['argv'][0])) {
        $script = basename((string) $_SERVER['argv'][0]);
    }
    if ($script !== '' && $script !== '.' && $script !== '..') {
        $data['script'] = $script;
    }
    
    @file_put_contents($heartbeatFile, json_encode($data), LOCK_EX);
}

/**
 * Check remaining worker time
 * 
 * Returns the number of seconds remaining before worker self-terminates.
 * Useful for deciding whether to start new operations.
 * 
 * @return int Seconds remaining (0 or negative means timeout imminent)
 */
function getWorkerTimeRemaining(): int {
    $startTime = $GLOBALS['_aviationwx_worker_start_time'] ?? null;
    $timeout = $GLOBALS['_aviationwx_worker_timeout'] ?? null;
    
    if ($startTime === null || $timeout === null) {
        return PHP_INT_MAX; // No timeout configured
    }
    
    $elapsed = time() - $startTime;
    return max(0, $timeout - $elapsed);
}

/**
 * Check if worker should abort current operation
 * 
 * Returns true if the worker is approaching its timeout and should
 * abort any new long-running operations.
 * 
 * @param int $requiredSeconds Minimum seconds required for next operation
 * @return bool True if worker should abort, false if safe to continue
 */
function shouldWorkerAbort(int $requiredSeconds = 10): bool {
    return getWorkerTimeRemaining() < $requiredSeconds;
}

/**
 * Silence allowed after the last heartbeat before a worker is treated as stuck.
 *
 * Also used as the absolute runtime ceiling from `started` so a worker that
 * keeps refreshing heartbeats past its declared timeout is still cleaned up.
 *
 * @param array<string, mixed> $data Heartbeat JSON payload
 * @param int|null $staleSecondsOverride When set, applies to all workers
 * @param int $defaultStaleSeconds Fallback when no declared timeout (typically getWorkerTimeout()+30)
 * @return int Seconds of no heartbeat progress before the worker is stale
 */
function workerHeartbeatStaleAfterSeconds(
    array $data,
    ?int $staleSecondsOverride,
    int $defaultStaleSeconds
): int {
    if ($staleSecondsOverride !== null) {
        return max(1, $staleSecondsOverride);
    }

    $declaredTimeout = isset($data['timeout']) && is_numeric($data['timeout'])
        ? (int) $data['timeout']
        : 0;
    if ($declaredTimeout <= 0) {
        return max(1, $defaultStaleSeconds);
    }

    // Cap declared timeout before adding the buffer (avoids int overflow on corrupt values).
    return min($declaredTimeout, 86400) + 30;
}

/**
 * Whether a heartbeat glob is restricted to /tmp/worker_heartbeat_*.json paths.
 */
function workerHeartbeatGlobIsAllowed(string $globPattern): bool
{
    // \w includes underscore (nasr_apt, test_...); * ? - are glob metacharacters.
    return preg_match('#^/tmp/worker_heartbeat_[\w*?-]*\.json$#', $globPattern) === 1;
}

/**
 * Cmdline substring used to verify a heartbeat PID before kill (limits PID reuse risk).
 *
 * Heartbeat files live in world-writable /tmp, so "script" is only trusted when it
 * looks like a worker basename (e.g. fetch-nasr-apt.php). Broad tokens like "php"
 * fall back to scripts/ (same idea as scheduler-health-check's "scheduler" token).
 *
 * @param array<string, mixed> $data Heartbeat JSON payload
 */
function workerHeartbeatExpectedProcessName(array $data): string
{
    if (!empty($data['script']) && is_string($data['script'])) {
        $script = basename($data['script']);
        // Reject free-form / broad tokens from forged heartbeat files.
        if (preg_match('/^[A-Za-z0-9_-]+\.php$/', $script) === 1) {
            return $script;
        }
    }

    // CLI workers live under scripts/; avoids matching php-fpm pool workers.
    return 'scripts/';
}

/**
 * Clean up stale worker heartbeat files and return stuck workers to kill.
 *
 * @param int|null $staleSeconds Override for all files; null uses each file's
 *        declared timeout (+30s) or getWorkerTimeout()+30.
 * @param string $globPattern Heartbeat file glob (tests may narrow within /tmp)
 * @return list<array{pid: int, expected_name: string}>
 */
function cleanupStaleWorkerHeartbeats(
    ?int $staleSeconds = null,
    string $globPattern = '/tmp/worker_heartbeat_*.json'
): array {
    $stuckWorkers = [];
    if (!workerHeartbeatGlobIsAllowed($globPattern)) {
        return $stuckWorkers;
    }

    $files = glob($globPattern);

    if ($files === false || empty($files)) {
        return $stuckWorkers;
    }

    $now = time();
    $defaultStaleSeconds = getWorkerTimeout() + 30;
    foreach ($files as $file) {
        $content = @file_get_contents($file);
        if ($content === false) {
            continue;
        }

        $data = @json_decode($content, true);
        if (!is_array($data) || !isset($data['heartbeat']) || !isset($data['pid'])) {
            @unlink($file);
            continue;
        }

        $effectiveStale = workerHeartbeatStaleAfterSeconds($data, $staleSeconds, $defaultStaleSeconds);
        $heartbeatAge = $now - (int) $data['heartbeat'];
        $started = isset($data['started']) ? (int) $data['started'] : 0;
        // Absolute runtime past declared timeout (defense if heartbeats keep refreshing).
        $pastAbsoluteDeadline = $started > 0 && ($now - $started) > $effectiveStale;
        if ($heartbeatAge <= $effectiveStale && !$pastAbsoluteDeadline) {
            continue;
        }

        $pid = (int) $data['pid'];
        $expectedName = workerHeartbeatExpectedProcessName($data);

        if ($pid > 0 && isProcessRunning($pid, $expectedName)) {
            $stuckWorkers[] = [
                'pid' => $pid,
                'expected_name' => $expectedName,
            ];
            aviationwx_log('warning', 'worker heartbeat stale - process may be stuck', [
                'pid' => $pid,
                'heartbeat_age' => $heartbeatAge,
                'stale_after' => $effectiveStale,
                'runtime' => $started > 0 ? ($now - $started) : null,
                'expected_name' => $expectedName,
                'file' => basename($file),
            ], 'app');
            // Keep stamp so a failed kill is retried on the next cleanup tick.
            continue;
        }

        @unlink($file);
    }

    return $stuckWorkers;
}

/**
 * Kill stuck workers returned by cleanupStaleWorkerHeartbeats().
 *
 * Matches ProcessPool / scheduler-health-check: SIGTERM, brief wait, then SIGKILL.
 *
 * @param list<array{pid: int, expected_name?: string}> $stuckWorkers
 * @return list<int> PIDs that were signaled (for accurate scheduler logs)
 */
function killStuckWorkers(array $stuckWorkers): array
{
    if (!function_exists('posix_kill') || !defined('SIGTERM') || !defined('SIGKILL')) {
        return [];
    }

    $killedPids = [];

    foreach ($stuckWorkers as $worker) {
        $pid = (int) ($worker['pid'] ?? 0);
        $expectedName = (string) ($worker['expected_name'] ?? 'scripts/');
        if ($pid <= 0 || $expectedName === '') {
            continue;
        }

        // Name check limits PID-reuse kills of unrelated processes.
        if (!isProcessRunning($pid, $expectedName)) {
            continue;
        }

        $result = @posix_kill($pid, SIGTERM);
        if (!$result) {
            continue;
        }

        usleep(500000);
        if (isProcessRunning($pid, $expectedName)) {
            @posix_kill($pid, SIGKILL);
        }

        $killedPids[] = $pid;
        aviationwx_log('info', 'killed stuck worker', [
            'pid' => $pid,
            'expected_name' => $expectedName,
        ], 'app');
    }

    return $killedPids;
}

