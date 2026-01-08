#!/usr/bin/env php
<?php
/**
 * CI Test Script - Circuit Breaker Functions
 * Tests that match GitHub Actions CI
 */

require_once __DIR__ . '/../lib/circuit-breaker.php';

echo "Testing circuit breaker functions...\n";

$funcs = [
    'checkWeatherCircuitBreaker',
    'recordWeatherFailure',
    'recordWeatherSuccess',
    'checkWebcamCircuitBreaker',
    'recordWebcamFailure',
    'recordWebcamSuccess'
];

foreach ($funcs as $func) {
    if (!function_exists($func)) {
        echo "FAIL: {$func} function not found\n";
        exit(1);
    }
}

echo "✓ Circuit breaker functions exist\n";
exit(0);
