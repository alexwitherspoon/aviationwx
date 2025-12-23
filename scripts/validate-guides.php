<?php
/**
 * Validate Guides
 * Checks that all guides markdown files are valid and can be parsed
 */

$guidesDir = __DIR__ . '/../guides';
$errors = [];
$warnings = [];

// Check if guides directory exists
if (!is_dir($guidesDir)) {
    echo "❌ Guides directory not found: $guidesDir\n";
    exit(1);
}

// Check for README.md or readme.md
$readmeFiles = ['README.md', 'readme.md'];
$hasReadme = false;
foreach ($readmeFiles as $readme) {
    $readmePath = $guidesDir . '/' . $readme;
    if (file_exists($readmePath)) {
        $hasReadme = true;
        break;
    }
}

if (!$hasReadme) {
    $errors[] = "No README.md or readme.md found in guides directory";
}

// Load Parsedown if available
$parsedown = null;
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    $parsedown = new Parsedown();
} else {
    $warnings[] = "Composer autoloader not found - skipping markdown parsing validation";
}

// Get all markdown files
$files = scandir($guidesDir);
$guides = [];
$invalidFiles = [];

foreach ($files as $file) {
    // Skip . and .. and README files
    if ($file === '.' || $file === '..' || 
        in_array(strtolower($file), ['readme.md', 'readme.md'])) {
        continue;
    }
    
    // Check if it's a markdown file
    if (!preg_match('/\.md$/i', $file)) {
        continue;
    }
    
    // Check naming pattern: ##-guide-name.md
    if (!preg_match('/^(\d+)-(.+)\.md$/i', $file, $matches)) {
        $invalidFiles[] = $file;
        continue;
    }
    
    $guides[] = [
        'file' => $file,
        'number' => intval($matches[1]),
        'slug' => preg_replace('/\.md$/i', '', $file)
    ];
    
    // Validate file is readable
    $filePath = $guidesDir . '/' . $file;
    if (!is_readable($filePath)) {
        $errors[] = "Guide file is not readable: $file";
        continue;
    }
    
    // Try to parse markdown if Parsedown is available
    if ($parsedown !== null) {
        $content = file_get_contents($filePath);
        if ($content === false) {
            $errors[] = "Failed to read guide file: $file";
            continue;
        }
        
        // Check for title (first H1)
        if (!preg_match('/^#\s+(.+)$/m', $content, $titleMatch)) {
            $warnings[] = "Guide $file does not have a title (first H1)";
        }
        
        // Try to parse
        try {
            $html = $parsedown->text($content);
            if (empty(trim($html))) {
                $warnings[] = "Guide $file appears to be empty after parsing";
            }
        } catch (Exception $e) {
            $errors[] = "Failed to parse markdown in $file: " . $e->getMessage();
        }
    }
}

// Check for invalid file names
if (!empty($invalidFiles)) {
    foreach ($invalidFiles as $file) {
        $errors[] = "Invalid guide filename: $file (must match pattern: ##-guide-name.md)";
    }
}

// Check for duplicate numbers
$numbers = array_column($guides, 'number');
$duplicates = array_diff_assoc($numbers, array_unique($numbers));
if (!empty($duplicates)) {
    foreach ($duplicates as $index => $number) {
        $errors[] = "Duplicate guide number $number in: " . $guides[$index]['file'];
    }
}

// Output results
if (!empty($errors)) {
    echo "❌ Validation errors found:\n";
    foreach ($errors as $error) {
        echo "  - $error\n";
    }
    echo "\n";
}

if (!empty($warnings)) {
    echo "⚠️  Warnings:\n";
    foreach ($warnings as $warning) {
        echo "  - $warning\n";
    }
    echo "\n";
}

if (empty($errors)) {
    echo "✓ Guides validation passed\n";
    echo "  - Found " . count($guides) . " guide(s)\n";
    if ($hasReadme) {
        echo "  - README found\n";
    }
    exit(0);
} else {
    exit(1);
}

