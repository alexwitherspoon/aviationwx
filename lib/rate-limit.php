<?php
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/config.php';
/**
 * Simple Rate Limiting Utility
 * IP-based rate limiting for API endpoints
 */

/**
 * Check if request should be rate limited
 * 
 * Implements IP-based rate limiting using APCu (preferred) or file-based fallback.
 * Extracts client IP from X-Forwarded-For header or REMOTE_ADDR.
 * 
 * @param string $key Unique key for this rate limit (e.g., 'weather_api', 'webcam_api')
 * @param int $maxRequests Maximum requests allowed in the time window (default: RATE_LIMIT_WEATHER_MAX)
 * @param int $windowSeconds Time window in seconds (default: RATE_LIMIT_WEATHER_WINDOW)
 * @return bool True if allowed, false if rate limited
 */
function checkRateLimit($key, $maxRequests = RATE_LIMIT_WEATHER_MAX, $windowSeconds = RATE_LIMIT_WEATHER_WINDOW) {
    // Get client IP (respect proxy headers)
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    // Handle comma-separated IPs (X-Forwarded-For can contain multiple IPs)
    $ip = trim(explode(',', $ip)[0]);
    
    // Use APCu if available for rate limiting
    if (function_exists('apcu_fetch') && function_exists('apcu_store')) {
        $rateLimitKey = 'rate_limit_' . $key . '_' . md5($ip);
        $data = apcu_fetch($rateLimitKey);
        
        if ($data === false) {
            // First request in this window
            apcu_store($rateLimitKey, ['count' => 1, 'reset' => time() + $windowSeconds], $windowSeconds + RATE_LIMIT_APCU_TTL_BUFFER);
            return true;
        }
        
        // Check if window expired
        if (time() >= ($data['reset'] ?? 0)) {
            // Reset window
            apcu_store($rateLimitKey, ['count' => 1, 'reset' => time() + $windowSeconds], $windowSeconds + RATE_LIMIT_APCU_TTL_BUFFER);
            return true;
        }
        
        if (($data['count'] ?? 0) >= $maxRequests) {
            // Rate limit exceeded
            aviationwx_log('warning', 'rate limit exceeded', [
                'key' => $key,
                'ip' => $ip,
                'limit' => $maxRequests,
                'reset' => $data['reset'] ?? null
            ], 'app');
            return false;
        }
        
        // Increment counter
        $data['count'] = ($data['count'] ?? 0) + 1;
        apcu_store($rateLimitKey, $data, $windowSeconds + RATE_LIMIT_APCU_TTL_BUFFER);
        return true;
    }
    
    // Fallback: File-based rate limiting if APCu not available
    $isProd = isProduction();
    if ($isProd) {
        aviationwx_log('warning', 'APCu not available, using file-based rate limiting fallback', [
            'key' => $key
        ], 'app');
    }
    return checkRateLimitFileBased($key, $maxRequests, $windowSeconds, $ip);
}

/**
 * File-based rate limiting fallback
 * 
 * Uses file locking to ensure atomic read-modify-write operations.
 * Without proper locking, concurrent requests could both read the same count,
 * increment independently, and write back, causing rate limit bypass.
 * 
 * @param string $key Rate limit key
 * @param int $maxRequests Maximum requests allowed
 * @param int $windowSeconds Time window in seconds
 * @param string $ip Client IP address
 * @return bool True if allowed, false if rate limited
 */
function checkRateLimitFileBased(string $key, int $maxRequests, int $windowSeconds, string $ip): bool {
    $cacheDir = __DIR__ . '/../cache';
    if (!is_dir($cacheDir)) {
        if (!@mkdir($cacheDir, 0755, true)) {
            aviationwx_log('error', 'rate limit cache directory creation failed', ['dir' => $cacheDir], 'app');
            return true; // Fail open - allow request if we can't create directory
        }
    }
    
    $rateLimitFile = $cacheDir . '/rate_limit_' . md5($key . '_' . $ip) . '.json';
    $now = time();
    
    // Use file locking for atomic read-modify-write
    // Critical: Without locking, race conditions allow rate limit bypass
    $fp = @fopen($rateLimitFile, 'c+');
    if ($fp === false) {
        // Fallback to non-locked write if we can't open file
        aviationwx_log('warning', 'rate limit file open failed, using fallback', ['file' => $rateLimitFile], 'app');
        return checkRateLimitFileBasedFallback($key, $maxRequests, $windowSeconds, $ip, $rateLimitFile, $now);
    }
    
    // Acquire exclusive lock (blocking) to ensure atomicity
    // This prevents concurrent requests from both reading the same count
    if (!@flock($fp, LOCK_EX)) {
        @fclose($fp);
        aviationwx_log('warning', 'rate limit file lock failed, using fallback', ['file' => $rateLimitFile], 'app');
        return checkRateLimitFileBasedFallback($key, $maxRequests, $windowSeconds, $ip, $rateLimitFile, $now);
    }
    
    // Read current data while lock is held
    $data = [];
    $fileSize = @filesize($rateLimitFile);
    if ($fileSize > 0) {
        $content = @stream_get_contents($fp);
        if ($content !== false && $content !== '') {
            $data = @json_decode($content, true);
            if (!is_array($data)) {
                $data = [];
            }
        }
    }
    
    // Normalize values from JSON to ensure proper type comparisons
    // JSON may store numbers as strings, so we need to cast to int
    $dataReset = isset($data['reset']) && is_numeric($data['reset']) ? (int)$data['reset'] : null;
    $dataCount = isset($data['count']) && is_numeric($data['count']) ? (int)$data['count'] : 0;
    
    // Check if window expired
    if ($dataReset !== null && $now >= $dataReset) {
        $data = ['count' => 0, 'reset' => $now + $windowSeconds];
    } elseif ($dataReset === null) {
        $data = ['count' => 0, 'reset' => $now + $windowSeconds];
    }
    
    // Check if limit exceeded (use normalized count)
    $currentCount = isset($data['count']) && is_numeric($data['count']) ? (int)$data['count'] : $dataCount;
    if ($currentCount >= $maxRequests) {
        @flock($fp, LOCK_UN);
        @fclose($fp);
        aviationwx_log('warning', 'rate limit exceeded (file-based)', [
            'key' => $key,
            'ip' => $ip,
            'limit' => $maxRequests,
            'reset' => $data['reset'] ?? null
        ], 'app');
        return false;
    }
    
    // Increment counter (ensure we're working with int)
    $data['count'] = $currentCount + 1;
    if (!isset($data['reset'])) {
        $data['reset'] = $now + $windowSeconds;
    }
    
    // Write modified data back while lock is still held
    // Truncate first to ensure clean write (prevents partial overwrites)
    @ftruncate($fp, 0);
    @rewind($fp);
    @fwrite($fp, json_encode($data));
    @fflush($fp);
    
    // Release lock and close
    @flock($fp, LOCK_UN);
    @fclose($fp);
    
    return true;
}

/**
 * Fallback for file-based rate limiting when file handle operations fail
 * 
 * Uses file_put_contents with LOCK_EX as last resort when file handle operations fail.
 * This is less reliable than checkRateLimitFileBased() due to potential race conditions,
 * but provides a fallback when file locking is unavailable.
 * 
 * @param string $key Rate limit key
 * @param int $maxRequests Maximum requests allowed
 * @param int $windowSeconds Time window in seconds
 * @param string $ip Client IP address
 * @param string $rateLimitFile Path to rate limit file
 * @param int $now Current timestamp
 * @return bool True if allowed, false if rate limited
 */
function checkRateLimitFileBasedFallback($key, $maxRequests, $windowSeconds, $ip, $rateLimitFile, $now) {
    $data = [];
    if (file_exists($rateLimitFile)) {
        $content = @file_get_contents($rateLimitFile);
        if ($content !== false) {
            $data = @json_decode($content, true) ?: [];
        }
    }
    
    // Normalize values from JSON to ensure proper type comparisons
    $dataReset = isset($data['reset']) && is_numeric($data['reset']) ? (int)$data['reset'] : null;
    $dataCount = isset($data['count']) && is_numeric($data['count']) ? (int)$data['count'] : 0;
    
    if ($dataReset !== null && $now >= $dataReset) {
        $data = ['count' => 0, 'reset' => $now + $windowSeconds];
    } elseif ($dataReset === null) {
        $data = ['count' => 0, 'reset' => $now + $windowSeconds];
    }
    
    // Check if limit exceeded (use normalized count)
    $currentCount = isset($data['count']) && is_numeric($data['count']) ? (int)$data['count'] : $dataCount;
    if ($currentCount >= $maxRequests) {
        aviationwx_log('warning', 'rate limit exceeded (file-based fallback)', [
            'key' => $key,
            'ip' => $ip,
            'limit' => $maxRequests
        ], 'app');
        return false;
    }
    
    // Increment counter (ensure we're working with int)
    $data['count'] = $currentCount + 1;
    if (!isset($data['reset'])) {
        $data['reset'] = $now + $windowSeconds;
    }
    
    @file_put_contents($rateLimitFile, json_encode($data), LOCK_EX);
    return true;
}

/**
 * Get remaining rate limit for this key
 * 
 * Returns the number of requests remaining in the current time window
 * and when the window resets. Used for X-RateLimit-* headers.
 * 
 * @param string $key Rate limit key
 * @param int $maxRequests Maximum requests allowed
 * @param int $windowSeconds Time window in seconds
 * @return array Returns array with 'remaining' (int) and 'reset' (int timestamp) keys
 */
function getRateLimitRemaining(string $key, int $maxRequests = RATE_LIMIT_WEATHER_MAX, int $windowSeconds = RATE_LIMIT_WEATHER_WINDOW): array {
    // Extract IP address (shared logic)
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $ip = trim(explode(',', $ip)[0]);
    
    if (!function_exists('apcu_fetch')) {
        // Fallback to file-based check
        $cacheDir = __DIR__ . '/../cache';
        $rateLimitFile = $cacheDir . '/rate_limit_' . md5($key . '_' . $ip) . '.json';
        $now = time();
        
        if (file_exists($rateLimitFile)) {
            $content = @file_get_contents($rateLimitFile);
            if ($content !== false) {
                $data = @json_decode($content, true);
                if (is_array($data)) {
                    // Normalize reset time to int for proper comparison
                    $dataReset = isset($data['reset']) && is_numeric($data['reset']) ? (int)$data['reset'] : null;
                    if ($dataReset !== null && $now < $dataReset) {
                        $currentCount = isset($data['count']) && is_numeric($data['count']) ? (int)$data['count'] : 0;
                        return [
                            'remaining' => (int)max(0, $maxRequests - $currentCount),
                            'reset' => $dataReset
                        ];
                    }
                }
            }
        }
        
        // No rate limit data or window expired
        return [
            'remaining' => $maxRequests,
            'reset' => $now + $windowSeconds
        ];
    }
    
    $rateLimitKey = 'rate_limit_' . $key . '_' . md5($ip);
    $data = apcu_fetch($rateLimitKey);
    
    if ($data === false) {
        // No rate limit data exists, so all requests are available
        return [
            'remaining' => $maxRequests,
            'reset' => time() + $windowSeconds
        ];
    }
    
    // Extract count and reset time from the data array
    // Normalize to int to prevent type coercion issues
    $currentCount = is_array($data) 
        ? (isset($data['count']) && is_numeric($data['count']) ? (int)$data['count'] : 0)
        : (is_numeric($data) ? (int)$data : 0);
    $now = time();
    
    // Check if window expired (normalize reset time to int for comparison)
    $dataReset = is_array($data) && isset($data['reset']) && is_numeric($data['reset']) 
        ? (int)$data['reset'] 
        : null;
    if ($dataReset !== null && $now >= $dataReset) {
        // Window expired, all requests are available
        return [
            'remaining' => $maxRequests,
            'reset' => $now + $windowSeconds
        ];
    }
    
    $resetTime = $dataReset !== null ? $dataReset : ($now + $windowSeconds);
    
    return [
        'remaining' => (int)max(0, $maxRequests - $currentCount),
        'reset' => $resetTime
    ];
}
