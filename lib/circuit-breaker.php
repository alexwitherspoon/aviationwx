<?php
/**
 * Circuit Breaker Utility
 * Shared circuit breaker logic for weather and webcam sources
 * Implements exponential backoff with severity-based scaling
 */

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/logger.php';

/**
 * Base circuit breaker check
 * 
 * Checks if a resource should be skipped due to circuit breaker backoff.
 * Returns skip status, remaining backoff time, and failure count.
 * 
 * @param string $key Unique identifier (e.g., 'kspb_weather_primary' or 'kspb_0')
 * @param string $backoffFile Path to backoff.json file
 * @return array {
 *   'skip' => bool,              // True if should skip (circuit open)
 *   'reason' => string,          // Reason for skip ('circuit_open' or '')
 *   'backoff_remaining' => int,  // Seconds remaining in backoff period
 *   'failures' => int            // Number of consecutive failures
 * }
 */
function checkCircuitBreakerBase($key, $backoffFile) {
    $now = time();
    
    if (!file_exists($backoffFile)) {
        return ['skip' => false, 'reason' => '', 'backoff_remaining' => 0, 'failures' => 0];
    }
    
    // Clear stat cache to ensure we read the latest file contents
    clearstatcache(true, $backoffFile);
    $backoffData = @json_decode(@file_get_contents($backoffFile), true) ?: [];
    
    if (!isset($backoffData[$key])) {
        return ['skip' => false, 'reason' => '', 'backoff_remaining' => 0, 'failures' => 0];
    }
    
    $state = $backoffData[$key];
    $nextAllowed = (int)($state['next_allowed_time'] ?? 0);
    
    // If nextAllowed <= now, backoff has expired
    if ($nextAllowed > $now) {
        $remaining = $nextAllowed - $now;
        return [
            'skip' => true,
            'reason' => 'circuit_open',
            'backoff_remaining' => $remaining,
            'failures' => (int)($state['failures'] ?? 0)
        ];
    }
    
    // Backoff expired - entry will be cleaned up lazily on next write
    return ['skip' => false, 'reason' => '', 'backoff_remaining' => 0, 'failures' => 0];
}

/**
 * Base failure recording with file locking
 * 
 * Records a failure and updates exponential backoff state.
 * Uses file locking to prevent race conditions in concurrent environments.
 * Backoff duration scales with failure count and severity (permanent = 2x multiplier).
 * 
 * @param string $key Unique identifier
 * @param string $backoffFile Path to backoff.json file
 * @param string $severity 'transient' or 'permanent' (default: 'transient')
 *   - transient: Normal backoff scaling
 *   - permanent: 2x multiplier (for auth errors, config issues, etc.)
 * @param int|null $httpCode HTTP status code (4xx/5xx) if available, null otherwise
 * @return void
 */
function recordCircuitBreakerFailureBase($key, $backoffFile, $severity = 'transient', $httpCode = null) {
    $cacheDir = dirname($backoffFile);
    if (!file_exists($cacheDir)) {
        if (!@mkdir($cacheDir, 0755, true)) {
            error_log("Failed to create cache directory: {$cacheDir}");
            return;
        }
    }
    
    $now = time();
    
    // Use file locking to prevent race conditions
    $fp = @fopen($backoffFile, 'c+'); // c+ = read/write, create if not exists
    if ($fp === false) {
        // Can't open, fall back to non-locked write (non-critical)
        $backoffData = [];
        if (file_exists($backoffFile)) {
            $backoffData = @json_decode(@file_get_contents($backoffFile), true) ?: [];
        }
        
    if (!isset($backoffData[$key])) {
        $backoffData[$key] = ['failures' => 0, 'next_allowed_time' => 0, 'last_attempt' => 0, 'backoff_seconds' => 0];
    }
    
    $state = &$backoffData[$key];
    $state['failures'] = ((int)($state['failures'] ?? 0)) + 1;
    $state['last_attempt'] = $now;
    
    // Store HTTP code if provided and is 4xx/5xx
    if ($httpCode !== null && $httpCode >= 400 && $httpCode < 600) {
        $state['last_http_code'] = (int)$httpCode;
        $state['last_error_time'] = $now;
    }
    
    $failures = $state['failures'];
    $base = max(BACKOFF_BASE_SECONDS, pow(2, min($failures - 1, BACKOFF_MAX_FAILURES)) * BACKOFF_BASE_SECONDS);
    $multiplier = ($severity === 'permanent') ? 2.0 : 1.0;
    $cap = ($severity === 'permanent') ? BACKOFF_MAX_PERMANENT : BACKOFF_MAX_TRANSIENT;
    $backoffSeconds = min($cap, (int)round($base * $multiplier));
    $state['backoff_seconds'] = $backoffSeconds;
    $state['next_allowed_time'] = $now + $backoffSeconds;
        
        @file_put_contents($backoffFile, json_encode($backoffData, JSON_PRETTY_PRINT), LOCK_EX);
        return;
    }
    
    // Lock file for exclusive access
    if (@flock($fp, LOCK_EX)) {
        // Read with lock held
        $content = @stream_get_contents($fp);
        if ($content === false || $content === '') {
            $backoffData = [];
        } else {
            rewind($fp);
            $backoffData = @json_decode($content, true) ?: [];
        }
    } else {
        // Lock failed, fall back to non-locked read
        @fclose($fp);
        $backoffData = [];
        if (file_exists($backoffFile)) {
            $backoffData = @json_decode(@file_get_contents($backoffFile), true) ?: [];
        }
        @file_put_contents($backoffFile, json_encode($backoffData, JSON_PRETTY_PRINT), LOCK_EX);
        return;
    }
    
    if (!isset($backoffData[$key])) {
        $backoffData[$key] = ['failures' => 0, 'next_allowed_time' => 0, 'last_attempt' => 0, 'backoff_seconds' => 0];
    }
    
    $state = &$backoffData[$key];
    $state['failures'] = ((int)($state['failures'] ?? 0)) + 1;
    $state['last_attempt'] = $now;
    
    // Store HTTP code if provided and is 4xx/5xx
    if ($httpCode !== null && $httpCode >= 400 && $httpCode < 600) {
        $state['last_http_code'] = (int)$httpCode;
        $state['last_error_time'] = $now;
    }
    
    // Exponential backoff with severity scaling
    $failures = $state['failures'];
    $base = max(BACKOFF_BASE_SECONDS, pow(2, min($failures - 1, BACKOFF_MAX_FAILURES)) * BACKOFF_BASE_SECONDS);
    $multiplier = ($severity === 'permanent') ? 2.0 : 1.0;
    $cap = ($severity === 'permanent') ? BACKOFF_MAX_PERMANENT : BACKOFF_MAX_TRANSIENT;
    $backoffSeconds = min($cap, (int)round($base * $multiplier));
    $state['backoff_seconds'] = $backoffSeconds;
    $state['next_allowed_time'] = $now + $backoffSeconds;
    
    // Write with lock held
    @ftruncate($fp, 0);
    @rewind($fp);
    @fwrite($fp, json_encode($backoffData, JSON_PRETTY_PRINT));
    @fflush($fp);
    @flock($fp, LOCK_UN);
    @fclose($fp);
}

/**
 * Base success recording with file locking
 * 
 * Records a successful operation and resets circuit breaker state.
 * Clears failure count and backoff period. Uses file locking for thread safety.
 * 
 * @param string $key Unique identifier
 * @param string $backoffFile Path to backoff.json file
 * @return void
 */
function recordCircuitBreakerSuccessBase($key, $backoffFile) {
    $cacheDir = dirname($backoffFile);
    if (!file_exists($cacheDir)) {
        if (!@mkdir($cacheDir, 0755, true)) {
            error_log("Failed to create cache directory: {$cacheDir}");
            return;
        }
    }
    
    $now = time();
    
    // Use file locking to prevent race conditions
    $fp = @fopen($backoffFile, 'c+'); // c+ = read/write, create if not exists
    if ($fp === false) {
        // Can't lock, but we can still try to write (non-critical)
        $backoffData = [];
        if (file_exists($backoffFile)) {
            $backoffData = @json_decode(@file_get_contents($backoffFile), true) ?: [];
        }
    } else {
        // Lock file for exclusive access
        if (@flock($fp, LOCK_EX)) {
            // Read with lock held
            $content = @stream_get_contents($fp);
            if ($content === false || $content === '') {
                $backoffData = [];
            } else {
                rewind($fp);
                $backoffData = @json_decode($content, true) ?: [];
            }
        } else {
            // Lock failed, fall back to non-locked read
            $backoffData = [];
            if (file_exists($backoffFile)) {
                $backoffData = @json_decode(@file_get_contents($backoffFile), true) ?: [];
            }
        }
    }
    
    if (!isset($backoffData[$key])) {
        if (isset($fp) && $fp !== false) {
            @flock($fp, LOCK_UN);
            @fclose($fp);
        }
        return; // No previous state to reset
    }
    
    // Reset on success - clear HTTP code tracking
    $backoffData[$key] = [
        'failures' => 0,
        'next_allowed_time' => 0,
        'last_attempt' => $now,
        'backoff_seconds' => 0
    ];
    // Explicitly remove HTTP error tracking on success
    unset($backoffData[$key]['last_http_code']);
    unset($backoffData[$key]['last_error_time']);
    
    if (isset($fp) && $fp !== false) {
        if (@flock($fp, LOCK_EX)) {
            // Write with lock held
            @ftruncate($fp, 0);
            @rewind($fp);
            @fwrite($fp, json_encode($backoffData, JSON_PRETTY_PRINT));
            @fflush($fp);
            @flock($fp, LOCK_UN);
            @fclose($fp);
        } else {
            // Lock failed, close and fall back
            @fclose($fp);
            @file_put_contents($backoffFile, json_encode($backoffData, JSON_PRETTY_PRINT), LOCK_EX);
        }
    } else {
        // File handle not available, fall back to file_put_contents with lock
        @file_put_contents($backoffFile, json_encode($backoffData, JSON_PRETTY_PRINT), LOCK_EX);
    }
}

/**
 * Check if weather API should be skipped due to backoff
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param string $sourceType Weather source type: 'primary' or 'metar'
 * @return array {
 *   'skip' => bool,              // True if should skip (circuit open)
 *   'reason' => string,          // Reason for skip ('circuit_open' or '')
 *   'backoff_remaining' => int,  // Seconds remaining in backoff period
 *   'failures' => int            // Number of consecutive failures
 * }
 */
function checkWeatherCircuitBreaker($airportId, $sourceType) {
    $cacheDir = __DIR__ . '/../cache';
    if (!file_exists($cacheDir)) {
        if (!@mkdir($cacheDir, 0755, true)) {
            error_log("Failed to create cache directory: {$cacheDir}");
            return ['skip' => false, 'reason' => '', 'backoff_remaining' => 0, 'failures' => 0];
        }
    }
    $backoffFile = $cacheDir . '/backoff.json';
    $key = $airportId . '_weather_' . $sourceType;
    return checkCircuitBreakerBase($key, $backoffFile);
}

/**
 * Record a weather API failure and update backoff state
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param string $sourceType Weather source type: 'primary' or 'metar'
 * @param string $severity 'transient' or 'permanent' (default: 'transient')
 * @param int|null $httpCode HTTP status code (4xx/5xx) if available, null otherwise
 * @return void
 */
function recordWeatherFailure($airportId, $sourceType, $severity = 'transient', $httpCode = null) {
    $cacheDir = __DIR__ . '/../cache';
    if (!file_exists($cacheDir)) {
        if (!@mkdir($cacheDir, 0755, true)) {
            error_log("Failed to create cache directory: {$cacheDir}");
            return;
        }
    }
    $backoffFile = $cacheDir . '/backoff.json';
    $key = $airportId . '_weather_' . $sourceType;
    recordCircuitBreakerFailureBase($key, $backoffFile, $severity, $httpCode);
}

/**
 * Record a weather API success and reset backoff state
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param string $sourceType Weather source type: 'primary' or 'metar'
 * @return void
 */
function recordWeatherSuccess($airportId, $sourceType) {
    $backoffFile = __DIR__ . '/../cache/backoff.json';
    $key = $airportId . '_weather_' . $sourceType;
    recordCircuitBreakerSuccessBase($key, $backoffFile);
}

/**
 * Check if webcam should be skipped due to backoff
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @return array {
 *   'skip' => bool,              // True if should skip (circuit open)
 *   'reason' => string,          // Reason for skip ('circuit_open' or '')
 *   'backoff_remaining' => int,  // Seconds remaining in backoff period
 *   'failures' => int            // Number of consecutive failures
 * }
 */
function checkWebcamCircuitBreaker($airportId, $camIndex) {
    $cacheDir = __DIR__ . '/../cache';
    if (!file_exists($cacheDir)) {
        if (!@mkdir($cacheDir, 0755, true)) {
            error_log("Failed to create cache directory: {$cacheDir}");
            return ['skip' => false, 'reason' => '', 'backoff_remaining' => 0, 'failures' => 0];
        }
    }
    $backoffFile = $cacheDir . '/backoff.json';
    $key = $airportId . '_' . $camIndex;
    return checkCircuitBreakerBase($key, $backoffFile);
}

/**
 * Record a webcam failure and update backoff state
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @param string $severity 'transient' or 'permanent' (default: 'transient')
 * @return void
 */
function recordWebcamFailure($airportId, $camIndex, $severity = 'transient') {
    $cacheDir = __DIR__ . '/../cache';
    if (!file_exists($cacheDir)) {
        if (!@mkdir($cacheDir, 0755, true)) {
            error_log("Failed to create cache directory: {$cacheDir}");
            return;
        }
    }
    $backoffFile = $cacheDir . '/backoff.json';
    $key = $airportId . '_' . $camIndex;
    recordCircuitBreakerFailureBase($key, $backoffFile, $severity);
}

/**
 * Record a webcam success and reset backoff state
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @return void
 */
function recordWebcamSuccess($airportId, $camIndex) {
    $backoffFile = __DIR__ . '/../cache/backoff.json';
    $key = $airportId . '_' . $camIndex;
    recordCircuitBreakerSuccessBase($key, $backoffFile);
}

