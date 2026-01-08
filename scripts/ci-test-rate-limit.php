#!/usr/bin/env php
<?php
/**
 * CI Test Script - Rate Limiting Functions  
 * Tests that match GitHub Actions CI
 */

require_once __DIR__ . '/../lib/rate-limit.php';

echo "Testing rate limiting functions...\n";

if (!function_exists('checkRateLimit')) {
    echo "FAIL: checkRateLimit function not found\n";
    exit(1);
}

if (!function_exists('getRateLimitRemaining')) {
    echo "FAIL: getRateLimitRemaining function not found\n";
    exit(1);
}

if (!function_exists('checkRateLimitFileBased')) {
    echo "FAIL: checkRateLimitFileBased function not found (file-based fallback)\n";
    exit(1);
}

echo "✓ Rate limiting functions exist\n";
exit(0);
