<?php
/**
 * Webcam History API
 * 
 * Returns manifest of available historical frames for a camera,
 * or serves individual frames when timestamp is specified.
 * 
 * Storage: History frames are stored directly in the camera cache directory
 * (unified storage with current images). Retention is controlled by
 * webcam_history_max_frames config.
 * 
 * History Behavior:
 * - max_frames < 2: History disabled, returns enabled=false
 * - max_frames >= 2 but frames < 2: History enabled but not yet available
 * - max_frames >= 2 and frames >= 2: History fully available
 * 
 * Endpoints:
 *   GET /api/webcam-history.php?id={airport}&cam={index}
 *     Returns JSON manifest of available frames with format availability
 * 
 *   GET /api/webcam-history.php?id={airport}&cam={index}&ts={timestamp}&fmt={format}
 *     Returns the image for that timestamp in requested format
 *     fmt: 'jpg' (default) or 'webp'
 *     Falls back to JPG if requested format not available
 * 
 * Response (manifest):
 * {
 *   "enabled": true,
 *   "available": true,
 *   "airport": "kspb",
 *   "cam": 0,
 *   "frames": [
 *     { 
 *       "timestamp": 1735084200, 
 *       "url": "/api/webcam-history.php?id=kspb&cam=0&ts=1735084200",
 *       "formats": ["jpg", "webp"]
 *     },
 *     ...
 *   ],
 *   "current_index": 11,
 *   "timezone": "America/Los_Angeles"
 * }
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/webcam-history.php';
require_once __DIR__ . '/../lib/cache-paths.php';
require_once __DIR__ . '/../lib/webcam-metadata.php';

// Supported image formats with MIME types
$supportedFormats = [
    'jpg' => 'image/jpeg',
    'webp' => 'image/webp'
];

// Get parameters
$airportId = isset($_GET['id']) ? strtolower(trim($_GET['id'])) : '';
$camIndex = isset($_GET['cam']) ? intval($_GET['cam']) : 0;
$timestamp = isset($_GET['ts']) ? intval($_GET['ts']) : null;
$requestedFormat = isset($_GET['fmt']) ? strtolower(trim($_GET['fmt'])) : 'jpg';
$requestedSize = isset($_GET['size']) ? trim($_GET['size']) : 'original';

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
    // History images are stored in the camera cache directory (unified storage)
    $cacheDir = getWebcamCameraDir($airportId, $camIndex);
    
    // Validate requested format
    if (!isset($supportedFormats[$requestedFormat])) {
        $requestedFormat = 'jpg';
    }
    
    $size = $requestedSize;
    if ($size !== 'original' && is_numeric($size)) {
        $size = (int)$size;
        if ($size < 1 || $size > 5000) {
            $size = 'original';
        }
    } elseif ($size !== 'original') {
        $size = 'original';
    }
    
    require_once __DIR__ . '/../lib/webcam-metadata.php';
    $imageFile = getImagePathForSize($airportId, $camIndex, $timestamp, $size, $requestedFormat);
    $servedFormat = $requestedFormat;
    $servedSize = $size;
    
    if ($imageFile === null) {
        $enabledFormats = getEnabledWebcamFormats();
        foreach ($enabledFormats as $format) {
            if ($format === $requestedFormat) {
                continue;
            }
            $imageFile = getImagePathForSize($airportId, $camIndex, $timestamp, $size, $format);
            if ($imageFile !== null) {
                $servedFormat = $format;
                break;
            }
        }
    }
    
    if ($imageFile === null || !file_exists($imageFile)) {
        header('Content-Type: application/json');
        http_response_code(404);
        echo json_encode([
            'error' => 'Frame not found',
            'timestamp' => $timestamp,
            'requested_size' => $size,
            'requested_format' => $requestedFormat
        ]);
        exit;
    }
    
    // Security check: ensure file is within cache directory
    $realPath = realpath($imageFile);
    $realCacheDir = realpath($cacheDir);
    if ($realPath === false || $realCacheDir === false || strpos($realPath, $realCacheDir) !== 0) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        exit;
    }
    
    header('Content-Type: ' . $supportedFormats[$servedFormat]);
    header('Cache-Control: public, max-age=31536000, immutable');
    header('Content-Length: ' . filesize($imageFile));
    header('X-Content-Type-Options: nosniff');
    
    if ($servedFormat !== $requestedFormat) {
        header('X-Served-Format: ' . $servedFormat);
    }
    
    readfile($imageFile);
    exit;
}

// Return JSON manifest
header('Content-Type: application/json');
header('Cache-Control: no-cache, max-age=0');

// Get history status (enabled, available, frame count)
$historyStatus = getHistoryStatus($airportId, $camIndex);

// History disabled by config (max_frames < 2)
if (!$historyStatus['enabled']) {
    echo json_encode([
        'enabled' => false,
        'available' => false,
        'airport' => $airportId,
        'cam' => $camIndex,
        'frames' => [],
        'max_frames' => $historyStatus['max_frames'],
        'message' => 'Webcam history not configured for this airport'
    ]);
    exit;
}

// Get available frames (includes format availability per frame)
$frames = getHistoryFrames($airportId, $camIndex);
$baseUrl = '/api/webcam-history.php?id=' . urlencode($airportId) . '&cam=' . $camIndex;

$frameList = [];
foreach ($frames as $frame) {
    $frameList[] = [
        'timestamp' => $frame['timestamp'],
        'url' => $baseUrl . '&ts=' . $frame['timestamp'],
        'formats' => $frame['formats'] ?? ['jpg'],
        'variants' => $frame['variants'] ?? [] // Variant manifest: {variant: [formats]}
    ];
}

// Calculate refresh interval for this camera (per-cam, airport-level, or global default)
$defaultWebcamRefresh = getDefaultWebcamRefresh();
$airportWebcamRefresh = isset($airport['webcam_refresh_seconds']) 
    ? intval($airport['webcam_refresh_seconds']) 
    : $defaultWebcamRefresh;
$cam = $airport['webcams'][$camIndex];
$perCamRefresh = isset($cam['refresh_seconds']) 
    ? intval($cam['refresh_seconds']) 
    : $airportWebcamRefresh;
$refreshInterval = max(60, $perCamRefresh); // Enforce minimum 60 seconds

// Get variant heights from config (for reference)
require_once __DIR__ . '/../lib/webcam-metadata.php';
$variantHeights = getVariantHeights($airportId, $camIndex);

// Build response with status information
$response = [
    'enabled' => true,
    'available' => $historyStatus['available'],
    'airport' => $airportId,
    'cam' => $camIndex,
    'frames' => $frameList,
    'frame_count' => count($frameList),
    'current_index' => count($frameList) > 0 ? count($frameList) - 1 : 0,
    'timezone' => $airport['timezone'] ?? 'UTC',
    'max_frames' => $historyStatus['max_frames'],
    'enabledFormats' => getEnabledWebcamFormats(),
    'variantHeights' => $variantHeights,
    'refresh_interval' => $refreshInterval
];

// Add message if history enabled but not yet available (insufficient frames)
if (!$historyStatus['available']) {
    $response['message'] = 'History not available for this camera, come back later.';
}

echo json_encode($response);

