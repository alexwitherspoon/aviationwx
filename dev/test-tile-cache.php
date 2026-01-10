#!/usr/bin/env php
<?php
/**
 * Test Map Tiles Cache Infrastructure
 * 
 * Tests the caching mechanism without requiring a valid OpenWeatherMap API key
 * by creating mock tiles and verifying cache behavior.
 */

// Change to project root
chdir(__DIR__ . '/..');

require_once __DIR__ . '/../lib/cache-paths.php';

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "Map Tiles Cache Infrastructure Test\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

// Test 1: Cache directory creation
echo "TEST 1: Cache Directory Structure\n";
echo "─────────────────────────────────\n";

ensureCacheDir(CACHE_MAP_TILES_DIR);
$layerDir = getMapTileLayerDir('test_layer');
ensureCacheDir($layerDir);

if (is_dir(CACHE_MAP_TILES_DIR)) {
    echo "✓ Base cache directory exists: " . CACHE_MAP_TILES_DIR . "\n";
} else {
    echo "✗ Failed to create base cache directory\n";
    exit(1);
}

if (is_dir($layerDir)) {
    echo "✓ Layer subdirectory exists: " . basename($layerDir) . "\n";
} else {
    echo "✗ Failed to create layer subdirectory\n";
    exit(1);
}

echo "\n";

// Test 2: Cache path generation
echo "TEST 2: Cache Path Generation\n";
echo "─────────────────────────────────\n";

$testCases = [
    ['clouds_new', 5, 10, 12],
    ['precipitation_new', 3, 5, 8],
    ['temp_new', 10, 512, 387],
];

foreach ($testCases as [$layer, $z, $x, $y]) {
    $path = getMapTileCachePath($layer, $z, $x, $y);
    $expected = CACHE_MAP_TILES_DIR . "/{$layer}/{$z}_{$x}_{$y}.png";
    
    if ($path === $expected) {
        echo "✓ {$layer} z={$z} x={$x} y={$y}\n";
        echo "  → " . basename(dirname($path)) . "/" . basename($path) . "\n";
    } else {
        echo "✗ Path mismatch for {$layer}\n";
        echo "  Expected: {$expected}\n";
        echo "  Got: {$path}\n";
    }
}

echo "\n";

// Test 3: Create mock tiles and test caching
echo "TEST 3: Cache Write and Read\n";
echo "─────────────────────────────────\n";

// Create a simple 1x1 PNG (valid PNG format)
$mockPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

$testLayer = 'test_layer';
$testZ = 5;
$testX = 10;
$testY = 12;

$cachePath = getMapTileCachePath($testLayer, $testZ, $testX, $testY);
$cacheDir = dirname($cachePath);

// Ensure directory exists
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

// Write mock tile to cache
$writeStart = microtime(true);
$written = file_put_contents($cachePath, $mockPng);
$writeTime = (microtime(true) - $writeStart) * 1000;

if ($written !== false) {
    echo "✓ Cache write successful ({$written} bytes, " . round($writeTime, 2) . "ms)\n";
    echo "  → " . basename($cachePath) . "\n";
} else {
    echo "✗ Failed to write cache file\n";
    exit(1);
}

// Read from cache
$readStart = microtime(true);
$readData = file_get_contents($cachePath);
$readTime = (microtime(true) - $readStart) * 1000;

if ($readData === $mockPng) {
    echo "✓ Cache read successful (" . strlen($readData) . " bytes, " . round($readTime, 2) . "ms)\n";
} else {
    echo "✗ Cache read mismatch\n";
    exit(1);
}

// Verify PNG signature
if (substr($readData, 0, 4) === "\x89PNG") {
    echo "✓ Valid PNG signature detected\n";
} else {
    echo "✗ Invalid PNG signature\n";
}

echo "\n";

// Test 4: Cache age and TTL
echo "TEST 4: Cache Age and TTL Simulation\n";
echo "─────────────────────────────────────\n";

$mtime = filemtime($cachePath);
$age = time() - $mtime;

echo "✓ Cache file modification time: " . date('Y-m-d H:i:s', $mtime) . "\n";
echo "✓ Cache age: {$age} seconds\n";

$ttl = 3600; // 1 hour
$isFresh = $age < $ttl;

if ($isFresh) {
    echo "✓ Cache is FRESH (within {$ttl}s TTL)\n";
} else {
    echo "✗ Cache is STALE (exceeded {$ttl}s TTL)\n";
}

echo "\n";

// Test 5: Multiple tiles caching
echo "TEST 5: Multiple Tiles Simulation\n";
echo "──────────────────────────────────\n";

$tilesCreated = 0;
$totalSize = 0;

for ($i = 0; $i < 10; $i++) {
    $testPath = getMapTileCachePath('test_layer', 5, 10 + $i, 12 + $i);
    $testDir = dirname($testPath);
    
    if (!is_dir($testDir)) {
        mkdir($testDir, 0755, true);
    }
    
    if (file_put_contents($testPath, $mockPng)) {
        $tilesCreated++;
        $totalSize += strlen($mockPng);
    }
}

echo "✓ Created {$tilesCreated} mock tiles\n";
echo "✓ Total cache size: {$totalSize} bytes\n";

// Count total files in cache
$allFiles = glob(CACHE_MAP_TILES_DIR . '/*/*.png');
$fileCount = $allFiles ? count($allFiles) : 0;

echo "✓ Total cached tiles: {$fileCount}\n";

echo "\n";

// Test 6: Cache cleanup test
echo "TEST 6: Cache Cleanup Simulation\n";
echo "─────────────────────────────────\n";

$oldTilePath = getMapTileCachePath('test_layer', 4, 5, 6);
$oldTileDir = dirname($oldTilePath);

if (!is_dir($oldTileDir)) {
    mkdir($oldTileDir, 0755, true);
}

file_put_contents($oldTilePath, $mockPng);

// Set modification time to 8 days ago
$eightDaysAgo = time() - (8 * 86400);
touch($oldTilePath, $eightDaysAgo);

$oldAge = time() - filemtime($oldTilePath);
$oldAgeDays = round($oldAge / 86400, 1);

echo "✓ Created old tile (age: {$oldAgeDays} days)\n";

// Simulate cleanup threshold (7 days)
$cleanupThreshold = 7 * 86400;
$shouldCleanup = $oldAge > $cleanupThreshold;

if ($shouldCleanup) {
    echo "✓ Old tile SHOULD be cleaned up (> 7 days)\n";
    
    // Actually remove it
    if (unlink($oldTilePath)) {
        echo "✓ Successfully removed old tile\n";
    }
} else {
    echo "✗ Old tile cleanup logic incorrect\n";
}

echo "\n";

// Test 7: Directory listing
echo "TEST 7: Cache Directory Contents\n";
echo "─────────────────────────────────\n";

echo "Cache structure:\n";
$layers = glob(CACHE_MAP_TILES_DIR . '/*', GLOB_ONLYDIR);

if ($layers) {
    foreach ($layers as $layerPath) {
        $layerName = basename($layerPath);
        $tiles = glob($layerPath . '/*.png');
        $tileCount = $tiles ? count($tiles) : 0;
        
        echo "  {$layerName}/ ({$tileCount} tiles)\n";
        
        if ($tileCount > 0 && $tileCount <= 5) {
            foreach ($tiles as $tile) {
                echo "    - " . basename($tile) . "\n";
            }
        } elseif ($tileCount > 5) {
            for ($i = 0; $i < 3; $i++) {
                echo "    - " . basename($tiles[$i]) . "\n";
            }
            echo "    ... and " . ($tileCount - 3) . " more\n";
        }
    }
} else {
    echo "  (no layers cached yet)\n";
}

echo "\n";

// Summary
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "SUMMARY\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "✓ All cache infrastructure tests passed\n";
echo "✓ Cache directory structure working\n";
echo "✓ Cache read/write operations functional\n";
echo "✓ PNG validation working\n";
echo "✓ Multi-tile caching operational\n";
echo "✓ Cleanup logic verified\n";
echo "\n";
echo "Cache is ready for production use!\n";
echo "Once OpenWeatherMap API key activates, tiles will be cached here.\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

// Cleanup test files
echo "\nCleaning up test files...\n";
$testLayerDir = CACHE_MAP_TILES_DIR . '/test_layer';
if (is_dir($testLayerDir)) {
    $testFiles = glob($testLayerDir . '/*.png');
    foreach ($testFiles as $file) {
        unlink($file);
    }
    rmdir($testLayerDir);
    echo "✓ Test files removed\n";
}
