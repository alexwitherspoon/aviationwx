<?php
/**
 * Version API Endpoint
 * 
 * Returns current deployment version information for client-side version checking.
 * Used by the dead man's switch to detect stuck/stale client versions.
 * 
 * Response includes:
 * - hash: Short git hash of current deployment
 * - hash_full: Full git hash
 * - timestamp: Unix timestamp of deployment
 * - deploy_date: ISO 8601 formatted deployment date
 * - force_cleanup: Emergency flag to force all clients to cleanup
 * - max_no_update_days: Days before dead man's switch triggers
 */

// Prevent caching - clients should always get fresh version info
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: application/json');

// CORS headers for API access
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Get request method (default to GET for CLI)
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Handle preflight requests
if ($requestMethod === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Only allow GET requests
if ($requestMethod !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$versionFile = __DIR__ . '/../../config/version.json';

// Check if version file exists
if (!file_exists($versionFile)) {
    // Fallback: generate version info from git if available
    $hash = 'unknown';
    $hashFull = 'unknown';
    
    // Try to get git hash
    $gitHash = @exec('git rev-parse --short HEAD 2>/dev/null');
    $gitHashFull = @exec('git rev-parse HEAD 2>/dev/null');
    
    if ($gitHash) {
        $hash = trim($gitHash);
    }
    if ($gitHashFull) {
        $hashFull = trim($gitHashFull);
    }
    
    $response = [
        'hash' => $hash,
        'hash_full' => $hashFull,
        'timestamp' => time(),
        'deploy_date' => gmdate('Y-m-d\TH:i:s\Z'),
        'force_cleanup' => false,
        'max_no_update_days' => 7,
        '_fallback' => true  // Indicates this was generated on-the-fly
    ];
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    exit;
}

// Read and serve the version file
$versionData = file_get_contents($versionFile);

if ($versionData === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to read version file']);
    exit;
}

// Validate JSON
$decoded = json_decode($versionData, true);
if ($decoded === null) {
    http_response_code(500);
    echo json_encode(['error' => 'Invalid version file format']);
    exit;
}

// Return the version data
echo $versionData;

