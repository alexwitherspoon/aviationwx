<?php
/**
 * Webcam History API
 * 
 * Returns manifest of available historical frames for a camera,
 * or serves individual frames when timestamp is specified.
 * 
 * Endpoints:
 *   GET /api/webcam-history.php?id={airport}&cam={index}
 *     Returns JSON manifest of available frames
 * 
 *   GET /api/webcam-history.php?id={airport}&cam={index}&ts={timestamp}
 *     Returns the JPEG image for that timestamp
 * 
 * Response (manifest):
 * {
 *   "enabled": true,
 *   "airport": "kspb",
 *   "cam": 0,
 *   "frames": [
 *     { "timestamp": 1735084200, "url": "/api/webcam-history.php?id=kspb&cam=0&ts=1735084200" },
 *     ...
 *   ],
 *   "current_index": 11,
 *   "timezone": "America/Los_Angeles"
 * }
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/webcam-history.php';

// Get parameters
$airportId = isset($_GET['id']) ? strtolower(trim($_GET['id'])) : '';
$camIndex = isset($_GET['cam']) ? intval($_GET['cam']) : 0;
$timestamp = isset($_GET['ts']) ? intval($_GET['ts']) : null;

// Validate airport ID
if (empty($airportId)) {
    header('Content-Type: application/json');
    http_response_code(400);
    echo json_encode(['error' => 'Missing airport id parameter']);
    exit;
}

// Load config and validate airport exists
$config = loadConfig();
if ($config === null || !isset($config['airports'][$airportId])) {
    header('Content-Type: application/json');
    http_response_code(404);
    echo json_encode(['error' => 'Airport not found']);
    exit;
}

$airport = $config['airports'][$airportId];

// Validate camera index exists
if (!isset($airport['webcams']) || !isset($airport['webcams'][$camIndex])) {
    header('Content-Type: application/json');
    http_response_code(404);
    echo json_encode(['error' => 'Camera not found']);
    exit;
}

// If timestamp provided, serve the actual image
if ($timestamp !== null) {
    $historyDir = getWebcamHistoryDir($airportId, $camIndex);
    $imageFile = $historyDir . '/' . $timestamp . '.jpg';
    
    if (!file_exists($imageFile)) {
        header('Content-Type: application/json');
        http_response_code(404);
        echo json_encode(['error' => 'Frame not found']);
        exit;
    }
    
    // Validate file is within expected directory (security check)
    $realPath = realpath($imageFile);
    $realHistoryDir = realpath($historyDir);
    if ($realPath === false || $realHistoryDir === false || strpos($realPath, $realHistoryDir) !== 0) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        exit;
    }
    
    // Serve image with aggressive cache headers (timestamp makes URL immutable)
    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=31536000, immutable');
    header('Content-Length: ' . filesize($imageFile));
    header('X-Content-Type-Options: nosniff');
    
    readfile($imageFile);
    exit;
}

// Return JSON manifest
header('Content-Type: application/json');
header('Cache-Control: no-cache, max-age=0');

// Check if history is enabled for this airport
$historyEnabled = isWebcamHistoryEnabledForAirport($airportId);

if (!$historyEnabled) {
    echo json_encode([
        'enabled' => false,
        'airport' => $airportId,
        'cam' => $camIndex,
        'frames' => [],
        'message' => 'Webcam history not enabled for this airport'
    ]);
    exit;
}

// Get available frames
$frames = getHistoryFrames($airportId, $camIndex);
$baseUrl = '/api/webcam-history.php?id=' . urlencode($airportId) . '&cam=' . $camIndex;

$frameList = [];
foreach ($frames as $frame) {
    $frameList[] = [
        'timestamp' => $frame['timestamp'],
        'url' => $baseUrl . '&ts=' . $frame['timestamp']
    ];
}

echo json_encode([
    'enabled' => true,
    'airport' => $airportId,
    'cam' => $camIndex,
    'frames' => $frameList,
    'current_index' => count($frameList) > 0 ? count($frameList) - 1 : 0,
    'timezone' => $airport['timezone'] ?? 'UTC',
    'max_frames' => getWebcamHistoryMaxFrames($airportId)
]);

