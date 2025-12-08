<?php
/**
 * Process Pool Manager
 * Manages worker processes for parallel execution with timeout and duplicate prevention
 */

require_once __DIR__ . '/logger.php';

/**
 * Process Pool Manager
 * 
 * Manages a pool of worker processes for parallel execution of tasks.
 * Features:
 * - Configurable maximum concurrent workers
 * - Per-worker timeout handling
 * - Duplicate job prevention
 * - Automatic cleanup of finished/timed-out workers
 * - Thread-safe job tracking
 * 
 * Used by fetch-weather.php and fetch-webcam.php for parallel processing.
 */
class ProcessPool {
    private $maxWorkers;
    private $timeout;
    private $workers = [];
    private $activeJobs = []; // Track active jobs by key to prevent duplicates
    private $scriptName;
    private $invocationId;
    
    /**
     * @param int $maxWorkers Maximum number of concurrent workers
     * @param int $timeout Timeout in seconds for each worker
     * @param string $scriptName Name of the script (for logging)
     * @param string $invocationId Invocation ID for logging correlation
     */
    public function __construct($maxWorkers, $timeout, $scriptName, $invocationId) {
        $this->maxWorkers = max(1, (int)$maxWorkers);
        $this->timeout = max(1, (int)$timeout);
        $this->scriptName = $scriptName;
        $this->invocationId = $invocationId;
    }
    
    /**
     * Generate job key from arguments to identify duplicates
     * 
     * Creates a unique key from job arguments to prevent duplicate jobs.
     * Examples: Weather job "kspb" -> "kspb", Webcam job ["kspb", 0] -> "kspb_0"
     * 
     * @param array $args Job arguments
     * @return string Job key (underscore-separated arguments)
     */
    private function getJobKey(array $args) {
        return implode('_', $args);
    }
    
    /**
     * Check if job is already running (cleans up finished workers first)
     * 
     * Checks if a job with the given key is currently active.
     * Automatically cleans up finished workers before checking.
     * 
     * @param string $jobKey Job key to check
     * @return bool True if job is running, false otherwise
     */
    private function isJobRunning($jobKey) {
        $dummyStats = ['completed' => 0, 'timed_out' => 0, 'failed' => 0];
        $this->cleanupFinished($dummyStats);
        return isset($this->activeJobs[$jobKey]);
    }
    
    /**
     * Add job to pool (skips duplicates, waits for slot if full)
     * 
     * Adds a new job to the worker pool. Skips if duplicate job is already running.
     * Waits for available slot if pool is full (max 5 minutes timeout).
     * 
     * @param array $args Job arguments (e.g., ['kspb'] for weather, ['kspb', 0] for webcam)
     * @return bool True if added successfully, false if duplicate or spawn failed
     */
    public function addJob(array $args) {
        $jobKey = $this->getJobKey($args);
        
        if ($this->isJobRunning($jobKey)) {
            aviationwx_log('info', 'process pool: job skipped - already running', [
                'invocation_id' => $this->invocationId,
                'script' => $this->scriptName,
                'args' => $args,
                'job_key' => $jobKey
            ], 'app');
            return false;
        }
        
        $this->waitForSlot();
        
        $worker = $this->spawnWorker($args);
        if ($worker !== null) {
            $this->workers[] = $worker;
            $this->activeJobs[$jobKey] = true;
            return true;
        }
        
        return false;
    }
    
    /**
     * Wait for all workers to complete
     * 
     * Blocks until all workers in the pool have finished (completed, timed out, or failed).
     * Periodically checks worker status and cleans up finished workers.
     * 
     * @return array {
     *   'completed' => int,   // Number of successfully completed jobs
     *   'timed_out' => int,    // Number of jobs that exceeded timeout
     *   'failed' => int        // Number of jobs that failed (non-zero exit code)
     * }
     */
    public function waitForAll() {
        $stats = ['completed' => 0, 'timed_out' => 0, 'failed' => 0];
        
        while (!empty($this->workers)) {
            $this->cleanupFinished($stats);
            usleep(100000);
        }
        
        return $stats;
    }
    
    /**
     * Get current number of active workers
     * 
     * Returns the count of currently running workers. Automatically cleans up
     * finished workers before counting.
     * 
     * @return int Number of active workers (0 to maxWorkers)
     */
    public function getActiveCount() {
        $dummyStats = ['completed' => 0, 'timed_out' => 0, 'failed' => 0];
        $this->cleanupFinished($dummyStats);
        return count($this->workers);
    }
    
    /**
     * Spawn worker process
     * 
     * Creates a new worker process using proc_open(). The worker runs the script
     * in --worker mode with the provided arguments.
     * 
     * @param array $args Job arguments to pass to worker script
     * @return array|null Worker data array with keys: 'proc' (resource), 'pipes' (array),
     *   'started' (int timestamp), 'args' (array), 'pid' (int|null), or null on failure
     */
    private function spawnWorker(array $args) {
        $scriptPath = __DIR__ . '/../scripts/' . basename($this->scriptName);
        
        $cmdParts = [
            '/usr/local/bin/php',
            escapeshellarg($scriptPath),
            '--worker'
        ];
        foreach ($args as $arg) {
            $cmdParts[] = escapeshellarg((string)$arg);
        }
        $command = implode(' ', $cmdParts) . ' 2>&1';
        
        $pipes = [];
        $process = @proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ], $pipes);
        
        if (!is_resource($process)) {
            aviationwx_log('error', 'process pool: failed to spawn worker', [
                'invocation_id' => $this->invocationId,
                'script' => $this->scriptName,
                'args' => $args
            ], 'app');
            return null;
        }
        
        fclose($pipes[0]); // Workers don't need stdin
        
        $worker = [
            'proc' => $process,
            'pipes' => $pipes,
            'started' => time(),
            'args' => $args,
            'pid' => proc_get_status($process)['pid'] ?? null
        ];
        
        aviationwx_log('info', 'process pool: worker spawned', [
            'invocation_id' => $this->invocationId,
            'script' => $this->scriptName,
            'args' => $args,
            'pid' => $worker['pid'],
            'active_workers' => count($this->workers) + 1,
            'max_workers' => $this->maxWorkers
        ], 'app');
        
        return $worker;
    }
    
    /**
     * Clean up finished or timed-out workers
     * 
     * Checks all workers and removes those that have finished or exceeded timeout.
     * Updates statistics array with counts of completed, timed-out, and failed jobs.
     * Terminates timed-out workers with SIGTERM, then SIGKILL if needed.
     * 
     * @param array &$stats Statistics array to update (passed by reference)
     *   - 'completed' => int (incremented for successful jobs)
     *   - 'timed_out' => int (incremented for timed-out jobs)
     *   - 'failed' => int (incremented for failed jobs)
     * @return void
     */
    private function cleanupFinished(array &$stats) {
        $now = time();
        
        foreach ($this->workers as $key => $worker) {
            $status = @proc_get_status($worker['proc']);
            if (!$status) {
                $this->closePipes($worker['pipes']);
                $jobKey = $this->getJobKey($worker['args']);
                unset($this->activeJobs[$jobKey]);
                unset($this->workers[$key]);
                $stats['failed']++;
                continue;
            }
            
            $elapsed = $now - $worker['started'];
            if ($status['running'] && $elapsed > $this->timeout) {
                aviationwx_log('warning', 'process pool: worker timeout, terminating', [
                    'invocation_id' => $this->invocationId,
                    'script' => $this->scriptName,
                    'args' => $worker['args'],
                    'pid' => $worker['pid'],
                    'elapsed' => $elapsed,
                    'timeout' => $this->timeout
                ], 'app');
                
                @proc_terminate($worker['proc'], SIGTERM);
                usleep(100000);
                
                $status = @proc_get_status($worker['proc']);
                if ($status && $status['running']) {
                    @proc_terminate($worker['proc'], SIGKILL);
                }
                
                @proc_close($worker['proc']);
                $this->closePipes($worker['pipes']);
                $jobKey = $this->getJobKey($worker['args']);
                unset($this->activeJobs[$jobKey]);
                
                unset($this->workers[$key]);
                $stats['timed_out']++;
                continue;
            }
            
            if (!$status['running']) {
                $exitCode = $status['exitcode'];
                $elapsed = $now - $worker['started'];
                
                @proc_close($worker['proc']);
                $this->closePipes($worker['pipes']);
                $jobKey = $this->getJobKey($worker['args']);
                unset($this->activeJobs[$jobKey]);
                
                if ($exitCode === 0) {
                    $stats['completed']++;
                } else {
                    $stats['failed']++;
                    aviationwx_log('warning', 'process pool: worker failed', [
                        'invocation_id' => $this->invocationId,
                        'script' => $this->scriptName,
                        'args' => $worker['args'],
                        'pid' => $worker['pid'],
                        'exit_code' => $exitCode,
                        'elapsed' => $elapsed
                    ], 'app');
                }
                
                unset($this->workers[$key]);
            }
        }
    }
    
    /**
     * Close all pipes for a worker
     * 
     * Closes all pipe resources (stdin, stdout, stderr) for a worker process.
     * Prevents resource leaks when workers are cleaned up.
     * 
     * @param array $pipes Array of pipe resources to close
     * @return void
     */
    private function closePipes(array $pipes) {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }
    }
    
    /**
     * Wait for available slot in pool (max 5 minutes)
     * 
     * Blocks until a slot becomes available in the worker pool.
     * Periodically checks for finished workers and cleans them up.
     * Times out after 5 minutes to prevent indefinite blocking.
     * 
     * @return void
     */
    private function waitForSlot() {
        $maxWait = 300;
        $waited = 0;
        
        while (count($this->workers) >= $this->maxWorkers && $waited < $maxWait) {
            $dummyStats = ['completed' => 0, 'timed_out' => 0, 'failed' => 0];
            $this->cleanupFinished($dummyStats);
            
            if (count($this->workers) < $this->maxWorkers) {
                break;
            }
            
            usleep(100000);
            $waited += 0.1;
        }
        
        if ($waited >= $maxWait) {
            aviationwx_log('error', 'process pool: timeout waiting for slot', [
                'invocation_id' => $this->invocationId,
                'script' => $this->scriptName,
                'max_workers' => $this->maxWorkers,
                'active_workers' => count($this->workers)
            ], 'app');
        }
    }
    
    /**
     * Cleanup: terminate all remaining workers (call on script exit)
     * 
     * Terminates all active workers and cleans up resources.
     * Should be called on script exit (via register_shutdown_function).
     * Attempts graceful termination (SIGTERM) first, then force kill (SIGKILL).
     * 
     * @return void
     */
    public function cleanup() {
        foreach ($this->workers as $worker) {
            $status = @proc_get_status($worker['proc']);
            if ($status && $status['running']) {
                @proc_terminate($worker['proc'], SIGTERM);
                usleep(100000);
                $status = @proc_get_status($worker['proc']);
                if ($status && $status['running']) {
                    @proc_terminate($worker['proc'], SIGKILL);
                }
            }
            @proc_close($worker['proc']);
            $this->closePipes($worker['pipes']);
        }
        $this->workers = [];
        $this->activeJobs = [];
    }
}

