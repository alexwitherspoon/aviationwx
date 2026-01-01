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
 * Clean up stale worker heartbeat files
 * 
 * Finds and removes heartbeat files from workers that appear to have died
 * without cleaning up. Also returns PIDs of potentially stuck workers.
 * 
 * @param int|null $staleSeconds Consider heartbeat stale after this many seconds (default: worker_timeout + 30)
 * @return int[] PIDs of potentially stuck workers (empty if none found)
 */
function cleanupStaleWorkerHeartbeats(?int $staleSeconds = null): array {
    if ($staleSeconds === null) {
        $staleSeconds = getWorkerTimeout() + 30;
    }
    
    $stuckPids = [];
    $pattern = '/tmp/worker_heartbeat_*.json';
    $files = glob($pattern);
    
    // glob() returns false on error, empty array if no matches
    if ($files === false || empty($files)) {
        return $stuckPids;
    }
    
    $now = time();
    foreach ($files as $file) {
        $content = @file_get_contents($file);
        if ($content === false) {
            continue;
        }
        
        $data = @json_decode($content, true);
        if (!is_array($data) || !isset($data['heartbeat']) || !isset($data['pid'])) {
            // Invalid format, just delete
            @unlink($file);
            continue;
        }
        
        $heartbeatAge = $now - $data['heartbeat'];
        if ($heartbeatAge > $staleSeconds) {
            // Heartbeat is stale - worker may be stuck
            $pid = (int)$data['pid'];
            
            // Use cross-platform process check from process-utils.php
            if ($pid > 0 && isProcessRunning($pid, 'php')) {
                $stuckPids[] = $pid;
                aviationwx_log('warning', 'worker heartbeat stale - process may be stuck', [
                    'pid' => $pid,
                    'heartbeat_age' => $heartbeatAge,
                    'file' => basename($file)
                ], 'app');
            }
            
            // Clean up the file regardless
            @unlink($file);
        }
    }
    
    return $stuckPids;
}

/**
 * Kill stuck worker processes
 * 
 * Attempts to terminate stuck worker processes identified by cleanupStaleWorkerHeartbeats().
 * Uses SIGTERM first, then SIGKILL if process doesn't exit.
 * 
 * @param int[] $pids Array of PIDs to kill
 * @param string $expectedName Process name substring to verify before killing (safety check)
 * @return int Number of processes killed
 */
function killStuckWorkers(array $pids, string $expectedName = 'php'): int {
    // Require posix_kill and signal constants for this function
    if (!function_exists('posix_kill') || !defined('SIGTERM') || !defined('SIGKILL')) {
        return 0;
    }
    
    $killed = 0;
    
    foreach ($pids as $pid) {
        $pid = (int)$pid;
        if ($pid <= 0) {
            continue;
        }
        
        // Use cross-platform process check with name verification
        // This prevents killing unrelated processes (e.g., PID reuse)
        if (!isProcessRunning($pid, $expectedName)) {
            continue;
        }
        
        // Try SIGTERM first (graceful shutdown)
        $result = @posix_kill($pid, SIGTERM);
        if ($result) {
            usleep(500000); // 500ms grace period
            
            // Check if still running
            if (isProcessRunning($pid)) {
                // Force kill
                @posix_kill($pid, SIGKILL);
            }
            
            $killed++;
            aviationwx_log('info', 'killed stuck worker', [
                'pid' => $pid
            ], 'app');
        }
    }
    
    return $killed;
}

