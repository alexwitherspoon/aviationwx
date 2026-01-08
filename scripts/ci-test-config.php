#!/usr/bin/env php
<?php
/**
 * CI Test Script - Config Utilities
 * Tests that match GitHub Actions CI
 */

require_once __DIR__ . '/../lib/config.php';

echo "Testing airport ID validation...\n";

$tests = [
    ['kspb', true],
    ['KSPB', true], // Should be lowercased
    ['kx12', true],
    ['invalid!!', false],
    ['', false],
    [str_repeat('a', 51), false], // Too long (51 chars)
    ['ab', false], // Too short
    ['private-strip-1', true], // Valid with hyphens
    ['helipad-downtown', true], // Valid longer ID
    ['-airport', false], // Leading hyphen
    ['airport-', false], // Trailing hyphen
    ['air--port', false], // Consecutive hyphens
];

foreach ($tests as [$id, $expected]) {
    $result = validateAirportId($id);
    if ($result !== $expected) {
        echo "FAIL: validateAirportId(\"{$id}\") returned " . ($result ? 'true' : 'false') . ", expected " . ($expected ? 'true' : 'false') . PHP_EOL;
        exit(1);
    }
}

echo "✓ Airport ID validation tests passed\n";
exit(0);
