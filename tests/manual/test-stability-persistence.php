<?php
/**
 * Test script for stability metrics persistence
 * 
 * Tests that metrics can be saved to disk and loaded back.
 * Run: php tests/manual/test-stability-persistence.php
 */

require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/webcam-image-metrics.php';
// Note: Stability metrics functions are now in webcam-image-metrics.php

echo "Testing Stability Metrics Persistence\n";
echo "======================================\n\n";

$airportId = 'test';
$camIndex = 0;

// Test 1: Save metrics to disk
echo "Test 1: Saving metrics to disk...\n";
$testMetrics = [
    'stability_times' => [1.0, 1.2, 1.5, 2.0, 1.8],
    'accepted' => 5,
    'rejected' => 1,
    'last_updated' => time()
];

$saved = saveStabilityMetricsToDisk($airportId, $camIndex, $testMetrics);
if ($saved) {
    echo "✅ Metrics saved successfully\n";
} else {
    echo "❌ Failed to save metrics\n";
    exit(1);
}

// Test 2: Load metrics from disk
echo "\nTest 2: Loading metrics from disk...\n";
$loaded = loadStabilityMetricsFromDisk($airportId, $camIndex);
if ($loaded) {
    echo "✅ Metrics loaded successfully\n";
    echo "   Samples: " . count($loaded['stability_times']) . "\n";
    echo "   Accepted: " . $loaded['accepted'] . "\n";
    echo "   Rejected: " . $loaded['rejected'] . "\n";
} else {
    echo "❌ Failed to load metrics\n";
    exit(1);
}

// Test 3: Verify data integrity
echo "\nTest 3: Verifying data integrity...\n";
$match = true;
if (count($loaded['stability_times']) !== count($testMetrics['stability_times'])) {
    echo "❌ Stability times count mismatch\n";
    $match = false;
}
if ($loaded['accepted'] !== $testMetrics['accepted']) {
    echo "❌ Accepted count mismatch\n";
    $match = false;
}
if ($loaded['rejected'] !== $testMetrics['rejected']) {
    echo "❌ Rejected count mismatch\n";
    $match = false;
}

if ($match) {
    echo "✅ Data integrity verified\n";
} else {
    exit(1);
}

// Test 4: Clean up
echo "\nTest 4: Cleaning up test files...\n";
$file = __DIR__ . '/../../cache/stability_metrics/' . $airportId . '_' . $camIndex . '.json';
if (unlink($file)) {
    echo "✅ Test file deleted\n";
} else {
    echo "⚠️  Failed to delete test file (may need manual cleanup)\n";
}

echo "\n======================================\n";
echo "All tests passed! ✅\n";
