<?php
/**
 * Quick test script to verify Parsedown is working
 * Run: php dev/test-guides.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

echo "Testing Parsedown installation...\n\n";

// Test 1: Check if Parsedown class exists
if (class_exists('Parsedown')) {
    echo "✅ Parsedown class found\n";
} else {
    echo "❌ Parsedown class NOT found\n";
    exit(1);
}

// Test 2: Try to instantiate Parsedown
try {
    $parsedown = new Parsedown();
    echo "✅ Parsedown instantiated successfully\n";
} catch (Exception $e) {
    echo "❌ Failed to instantiate Parsedown: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 3: Try to parse a simple markdown
try {
    $markdown = "# Test Guide\n\nThis is a **test** guide.";
    $html = $parsedown->text($markdown);
    if (strpos($html, '<h1>') !== false && strpos($html, '<strong>') !== false) {
        echo "✅ Markdown parsing works\n";
        echo "\nSample output:\n";
        echo $html . "\n";
    } else {
        echo "❌ Markdown parsing failed\n";
        exit(1);
    }
} catch (Exception $e) {
    echo "❌ Markdown parsing error: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 4: Check if guides/README.md exists
$readmePath = __DIR__ . '/../guides/README.md';
if (file_exists($readmePath)) {
    echo "✅ guides/README.md exists\n";
    $content = file_get_contents($readmePath);
    if ($content !== false) {
        echo "✅ guides/README.md is readable (" . strlen($content) . " bytes)\n";
    } else {
        echo "❌ guides/README.md exists but cannot be read\n";
        exit(1);
    }
} else {
    echo "❌ guides/README.md does NOT exist\n";
    exit(1);
}

// Test 5: Try to parse the actual README
try {
    $readmeContent = file_get_contents($readmePath);
    $parsedReadme = $parsedown->text($readmeContent);
    if (strlen($parsedReadme) > 0) {
        echo "✅ Successfully parsed guides/README.md\n";
    } else {
        echo "❌ Parsed README is empty\n";
        exit(1);
    }
} catch (Exception $e) {
    echo "❌ Error parsing README: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n✅ All tests passed! Guides should work now.\n";
echo "\nTo test in browser:\n";
echo "1. Start server: php -S localhost:8080 dev/router.php\n";
echo "2. Visit: http://guides.localhost:8080\n";
echo "   OR: http://localhost:8080 (then manually test guides.php)\n";

