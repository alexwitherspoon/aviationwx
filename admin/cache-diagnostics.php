<?php
/**
 * Cache Directory Diagnostics
 * Helps diagnose cache directory permission issues in production
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/cache-paths.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== Cache Directory Diagnostics ===\n\n";

// Get current user info
$currentUser = posix_getpwuid(posix_geteuid());
$currentGroup = posix_getgrgid(posix_getegid());
echo "Current Process User: " . ($currentUser['name'] ?? 'unknown') . " (UID: " . posix_geteuid() . ")\n";
echo "Current Process Group: " . ($currentGroup['name'] ?? 'unknown') . " (GID: " . posix_getegid() . ")\n\n";

// Check cache directory paths using centralized constants
$cacheDir = CACHE_BASE_DIR;
$webcamCacheDir = CACHE_WEBCAMS_DIR;

echo "Cache Directory Paths:\n";
echo "  Base: " . $cacheDir . "\n";
echo "  Webcams: " . $webcamCacheDir . "\n";
echo "  Resolved Base: " . (realpath($cacheDir) ?: 'NOT RESOLVED') . "\n";
echo "  Resolved Webcams: " . (realpath($webcamCacheDir) ?: 'NOT RESOLVED') . "\n\n";

// Check parent directory
$parentDir = dirname($webcamCacheDir);
echo "Parent Directory: " . $parentDir . "\n";
echo "  Exists: " . (is_dir($parentDir) ? 'YES' : 'NO') . "\n";
if (is_dir($parentDir)) {
    $parentPerms = substr(sprintf('%o', fileperms($parentDir)), -4);
    $parentOwner = posix_getpwuid(fileowner($parentDir));
    $parentGroup = posix_getgrgid(filegroup($parentDir));
    echo "  Permissions: " . $parentPerms . "\n";
    echo "  Owner: " . ($parentOwner['name'] ?? 'unknown') . " (UID: " . fileowner($parentDir) . ")\n";
    echo "  Group: " . ($parentGroup['name'] ?? 'unknown') . " (GID: " . filegroup($parentDir) . ")\n";
    echo "  Readable: " . (is_readable($parentDir) ? 'YES' : 'NO') . "\n";
    echo "  Writable: " . (is_writable($parentDir) ? 'YES' : 'NO') . "\n";
    echo "  Executable: " . (is_executable($parentDir) ? 'YES' : 'NO') . "\n";
} else {
    echo "  ⚠️  Parent directory does not exist - this is the problem!\n";
    echo "  Attempting to create parent directory...\n";
    $created = @mkdir($parentDir, 0755, true);
    if ($created) {
        echo "  ✓ Parent directory created successfully\n";
    } else {
        $error = error_get_last();
        echo "  ✗ Failed to create parent directory\n";
        echo "  Error: " . ($error['message'] ?? 'unknown') . "\n";
        echo "  Error file: " . ($error['file'] ?? 'unknown') . "\n";
        echo "  Error line: " . ($error['line'] ?? 'unknown') . "\n";
    }
}
echo "\n";

// Check webcam cache directory
echo "Webcam Cache Directory:\n";
echo "  Exists: " . (is_dir($webcamCacheDir) ? 'YES' : 'NO') . "\n";
if (is_dir($webcamCacheDir)) {
    $webcamPerms = substr(sprintf('%o', fileperms($webcamCacheDir)), -4);
    $webcamOwner = posix_getpwuid(fileowner($webcamCacheDir));
    $webcamGroup = posix_getgrgid(filegroup($webcamCacheDir));
    echo "  Permissions: " . $webcamPerms . "\n";
    echo "  Owner: " . ($webcamOwner['name'] ?? 'unknown') . " (UID: " . fileowner($webcamCacheDir) . ")\n";
    echo "  Group: " . ($webcamGroup['name'] ?? 'unknown') . " (GID: " . filegroup($webcamCacheDir) . ")\n";
    echo "  Readable: " . (is_readable($webcamCacheDir) ? 'YES' : 'NO') . "\n";
    echo "  Writable: " . (is_writable($webcamCacheDir) ? 'YES' : 'NO') . "\n";
    echo "  Executable: " . (is_executable($webcamCacheDir) ? 'YES' : 'NO') . "\n";
} else {
    echo "  ⚠️  Webcam cache directory does not exist\n";
    echo "  Attempting to create webcam cache directory...\n";
    
    // First ensure parent exists
    if (!is_dir($parentDir)) {
        echo "    Creating parent directory first...\n";
        $parentCreated = @mkdir($parentDir, 0755, true);
        if (!$parentCreated) {
            $error = error_get_last();
            echo "    ✗ Failed to create parent: " . ($error['message'] ?? 'unknown') . "\n";
        } else {
            echo "    ✓ Parent directory created\n";
        }
    }
    
    $created = @mkdir($webcamCacheDir, 0755, true);
    if ($created) {
        echo "  ✓ Webcam cache directory created successfully\n";
        echo "  New permissions: " . substr(sprintf('%o', fileperms($webcamCacheDir)), -4) . "\n";
    } else {
        $error = error_get_last();
        echo "  ✗ Failed to create webcam cache directory\n";
        echo "  Error: " . ($error['message'] ?? 'unknown') . "\n";
        echo "  Error file: " . ($error['file'] ?? 'unknown') . "\n";
        echo "  Error line: " . ($error['line'] ?? 'unknown') . "\n";
        
        // Additional diagnostics
        if (is_dir($parentDir)) {
            echo "  Parent directory exists but creation failed - permission issue?\n";
            echo "  Parent writable: " . (is_writable($parentDir) ? 'YES' : 'NO') . "\n";
        }
    }
}
echo "\n";

// Check /tmp directory (if cache is in /tmp)
if (strpos($cacheDir, '/tmp') === 0) {
    echo "/tmp Directory Info:\n";
    echo "  Exists: " . (is_dir('/tmp') ? 'YES' : 'NO') . "\n";
    if (is_dir('/tmp')) {
        $tmpPerms = substr(sprintf('%o', fileperms('/tmp')), -4);
        echo "  Permissions: " . $tmpPerms . "\n";
        echo "  Writable: " . (is_writable('/tmp') ? 'YES' : 'NO') . "\n";
    }
    echo "\n";
}

// Test file creation
echo "File Creation Test:\n";
$testFile = $webcamCacheDir . '/.test_write_' . time();
if (is_dir($webcamCacheDir) && is_writable($webcamCacheDir)) {
    $testWrite = @file_put_contents($testFile, 'test');
    if ($testWrite !== false) {
        echo "  ✓ Successfully created test file\n";
        @unlink($testFile);
        echo "  ✓ Successfully deleted test file\n";
    } else {
        $error = error_get_last();
        echo "  ✗ Failed to create test file\n";
        echo "  Error: " . ($error['message'] ?? 'unknown') . "\n";
    }
} else {
    echo "  ⚠️  Cannot test - directory doesn't exist or isn't writable\n";
}
echo "\n";

// Check Docker volume mount (if applicable)
if (file_exists('/.dockerenv')) {
    echo "Docker Environment Detected:\n";
    echo "  Running in Docker container\n";
    
    // Check if cache is a volume mount
    $cacheStat = @stat($cacheDir);
    if ($cacheStat) {
        echo "  Cache directory device: " . $cacheStat['dev'] . "\n";
        $rootStat = @stat('/');
        if ($rootStat) {
            echo "  Root device: " . $rootStat['dev'] . "\n";
            if ($cacheStat['dev'] !== $rootStat['dev']) {
                echo "  ✓ Cache appears to be a volume mount\n";
            } else {
                echo "  Cache is on same filesystem as root\n";
            }
        }
    }
    echo "\n";
}

// Summary
echo "=== Summary ===\n";
$issues = [];
if (!is_dir($parentDir)) {
    $issues[] = "Parent cache directory does not exist";
} elseif (!is_writable($parentDir)) {
    $issues[] = "Parent cache directory is not writable";
}
if (!is_dir($webcamCacheDir)) {
    $issues[] = "Webcam cache directory does not exist";
} elseif (!is_writable($webcamCacheDir)) {
    $issues[] = "Webcam cache directory is not writable";
}

if (empty($issues)) {
    echo "✓ All cache directories are accessible and writable\n";
} else {
    echo "✗ Issues found:\n";
    foreach ($issues as $issue) {
        echo "  - " . $issue . "\n";
    }
    echo "\nRecommendations:\n";
    if (!is_dir($parentDir) || !is_writable($parentDir)) {
        echo "  1. Ensure the cache directory is created in the deployment script\n";
        echo "  2. Check that the directory permissions allow the web server user to write\n";
        echo "  3. If using Docker, verify the volume mount has correct permissions\n";
    }
}

