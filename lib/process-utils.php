<?php
/**
 * Process Utilities
 * 
 * Helper functions for process management and detection.
 */

/**
 * Check if a process is running by PID
 * 
 * Uses /proc filesystem which is readable regardless of process ownership,
 * unlike posix_kill() which requires permission to send signals.
 * Falls back to posix_kill() on non-Linux systems.
 * 
 * @param int $pid Process ID to check
 * @param string|null $expectedName Optional: expected process name substring to verify
 * @return bool True if process exists and is running
 */
function isProcessRunning(int $pid, ?string $expectedName = null): bool {
    if ($pid <= 0) {
        return false;
    }
    
    // Primary method: Check /proc/{pid} directory (Linux)
    // This works regardless of the process owner and is the most reliable method
    $procDir = "/proc/{$pid}";
    if (is_dir($procDir)) {
        // Optionally verify it's the expected process by checking cmdline
        $cmdlineFile = "{$procDir}/cmdline";
        if (is_readable($cmdlineFile)) {
            $cmdline = @file_get_contents($cmdlineFile);
            // cmdline uses null bytes as separators
            if ($cmdline !== false && strlen($cmdline) > 0) {
                // If expectedName provided, verify it matches
                if ($expectedName !== null) {
                    if (stripos($cmdline, $expectedName) !== false) {
                        return true;
                    }
                    // Name doesn't match - could be PID reuse, return false
                    return false;
                }
                // No name check required, process exists
                return true;
            }
        }
        // Directory exists but can't read cmdline - process exists
        return true;
    }
    
    // Fallback method: posix_kill with signal 0
    // This may fail if the process is owned by a different user (EPERM)
    // Only use this as a fallback for non-Linux systems
    if (function_exists('posix_kill')) {
        $result = @posix_kill($pid, 0);
        if ($result) {
            return true;
        }
        // Check if error was permission denied (EPERM = 1)
        // If EPERM, the process exists but we can't signal it
        $errno = posix_get_last_error();
        if ($errno === 1) { // EPERM - Operation not permitted
            return true; // Process exists, we just can't signal it
        }
    }
    
    return false;
}

/**
 * Check if we can send signals to a process
 * 
 * This is useful to determine if we have permission to kill/restart a process.
 * Returns true if we can send signals, false if EPERM or process doesn't exist.
 * 
 * @param int $pid Process ID to check
 * @return bool True if we can send signals to this process
 */
function canSignalProcess(int $pid): bool {
    if ($pid <= 0) {
        return false;
    }
    
    if (!function_exists('posix_kill')) {
        return false;
    }
    
    $result = @posix_kill($pid, 0);
    return $result === true;
}

