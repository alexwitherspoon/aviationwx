<?php
/**
 * Standalone test for guides.php functionality
 * Simulates what guides.php does without going through index.php
 */

// Set up environment
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['HTTP_HOST'] = 'guides.localhost';

// Load dependencies
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/seo.php';

echo "Testing guides.php functionality...\n\n";

// Test 1: Check Parsedown
if (!class_exists('Parsedown')) {
    die("❌ Parsedown class not found\n");
}
echo "✅ Parsedown class found\n";

// Test 2: Check guides directory
$guidesDir = __DIR__ . '/../guides';
if (!is_dir($guidesDir)) {
    die("❌ Guides directory not found: $guidesDir\n");
}
echo "✅ Guides directory exists\n";

// Test 3: Check README.md
$readmeFiles = ['README.md', 'readme.md'];
$readmePath = null;
foreach ($readmeFiles as $readme) {
    $path = $guidesDir . '/' . $readme;
    if (file_exists($path)) {
        $readmePath = $path;
        break;
    }
}
if (!$readmePath) {
    die("❌ README.md not found in guides directory\n");
}
echo "✅ README.md found: $readmePath\n";

// Test 4: Read and parse markdown
$markdownContent = file_get_contents($readmePath);
if ($markdownContent === false) {
    die("❌ Failed to read README.md\n");
}
echo "✅ README.md read successfully (" . strlen($markdownContent) . " bytes)\n";

// Test 5: Parse with Parsedown
try {
    $parsedown = new Parsedown();
    $htmlContent = $parsedown->text($markdownContent);
    
    if (empty($htmlContent)) {
        die("❌ Parsed HTML is empty\n");
    }
    
    echo "✅ Markdown parsed successfully (" . strlen($htmlContent) . " bytes)\n";
    
    // Check for expected content
    if (strpos($htmlContent, '<h1>') !== false || strpos($htmlContent, '<h2>') !== false) {
        echo "✅ HTML contains headings\n";
    }
    
    if (strpos($htmlContent, 'AviationWX') !== false) {
        echo "✅ HTML contains expected content\n";
    }
    
    // Show first 500 chars of parsed HTML
    echo "\n--- First 500 chars of parsed HTML ---\n";
    echo substr($htmlContent, 0, 500) . "...\n";
    
} catch (Exception $e) {
    die("❌ Error parsing markdown: " . $e->getMessage() . "\n");
}

// Test 6: Check guide files
$allGuides = [];
$files = scandir($guidesDir);
foreach ($files as $file) {
    if (preg_match('/^(\d+)-(.+)\.md$/i', $file, $matches)) {
        $allGuides[] = [
            'file' => $file,
            'slug' => preg_replace('/\.md$/i', '', $file),
            'number' => intval($matches[1])
        ];
    }
}
usort($allGuides, function($a, $b) {
    return $a['number'] <=> $b['number'];
});

echo "\n✅ Found " . count($allGuides) . " guide files:\n";
foreach ($allGuides as $guide) {
    echo "   - " . $guide['slug'] . "\n";
}

echo "\n✅ All tests passed! Guides functionality is working.\n";
echo "\nTo test in browser with proper subdomain:\n";
echo "1. Add to /etc/hosts: 127.0.0.1 guides.aviationwx.local\n";
echo "2. Visit: http://guides.aviationwx.local:8080\n";
echo "\nOr test directly:\n";
echo "Visit: http://localhost:8080/?test=guides (if you modify router)\n";

