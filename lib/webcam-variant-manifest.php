<?php
/**
 * Webcam Variant Manifest
 * 
 * Tracks what variants were actually generated for each image.
 * This allows the status page to accurately report variant coverage based on
 * what was actually generated (dynamic based on source image dimensions),
 * rather than assuming global config defaults.
 * 
 * Uses APCu cache with fallback to JSON files for persistence.
 */

require_once __DIR__ . '/config.php';

/**
 * Store variant manifest for an image
 * 
 * Called by the processing pipeline after generating variants.
 * Stores the manifest in APCu with a long TTL and also writes to disk.
 * 
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index (0-based)
 * @param int $timestamp Image timestamp
 * @param array $variantResult Result from generateVariantsFromOriginal()
 * @return bool True on success, false on failure
 */
function storeVariantManifest(string $airportId, int $camIndex, int $timestamp, array $variantResult): bool {
    // Build manifest structure
    $manifest = [
        'timestamp' => $timestamp,
        'generated_at' => time(),
        'source_dimensions' => [
            'width' => $variantResult['metadata']['width'] ?? null,
            'height' => $variantResult['metadata']['height'] ?? null,
        ],
        'original' => [
            'format' => $variantResult['metadata']['format'] ?? 'jpg',
            'exists' => !empty($variantResult['original'])
        ],
        'variants' => []
    ];
    
    // Count variants by height × format
    $totalVariants = 1; // Original counts as 1
    foreach ($variantResult['variants'] as $height => $formats) {
        foreach ($formats as $format => $path) {
            if (!isset($manifest['variants'][$height])) {
                $manifest['variants'][$height] = [];
            }
            $manifest['variants'][$height][] = $format;
            $totalVariants++;
        }
    }
    
    $manifest['total_files'] = $totalVariants;
    
    // Store in APCu with 24 hour TTL (updated on each new image)
    $cacheKey = "webcam_variant_manifest:{$airportId}:{$camIndex}:{$timestamp}";
    if (function_exists('apcu_store')) {
        apcu_store($cacheKey, $manifest, 86400);
    }
    
    // Also store "latest" pointer for quick lookup
    $latestKey = "webcam_variant_manifest_latest:{$airportId}:{$camIndex}";
    if (function_exists('apcu_store')) {
        apcu_store($latestKey, [
            'timestamp' => $timestamp,
            'manifest' => $manifest
        ], 86400);
    }
    
    // Write to disk as JSON for persistence across restarts
    $cacheDir = getWebcamCameraDir($airportId, $camIndex);
    if (!is_dir($cacheDir)) {
        return false;
    }
    
    $manifestFile = $cacheDir . '/' . $timestamp . '_manifest.json';
    $jsonData = json_encode($manifest, JSON_PRETTY_PRINT);
    if ($jsonData === false) {
        return false;
    }
    
    return @file_put_contents($manifestFile, $jsonData) !== false;
}

/**
 * Get variant manifest for an image
 * 
 * First checks APCu cache, then falls back to disk JSON file.
 * 
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index (0-based)
 * @param int $timestamp Image timestamp
 * @return array|null Manifest array or null if not found
 */
function getVariantManifest(string $airportId, int $camIndex, int $timestamp): ?array {
    // Try APCu first
    $cacheKey = "webcam_variant_manifest:{$airportId}:{$camIndex}:{$timestamp}";
    if (function_exists('apcu_fetch')) {
        $manifest = apcu_fetch($cacheKey, $success);
        if ($success) {
            return $manifest;
        }
    }
    
    // Fall back to disk
    $cacheDir = getWebcamCameraDir($airportId, $camIndex);
    if (!is_dir($cacheDir)) {
        return null;
    }
    
    $manifestFile = $cacheDir . '/' . $timestamp . '_manifest.json';
    if (!file_exists($manifestFile)) {
        return null;
    }
    
    $jsonData = @file_get_contents($manifestFile);
    if ($jsonData === false) {
        return null;
    }
    
    $manifest = json_decode($jsonData, true);
    if ($manifest === null) {
        return null;
    }
    
    // Store in APCu for next time
    if (function_exists('apcu_store')) {
        apcu_store($cacheKey, $manifest, 86400);
    }
    
    return $manifest;
}

/**
 * Get latest variant manifest for a camera
 * 
 * Returns the manifest for the most recent image.
 * First checks APCu "latest" pointer, then scans disk.
 * 
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index (0-based)
 * @return array|null Manifest array or null if not found
 */
function getLatestVariantManifest(string $airportId, int $camIndex): ?array {
    // Try APCu "latest" pointer first
    $latestKey = "webcam_variant_manifest_latest:{$airportId}:{$camIndex}";
    if (function_exists('apcu_fetch')) {
        $latest = apcu_fetch($latestKey, $success);
        if ($success && is_array($latest) && isset($latest['manifest'])) {
            return $latest['manifest'];
        }
    }
    
    // Fall back to finding latest timestamp from disk
    require_once __DIR__ . '/webcam-metadata.php';
    $latestTimestamp = getLatestImageTimestamp($airportId, $camIndex);
    if ($latestTimestamp <= 0) {
        return null;
    }
    
    return getVariantManifest($airportId, $camIndex, $latestTimestamp);
}

/**
 * Calculate variant coverage percentage
 * 
 * Returns the actual variant coverage based on what was generated,
 * not based on global config assumptions.
 * 
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index (0-based)
 * @param int|null $timestamp Specific timestamp, or null for latest
 * @return float Coverage percentage (0.0 to 1.0), or 0.0 if no manifest found
 */
function getVariantCoverage(string $airportId, int $camIndex, ?int $timestamp = null): float {
    if ($timestamp === null) {
        $manifest = getLatestVariantManifest($airportId, $camIndex);
    } else {
        $manifest = getVariantManifest($airportId, $camIndex, $timestamp);
    }
    
    if ($manifest === null) {
        return 0.0;
    }
    
    $expected = $manifest['total_files'] ?? 0;
    if ($expected <= 0) {
        return 0.0;
    }
    
    // Count actual files on disk
    $cacheDir = getWebcamCameraDir($airportId, $camIndex);
    if (!is_dir($cacheDir)) {
        return 0.0;
    }
    
    $actualTimestamp = $timestamp ?? $manifest['timestamp'];
    $available = 0;
    
    // Check original
    if ($manifest['original']['exists'] ?? false) {
        $originalFormat = $manifest['original']['format'] ?? 'jpg';
        require_once __DIR__ . '/webcam-metadata.php';
        $originalPath = getWebcamOriginalTimestampedPath($airportId, $camIndex, $actualTimestamp, $originalFormat);
        if (file_exists($originalPath)) {
            $available++;
        }
    }
    
    // Check variants
    foreach ($manifest['variants'] as $height => $formats) {
        foreach ($formats as $format) {
            $variantPath = $cacheDir . '/' . $actualTimestamp . '_' . $height . '.' . $format;
            if (file_exists($variantPath)) {
                $available++;
            }
        }
    }
    
    return $expected > 0 ? ($available / $expected) : 0.0;
}

/**
 * Get webcam camera directory
 * 
 * Helper function to avoid circular dependency.
 * 
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index (0-based)
 * @return string Cache directory path
 */
function getWebcamCameraDir(string $airportId, int $camIndex): string {
    $baseDir = __DIR__ . '/../cache/webcams';
    return $baseDir . '/' . strtolower($airportId) . '/' . $camIndex;
}
