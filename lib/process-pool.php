<?php
/**
 * Process Pool Manager
 * Manages worker processes for parallel execution with timeout and duplicate prevention
 */

require_once __DIR__ . '/logger.php';

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
     * Weather: "kspb", Webcam: "kspb_0"
     */
    private function getJobKey(array $args) {
        return implode('_', $args);
    }
    
    /**
     * Check if job is already running (cleans up finished workers first)
     */
    private function isJobRunning($jobKey) {
        $dummyStats = ['completed' => 0, 'timed_out' => 0, 'failed' => 0];
        $this->cleanupFinished($dummyStats);
        return isset($this->activeJobs[$jobKey]);
    }
    
    /**
     * Add job to pool (skips duplicates, waits for slot if full)
     * @return bool True if added, false if duplicate
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
     * @return array ['completed' => int, 'timed_out' => int, 'failed' => int]
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
     */
    public function getActiveCount() {
        $dummyStats = ['completed' => 0, 'timed_out' => 0, 'failed' => 0];
        $this->cleanupFinished($dummyStats);
        return count($this->workers);
    }
    
    /**
     * Spawn worker process
     * @return array|null Worker data or null on failure
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
     * @param array &$stats Statistics to update
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

