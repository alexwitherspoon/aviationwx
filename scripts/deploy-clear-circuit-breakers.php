#!/usr/bin/env php
<?php
/**
 * Clear Circuit Breakers During Deployment
 * 
 * Clears all circuit breaker state files (backoff.json) during deployment.
 * This ensures that circuit breaker state is reset when code changes that
 * affect circuit breaker logic are deployed.
 * 
 * This script is called automatically during CI/CD deployment, but can also
 * be run manually if needed.
 * 
 * Usage:
 *   php deploy-clear-circuit-breakers.php [--dry-run] [--verbose]
 * 
 * Options:
 *   --dry-run   Show what would be deleted without actually deleting
 *   --verbose   Show detailed information about each file
 * 
 * Files cleared:
 *   - cache/backoff.json (main circuit breaker state)
 *   - api/cache/backoff.json (if exists)
 *   - scripts/cache/backoff.json (if exists)
 */

// Ensure we're running from CLI
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script must be run from the command line');
}

// Parse command line options
$options = getopt('', ['dry-run', 'verbose', 'help']);
$dryRun = isset($options['dry-run']);
$verbose = isset($options['verbose']);

if (isset($options['help'])) {
    echo <<<HELP
Clear Circuit Breakers During Deployment

Usage: php deploy-clear-circuit-breakers.php [OPTIONS]

Options:
  --dry-run   Show what would be deleted without actually deleting
  --verbose   Show detailed information about each file
  --help      Show this help message

This script clears all circuit breaker state files (backoff.json) to ensure
fresh state after code deployments that may change circuit breaker logic.

Files cleared:
  - cache/backoff.json (main circuit breaker state)
  - api/cache/backoff.json (if exists)
  - scripts/cache/backoff.json (if exists)

HELP;
    exit(0);
}

// Get project root (parent of scripts directory)
$projectRoot = dirname(__DIR__);

// Define all possible backoff.json file locations
// Inside container: /var/www/html/cache/backoff.json (which may be symlinked to /tmp/aviationwx-cache)
// The script works from inside the container, so we use the container paths
$backoffFiles = [
    $projectRoot . '/cache/backoff.json',  // Main circuit breaker state
    $projectRoot . '/api/cache/backoff.json',  // API cache directory (if exists)
    $projectRoot . '/scripts/cache/backoff.json',  // Scripts cache directory (if exists)
];

$cleared = 0;
$skipped = 0;
$errors = 0;

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "Clearing Circuit Breaker State Files\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

if ($dryRun) {
    echo "DRY RUN MODE - No files will be deleted\n\n";
}

foreach ($backoffFiles as $file) {
    if (!file_exists($file)) {
        if ($verbose) {
            echo "  ⏭️  Skipping (not found): {$file}\n";
        }
        $skipped++;
        continue;
    }
    
    // Get file info before deletion
    $fileSize = filesize($file);
    $fileTime = filemtime($file);
    $fileAge = time() - $fileTime;
    
    if ($verbose) {
        $ageStr = $fileAge < 60 ? "{$fileAge}s" : ($fileAge < 3600 ? round($fileAge / 60) . "m" : round($fileAge / 3600) . "h");
        echo "  📄 File: {$file}\n";
        echo "     Size: " . formatBytes($fileSize) . "\n";
        echo "     Age: {$ageStr} ago\n";
        
        // Try to read and show entry count
        $content = @file_get_contents($file);
        if ($content !== false) {
            $data = @json_decode($content, true);
            if (is_array($data)) {
                $entryCount = count($data);
                echo "     Entries: {$entryCount}\n";
            }
        }
    }
    
    if ($dryRun) {
        echo "  🗑️  Would delete: {$file}\n";
        $cleared++;
    } else {
        if (@unlink($file)) {
            echo "  ✓ Deleted: {$file}\n";
            $cleared++;
        } else {
            echo "  ❌ Failed to delete: {$file}\n";
            $errors++;
        }
    }
    
    if ($verbose) {
        echo "\n";
    }
}

echo "\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "Summary\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "  Cleared: {$cleared}\n";
echo "  Skipped: {$skipped}\n";
if ($errors > 0) {
    echo "  Errors: {$errors}\n";
}
echo "\n";

if ($errors > 0) {
    exit(1);
}

/**
 * Format bytes to human-readable string
 * 
 * @param int $bytes Number of bytes
 * @return string Formatted string (e.g., "1.5 KB")
 */
function formatBytes(int $bytes): string {
    if ($bytes < 1024) {
        return $bytes . ' B';
    } elseif ($bytes < 1048576) {
        return number_format($bytes / 1024, 1) . ' KB';
    } else {
        return number_format($bytes / 1048576, 1) . ' MB';
    }
}

