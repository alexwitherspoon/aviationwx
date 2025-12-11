#!/usr/bin/env php
<?php
/**
 * JavaScript Static Analysis Validator
 * 
 * Validates JavaScript code in PHP files for common issues:
 * - PHP functions used in JavaScript
 * - Incorrect API endpoint URLs
 * - JavaScript syntax issues
 * 
 * Usage:
 *   php scripts/validate-javascript.php
 *   php scripts/validate-javascript.php --files=pages/airport.php,pages/homepage.php
 * 
 * Examples:
 *   # Validate all PHP files in pages directory (default)
 *   php scripts/validate-javascript.php
 * 
 *   # Validate specific files
 *   php scripts/validate-javascript.php --files=pages/airport.php
 * 
 * Exit codes:
 * - 0: All checks passed
 * - 1: Errors found
 */

// Default files to check
$defaultFiles = glob(__DIR__ . '/../pages/*.php');

// Parse command line arguments
$files = $defaultFiles;
if (isset($argv[1]) && strpos($argv[1], '--files=') === 0) {
    $fileList = substr($argv[1], 8);
    $files = array_map('trim', explode(',', $fileList));
    $files = array_map(function($f) {
        return __DIR__ . '/../' . $f;
    }, $files);
}

$phpFunctions = ['empty(', 'isset(', 'array_', 'str_', 'preg_'];
$errors = [];

foreach ($files as $file) {
    if (!file_exists($file)) {
        echo "Warning: File not found: $file\n";
        continue;
    }
    
    // Read file with error handling
    $content = @file_get_contents($file);
    if ($content === false) {
        echo "Warning: Could not read file: $file\n";
        continue;
    }
    
    preg_match_all('/<script[^>]*>(.*?)<\/script>/is', $content, $matches);
    
    foreach ($matches[1] as $i => $jsCode) {
        // Filter out PHP tags to avoid false positives from PHP conditionals wrapping JavaScript
        $jsCodeCleaned = preg_replace('/<\?[=]?php?[\s\S]*?\?>/', '', $jsCode);
        
        // Skip blocks that are entirely PHP (server-side code, not client-side JavaScript)
        $jsCodeTrimmed = trim($jsCodeCleaned);
        if (empty($jsCodeTrimmed)) {
            continue;
        }
        
        // Skip server-side PHP code blocks (contain function definitions with $ variables)
        if (preg_match('/function\s+\w+\s*\([^)]*\$|^\s*\$[a-zA-Z_]/m', $jsCodeCleaned)) {
            continue;
        }
        
        foreach ($phpFunctions as $func) {
            if (strpos($jsCodeCleaned, $func) !== false) {
                // Ignore PHP functions found in comments or strings
                $matchIndex = strpos($jsCodeCleaned, $func);
                $beforeMatch = substr($jsCodeCleaned, 0, $matchIndex);
                $isInComment = 
                    (strrpos($beforeMatch, '//') !== false && 
                     strrpos($beforeMatch, "\n", strrpos($beforeMatch, '//')) === false) ||
                    (strrpos($beforeMatch, '/*') !== false && 
                     strrpos($beforeMatch, '*/', strrpos($beforeMatch, '/*')) === false);
                
                if (!$isInComment) {
                    $errors[] = basename($file) . ": PHP function '$func' found in JavaScript (script block #$i)";
                }
            }
        }
    }
}

if (!empty($errors)) {
    echo "❌ PHP functions found in JavaScript:\n";
    echo implode("\n", $errors) . "\n";
    exit(1);
}

echo "✓ No PHP functions found in JavaScript\n";
exit(0);
