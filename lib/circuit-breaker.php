<?php
/**
 * Circuit Breaker Utility
 * Shared circuit breaker logic for weather and webcam sources
 * Implements exponential backoff with severity-based scaling
 */

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/cache-paths.php';

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
 *   'failures' => int,           // Number of consecutive failures
 *   'last_failure_reason' => string|null  // Reason for last failure (if available)
 * }
 */
function checkCircuitBreakerBase($key, $backoffFile) {
    $now = time();
    
    if (!file_exists($backoffFile)) {
        return ['skip' => false, 'reason' => '', 'backoff_remaining' => 0, 'failures' => 0, 'last_failure_reason' => null];
    }
    
    // Clear stat cache to ensure we read the latest file contents
    clearstatcache(true, $backoffFile);
    $backoffData = @json_decode(@file_get_contents($backoffFile), true) ?: [];
    
    if (!isset($backoffData[$key])) {
        return ['skip' => false, 'reason' => '', 'backoff_remaining' => 0, 'failures' => 0, 'last_failure_reason' => null];
    }
    
    $state = $backoffData[$key];
    $failures = (int)($state['failures'] ?? 0);
    $nextAllowed = (int)($state['next_allowed_time'] ?? 0);
    
    // Only open circuit if we've reached the failure threshold
    // This prevents opening on a single failure
    if ($failures < CIRCUIT_BREAKER_FAILURE_THRESHOLD) {
        // Not enough failures yet - allow request
        return ['skip' => false, 'reason' => '', 'backoff_remaining' => 0, 'failures' => $failures, 'last_failure_reason' => null];
    }
    
    // If nextAllowed <= now, backoff has expired
    if ($nextAllowed > $now) {
        $remaining = $nextAllowed - $now;
        $failureReason = $state['last_failure_reason'] ?? null;
        return [
            'skip' => true,
            'reason' => 'circuit_open',
            'backoff_remaining' => $remaining,
            'failures' => $failures,
            'last_failure_reason' => $failureReason
        ];
    }
    
    // Backoff expired - entry will be cleaned up lazily on next write
    return ['skip' => false, 'reason' => '', 'backoff_remaining' => 0, 'failures' => 0, 'last_failure_reason' => null];
}

/**
 * Base failure recording with file locking
 * 
 * Records a failure and updates exponential backoff state.
 * Uses file locking to prevent race conditions in concurrent environments.
 * Implements error-type-specific backoff strategies:
 *   - 429 (Rate Limit): Short backoff (2s base), minimal growth
 *   - Transient errors (5xx, network): Moderate backoff (10s base), exponential growth, capped at 10 min
 *   - Permanent errors (4xx auth/config): Long backoff (2x multiplier), capped at 30 min
 * 
 * Circuit breaker only opens after CIRCUIT_BREAKER_FAILURE_THRESHOLD consecutive failures.
 * 
 * @param string $key Unique identifier
 * @param string $backoffFile Path to backoff.json file
 * @param string $severity 'transient' or 'permanent' (default: 'transient')
 *   - transient: Normal backoff scaling
 *   - permanent: 2x multiplier (for auth errors, config issues, etc.)
 * @param int|null $httpCode HTTP status code (4xx/5xx) if available, null otherwise
 * @param string|null $failureReason Human-readable reason for the failure (e.g., 'EXIF validation failed', 'HTTP 503')
 * @return void
 */
function recordCircuitBreakerFailureBase($key, $backoffFile, $severity = 'transient', $httpCode = null, $failureReason = null) {
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
    
    // Store failure reason (always store if provided, even if HTTP code is not 4xx/5xx)
    if ($failureReason !== null && $failureReason !== '') {
        $state['last_failure_reason'] = $failureReason;
    } elseif ($httpCode !== null && $httpCode >= 400 && $httpCode < 600) {
        // If no explicit reason provided but we have an HTTP error code, generate a reason
        $state['last_failure_reason'] = "HTTP {$httpCode}";
    } elseif (!isset($state['last_failure_reason'])) {
        // Preserve existing reason if no new one provided
        $state['last_failure_reason'] = 'unknown';
    }
    
    $failures = $state['failures'];
    
    // Error-type-specific backoff strategies
    if ($httpCode === 429) {
        // Rate limit errors: short backoff, minimal growth
        $base = BACKOFF_BASE_RATE_LIMIT;
        $backoffSeconds = min(10, $base + ($failures - 1)); // 2s, 3s, 4s... capped at 10s
    } elseif ($severity === 'permanent') {
        // Permanent errors: 2x multiplier, 30 min cap
        $base = max(BACKOFF_BASE_SECONDS, pow(2, min($failures - 1, BACKOFF_MAX_FAILURES)) * BACKOFF_BASE_SECONDS);
        $backoffSeconds = min(BACKOFF_MAX_PERMANENT, (int)round($base * 2.0));
    } else {
        // Transient errors: 10s base, exponential growth, 10 min cap
        $base = max(BACKOFF_BASE_TRANSIENT, pow(2, min($failures - 1, BACKOFF_MAX_FAILURES)) * BACKOFF_BASE_TRANSIENT);
        $backoffSeconds = min(BACKOFF_MAX_TRANSIENT, (int)round($base));
    }
    
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
    
    // Store failure reason (always store if provided, even if HTTP code is not 4xx/5xx)
    if ($failureReason !== null && $failureReason !== '') {
        $state['last_failure_reason'] = $failureReason;
    } elseif ($httpCode !== null && $httpCode >= 400 && $httpCode < 600) {
        // If no explicit reason provided but we have an HTTP error code, generate a reason
        $state['last_failure_reason'] = "HTTP {$httpCode}";
    } elseif (!isset($state['last_failure_reason'])) {
        // Preserve existing reason if no new one provided
        $state['last_failure_reason'] = 'unknown';
    }
    
    // Error-type-specific backoff strategies
    $failures = $state['failures'];
    
    if ($httpCode === 429) {
        // Rate limit errors: short backoff, minimal growth
        $base = BACKOFF_BASE_RATE_LIMIT;
        $backoffSeconds = min(10, $base + ($failures - 1)); // 2s, 3s, 4s... capped at 10s
    } elseif ($severity === 'permanent') {
        // Permanent errors: 2x multiplier, 30 min cap
        $base = max(BACKOFF_BASE_SECONDS, pow(2, min($failures - 1, BACKOFF_MAX_FAILURES)) * BACKOFF_BASE_SECONDS);
        $backoffSeconds = min(BACKOFF_MAX_PERMANENT, (int)round($base * 2.0));
    } else {
        // Transient errors: 10s base, exponential growth, 10 min cap
        $base = max(BACKOFF_BASE_TRANSIENT, pow(2, min($failures - 1, BACKOFF_MAX_FAILURES)) * BACKOFF_BASE_TRANSIENT);
        $backoffSeconds = min(BACKOFF_MAX_TRANSIENT, (int)round($base));
    }
    
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
    
    // Reset on success - clear HTTP code and failure reason tracking
    $backoffData[$key] = [
        'failures' => 0,
        'next_allowed_time' => 0,
        'last_attempt' => $now,
        'backoff_seconds' => 0
    ];
    // Explicitly remove HTTP error and failure reason tracking on success
    unset($backoffData[$key]['last_http_code']);
    unset($backoffData[$key]['last_error_time']);
    unset($backoffData[$key]['last_failure_reason']);
    
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
 *   'failures' => int,           // Number of consecutive failures
 *   'last_failure_reason' => string|null  // Reason for last failure (if available)
 * }
 */
function checkWeatherCircuitBreaker($airportId, $sourceType) {
    if (!ensureCacheDir(CACHE_BASE_DIR)) {
        error_log("Failed to create cache directory: " . CACHE_BASE_DIR);
        return ['skip' => false, 'reason' => '', 'backoff_remaining' => 0, 'failures' => 0, 'last_failure_reason' => null];
    }
    $key = $airportId . '_weather_' . $sourceType;
    return checkCircuitBreakerBase($key, CACHE_BACKOFF_FILE);
}

/**
 * Record a weather API failure and update backoff state
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param string $sourceType Weather source type: 'primary' or 'metar'
 * @param string $severity 'transient' or 'permanent' (default: 'transient')
 * @param int|null $httpCode HTTP status code (4xx/5xx) if available, null otherwise
 * @param string|null $failureReason Human-readable reason for the failure (e.g., 'API rate limit exceeded', 'HTTP 503')
 * @return void
 */
function recordWeatherFailure($airportId, $sourceType, $severity = 'transient', $httpCode = null, $failureReason = null) {
    if (!ensureCacheDir(CACHE_BASE_DIR)) {
        error_log("Failed to create cache directory: " . CACHE_BASE_DIR);
        return;
    }
    $key = $airportId . '_weather_' . $sourceType;
    recordCircuitBreakerFailureBase($key, CACHE_BACKOFF_FILE, $severity, $httpCode, $failureReason);
}

/**
 * Record a weather API success and reset backoff state
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param string $sourceType Weather source type: 'primary' or 'metar'
 * @return void
 */
function recordWeatherSuccess($airportId, $sourceType) {
    $key = $airportId . '_weather_' . $sourceType;
    recordCircuitBreakerSuccessBase($key, CACHE_BACKOFF_FILE);
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
 *   'failures' => int,           // Number of consecutive failures
 *   'last_failure_reason' => string|null  // Reason for last failure (if available)
 * }
 */
function checkWebcamCircuitBreaker($airportId, $camIndex) {
    if (!ensureCacheDir(CACHE_BASE_DIR)) {
        error_log("Failed to create cache directory: " . CACHE_BASE_DIR);
        return ['skip' => false, 'reason' => '', 'backoff_remaining' => 0, 'failures' => 0, 'last_failure_reason' => null];
    }
    $key = $airportId . '_' . $camIndex;
    return checkCircuitBreakerBase($key, CACHE_BACKOFF_FILE);
}

/**
 * Record a webcam failure and update backoff state
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @param string $severity 'transient' or 'permanent' (default: 'transient')
 * @param int|null $httpCode HTTP status code (4xx/5xx) if available, null otherwise
 * @param string|null $failureReason Human-readable reason for the failure (e.g., 'EXIF validation failed', 'HTTP 503')
 * @return void
 */
function recordWebcamFailure($airportId, $camIndex, $severity = 'transient', $httpCode = null, $failureReason = null) {
    if (!ensureCacheDir(CACHE_BASE_DIR)) {
        error_log("Failed to create cache directory: " . CACHE_BASE_DIR);
        return;
    }
    $key = $airportId . '_' . $camIndex;
    recordCircuitBreakerFailureBase($key, CACHE_BACKOFF_FILE, $severity, $httpCode, $failureReason);
}

/**
 * Record a webcam success and reset backoff state
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @return void
 */
function recordWebcamSuccess($airportId, $camIndex) {
    $key = $airportId . '_' . $camIndex;
    recordCircuitBreakerSuccessBase($key, CACHE_BACKOFF_FILE);
}

/** Circuit breaker key for NOAA geomag API (single global endpoint) */
const GEOMAG_CIRCUIT_BREAKER_KEY = 'geomag_noaa';

/**
 * Check if geomag API should be skipped due to circuit breaker
 *
 * @return array {
 *   'skip' => bool,              // True if should skip (circuit open)
 *   'reason' => string,          // Reason for skip ('circuit_open' or '')
 *   'backoff_remaining' => int,  // Seconds remaining in backoff period
 *   'failures' => int,           // Number of consecutive failures
 *   'last_failure_reason' => string|null  // Reason for last failure (if available)
 * }
 */
function checkGeomagCircuitBreaker(): array {
    if (!ensureCacheDir(CACHE_BASE_DIR)) {
        return ['skip' => false, 'reason' => '', 'backoff_remaining' => 0, 'failures' => 0, 'last_failure_reason' => null];
    }
    return checkCircuitBreakerBase(GEOMAG_CIRCUIT_BREAKER_KEY, CACHE_BACKOFF_FILE);
}

/**
 * Record geomag API failure and update backoff state
 *
 * @param string $severity 'transient' or 'permanent' (default: 'transient')
 * @param int|null $httpCode HTTP status code (4xx/5xx) if available, null otherwise
 * @param string|null $failureReason Human-readable reason for the failure
 * @return void
 */
function recordGeomagFailure(string $severity = 'transient', ?int $httpCode = null, ?string $failureReason = null): void {
    if (!ensureCacheDir(CACHE_BASE_DIR)) {
        return;
    }
    recordCircuitBreakerFailureBase(GEOMAG_CIRCUIT_BREAKER_KEY, CACHE_BACKOFF_FILE, $severity, $httpCode, $failureReason);
}

/**
 * Record geomag API success and reset backoff state
 *
 * @return void
 */
function recordGeomagSuccess(): void {
    recordCircuitBreakerSuccessBase(GEOMAG_CIRCUIT_BREAKER_KEY, CACHE_BACKOFF_FILE);
}

