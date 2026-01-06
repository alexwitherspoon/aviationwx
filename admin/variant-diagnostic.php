<?php
/**
 * Variant Coverage Diagnostic
 * 
 * Diagnoses why variant coverage shows 63% or other unexpected values.
 * Checks calculation logic vs actual file availability.
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/webcam-metadata.php';
require_once __DIR__ . '/../lib/webcam-format-generation.php';

header('Content-Type: text/plain');

$config = loadConfig();
if ($config === null) {
    die("ERROR: Could not load config\n");
}

$enabledFormats = getEnabledWebcamFormats();
echo "=== Variant Coverage Diagnostic ===\n\n";
echo "Enabled formats: " . implode(', ', $enabledFormats) . "\n";
echo "WebP enabled: " . (isWebpGenerationEnabled() ? 'yes' : 'no') . "\n\n";

$issues = [];
$totalCameras = 0;
$camerasWithIssues = 0;

foreach ($config['airports'] as $airportId => $airport) {
    if (!isset($airport['webcams']) || !is_array($airport['webcams'])) {
        continue;
    }
    
    foreach ($airport['webcams'] as $idx => $cam) {
        $totalCameras++;
        $camName = $cam['name'] ?? "Camera {$idx}";
        
        // Get variant heights
        $variantHeights = getVariantHeights($airportId, $idx);
        $formats = getEnabledWebcamFormats();
        
        // Calculate expected total (same as status.php)
        // Original is only stored in source format (not all formats), so count it once
        // Height variants are generated in all enabled formats
        $totalVariants = count($variantHeights) * count($formats) + 1;
        
        // Get latest timestamp
        $latestTimestamp = getLatestImageTimestamp($airportId, $idx);
        
        if ($latestTimestamp <= 0) {
            continue; // Skip cameras with no images
        }
        
        // Get actual available variants
        $availableVariantsData = getAvailableVariants($airportId, $idx, $latestTimestamp);
        $availableVariants = 0;
        
        // Count original (only one original file exists, in source format)
        $originalAvailable = isset($availableVariantsData['original']) && !empty($availableVariantsData['original']);
        if ($originalAvailable) {
            $availableVariants++;
        }
        
        // Count height-based variants
        foreach ($variantHeights as $height) {
            foreach ($formats as $format) {
                $available = isset($availableVariantsData[$height]) && in_array($format, $availableVariantsData[$height]);
                if ($available) {
                    $availableVariants++;
                }
            }
        }
        
        $variantCoverage = $totalVariants > 0 ? ($availableVariants / $totalVariants) : 0;
        $coveragePercent = round($variantCoverage * 100, 1);
        
        // Check for issues (coverage < 90%)
        if ($variantCoverage < 0.9) {
            $camerasWithIssues++;
            
            echo "--- {$airportId} / {$camName} (idx: {$idx}) ---\n";
            echo "Variant heights: " . implode(', ', $variantHeights) . "\n";
            echo "Expected total variants: {$totalVariants}\n";
            echo "Available variants: {$availableVariants}\n";
            echo "Coverage: {$coveragePercent}%\n";
            echo "Latest timestamp: {$latestTimestamp}\n\n";
            
            // Detailed breakdown
            echo "Expected variants breakdown:\n";
            echo "  Original: 1 (stored in source format only)\n";
            echo "  Height variants: " . count($variantHeights) . " heights × " . count($formats) . " formats = " . (count($variantHeights) * count($formats)) . "\n\n";
            
            echo "Available variants breakdown:\n";
            if (isset($availableVariantsData['original'])) {
                echo "  Original: " . implode(', ', $availableVariantsData['original']) . "\n";
            } else {
                echo "  Original: NONE\n";
            }
            foreach ($variantHeights as $height) {
                if (isset($availableVariantsData[$height])) {
                    echo "  {$height}px: " . implode(', ', $availableVariantsData[$height]) . "\n";
                } else {
                    echo "  {$height}px: NONE\n";
                }
            }
            echo "\n";
            
            // Check which specific variants are missing
            echo "Missing variants:\n";
            $originalAvailable = isset($availableVariantsData['original']) && !empty($availableVariantsData['original']);
            if (!$originalAvailable) {
                echo "  original (source format)\n";
            }
            foreach ($variantHeights as $height) {
                foreach ($formats as $format) {
                    $available = isset($availableVariantsData[$height]) && in_array($format, $availableVariantsData[$height]);
                    if (!$available) {
                        echo "  {$height}px.{$format}\n";
                    }
                }
            }
            echo "\n";
            
            // Check actual files
            $cacheDir = getWebcamCameraDir($airportId, $idx);
            echo "Cache directory: {$cacheDir}\n";
            if (is_dir($cacheDir)) {
                $pattern = $cacheDir . '/' . $latestTimestamp . '_*.{jpg,jpeg,webp}';
                $files = glob($pattern, GLOB_BRACE);
                if (empty($files)) {
                    $files = array_merge(
                        glob($cacheDir . '/' . $latestTimestamp . '_*.jpg'),
                        glob($cacheDir . '/' . $latestTimestamp . '_*.jpeg'),
                        glob($cacheDir . '/' . $latestTimestamp . '_*.webp')
                    );
                }
                echo "Files found for timestamp {$latestTimestamp}: " . count($files) . "\n";
                foreach ($files as $file) {
                    if (!is_link($file)) {
                        echo "  " . basename($file) . "\n";
                    }
                }
            }
            echo "\n" . str_repeat('=', 60) . "\n\n";
        }
    }
}

echo "\n=== Summary ===\n";
echo "Total cameras checked: {$totalCameras}\n";
echo "Cameras with coverage < 90%: {$camerasWithIssues}\n";

